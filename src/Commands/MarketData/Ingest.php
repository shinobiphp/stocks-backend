<?php

declare(strict_types=1);

namespace BigStocks\Commands\MarketData;

use BigStocks\APIS\Massive\Client as MassiveClient;
use Basis\Nats\Client as NatsClient;
use Basis\Nats\Configuration as NatsConfiguration;
use Basis\Nats\Message\Payload;
use PDO;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'market-data:ingest',
    description: 'Continuously fetch tracked Massive snapshots and publish them to JetStream',
)]
final class Ingest extends Command
{
    protected function configure(): void
    {
        $this->addOption(
            'once',
            null,
            InputOption::VALUE_NONE,
            'Run one ingest cycle and exit',
        );
    }

    protected function execute(
        InputInterface $input,
        OutputInterface $output,
    ): int {
        try {
            $database = new PDO(
                $this->requiredEnv('DATABASE_DSN'),
                $this->env('DATABASE_USER') ?? 'bigstocks',
                $this->env('DATABASE_PASSWORD') ?? '',
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                ],
            );

            $massive = new MassiveClient($this->requiredEnv('MASSIVE_API_KEY'));

            $nats = new NatsClient(new NatsConfiguration(
                host: $this->env('NATS_HOST') ?? '127.0.0.1',
                port: (int) ($this->env('NATS_PORT') ?? '4222'),
                user: $this->env('NATS_USER'),
                pass: $this->env('NATS_PASSWORD'),
            ));

            // The stream must exist already, and include NATS_SUBJECT.
            $stream = $nats->getApi()->getStream(
                $this->env('NATS_STREAM') ?? 'MASSIVE',
            );

            $subject = $this->env('NATS_SUBJECT') ?? 'massive.raw.snapshot';
            $interval = max(1, (int) ($this->env('INGEST_INTERVAL_SECONDS') ?? '15'));

            $output->writeln(sprintf(
                'Ingest started: provider=massive subject=%s interval=%ds',
                $subject,
                $interval,
            ));

            do {
                $startedAt = microtime(true);

                try {
                    $symbols = $this->loadTrackedSymbols($database);

                    if ($symbols === []) {
                        $output->writeln(sprintf(
                            '[%s] No active tracked symbols',
                            date(DATE_ATOM),
                        ));
                    } else {
                        // MassiveClient batches symbol lists into snapshot requests.
                        $snapshots = $massive->snapshots($symbols);
                        $published = 0;

                        foreach ($snapshots as $snapshot) {
                            $symbol = strtoupper((string) ($snapshot['ticker'] ?? ''));

                            if ($symbol === '') {
                                continue;
                            }

                            $payload = $this->makeRawEvent($symbol, $snapshot, $symbols);
                            $messageId = $payload['idempotency_hash'];

                            $stream->put(
                                $subject,
                                new Payload(
                                    json_encode(
                                        $payload,
                                        JSON_UNESCAPED_SLASHES
                                            | JSON_UNESCAPED_UNICODE
                                            | JSON_THROW_ON_ERROR,
                                    ),
                                    ['Nats-Msg-Id' => $messageId],
                                ),
                            );

                            ++$published;
                        }

                        $output->writeln(sprintf(
                            '[%s] symbols=%d snapshots=%d published=%d',
                            date(DATE_ATOM),
                            count($symbols),
                            count($snapshots),
                            $published,
                        ));
                    }
                } catch (\Throwable $exception) {
                    $output->writeln(sprintf(
                        '<error>Ingest cycle failed: %s</error>',
                        $exception->getMessage(),
                    ));
                }

                if ($input->getOption('once')) {
                    break;
                }

                $remaining = $interval - (microtime(true) - $startedAt);

                if ($remaining > 0) {
                    usleep((int) ($remaining * 1_000_000));
                }
            } while (true);

            return Command::SUCCESS;
        } catch (\Throwable $exception) {
            $output->writeln('<error>' . $exception->getMessage() . '</error>');

            return Command::FAILURE;
        }
    }

    /** @return list<string> */
    private function loadTrackedSymbols(PDO $database): array
    {
        $sql = <<<'SQL'
            SELECT DISTINCT upper(s.symbol) AS symbol
            FROM stocks s
            WHERE s.enabled = TRUE
              AND s.deleted_ts IS NULL
              AND (
                    EXISTS (
                        SELECT 1
                        FROM workspace_stocks ws
                        JOIN workspaces w ON w.id = ws.workspace_id
                        JOIN users u ON u.id = w.user_id
                        WHERE ws.stock_id = s.id
                          AND w.enabled = TRUE
                          AND w.deleted_ts IS NULL
                          AND u.enabled = TRUE
                          AND u.deleted_ts IS NULL
                    )
                    OR EXISTS (
                        SELECT 1
                        FROM watchlist_stocks wls
                        JOIN watchlists wl ON wl.id = wls.watchlist_id
                        JOIN users u ON u.id = wl.user_id
                        WHERE wls.stock_id = s.id
                          AND wl.enabled = TRUE
                          AND wl.deleted_ts IS NULL
                          AND u.enabled = TRUE
                          AND u.deleted_ts IS NULL
                    )
              )
            ORDER BY symbol
            SQL;

        return array_column($database->query($sql)->fetchAll(), 'symbol');
    }

    /**
     * Produces the row shape for ClickHouse bigstocks.massive_raw.
     *
     * @param list<string> $batchSymbols
     * @return array<string, mixed>
     */
    private function makeRawEvent(
        string $symbol,
        array $snapshot,
        array $batchSymbols,
    ): array {
        $canonicalPayload = $this->canonicalJson($snapshot);
        $observedAt = $this->snapshotTimestamp($snapshot);
        $requestKey = hash(
            'sha256',
            '/v2/snapshot/locale/us/markets/stocks/tickers?tickers='
                . implode(',', $batchSymbols),
        );
        $idempotencyHash = hash(
            'sha256',
            'massive:snapshot:' . $symbol . ':' . $canonicalPayload,
        );

        return [
            'endpoint' => '/v2/snapshot/locale/us/markets/stocks/tickers',
            'request_key' => $requestKey,
            'record_key' => $symbol,
            'idempotency_hash' => $idempotencyHash,
            'symbol' => $symbol,
            'provider_updated_at' => $observedAt,
            'observed_at' => $observedAt,
            'fetched_at' => gmdate('Y-m-d\TH:i:s.000\Z'),
            'schema_version' => 1,
            // ClickHouse payload column is String containing JSON.
            'payload' => $canonicalPayload,
        ];
    }

    private function snapshotTimestamp(array $snapshot): string
    {
        // Massive snapshot `updated` is a Unix timestamp in nanoseconds.
        $updated = (int) ($snapshot['updated'] ?? 0);

        if ($updated <= 0) {
            return gmdate('Y-m-d\TH:i:s.000\Z');
        }

        $seconds = intdiv($updated, 1_000_000_000);
        $milliseconds = intdiv($updated % 1_000_000_000, 1_000_000);

        return gmdate('Y-m-d\TH:i:s', $seconds)
            . sprintf('.%03dZ', $milliseconds);
    }

    private function canonicalJson(array $data): string
    {
        $sort = static function (&$value) use (&$sort): void {
            if (!is_array($value)) {
                return;
            }

            if (!array_is_list($value)) {
                ksort($value);
            }

            foreach ($value as &$item) {
                $sort($item);
            }
        };

        $sort($data);

        return json_encode(
            $data,
            JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE
                | JSON_PRESERVE_ZERO_FRACTION
                | JSON_THROW_ON_ERROR,
        );
    }

    private function env(string $key): ?string
    {
        foreach ([$key, 'ENV_' . $key] as $name) {
            $value = getenv($name);

            if ($value !== false && $value !== '') {
                return $value;
            }

            $value = $_ENV[$name] ?? null;

            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    private function requiredEnv(string $key): string
    {
        return $this->env($key)
            ?? throw new \RuntimeException("Required environment variable {$key} is not set");
    }
}
