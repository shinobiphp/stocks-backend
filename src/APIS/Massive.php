<?php

declare(strict_types=1);

namespace BigStocks\APIS;

use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class Massive
{
    private const BASE_URL = 'https://api.massive.com';
    private const MAX_SNAPSHOT_BATCH = 100;
    private const MAX_CONCURRENT_REQUESTS = 20;

    private HttpClientInterface $http;

    public function __construct(
        private readonly string $apiKey,
        ?HttpClientInterface $http = null,
    ) {
        if ($apiKey === '') {
            throw new \InvalidArgumentException('Massive API key cannot be empty.');
        }

        $this->http = $http ?? HttpClient::create([
            'timeout' => 30,
        ]);
    }

    /**
     * Fetch current snapshots for one or more symbols.
     *
     * Massive accepts a comma-separated ticker list. Large lists are split
     * into batches. Returns the provider's ticker snapshot objects.
     *
     * @param list<string> $symbols
     * @return list<array<string, mixed>>
     */
    public function snapshots(array $symbols, bool $includeOtc = false): array
    {
        $symbols = $this->normalizeSymbols($symbols);

        if ($symbols === []) {
            return [];
        }

        $tickers = [];

        foreach (array_chunk($symbols, self::MAX_SNAPSHOT_BATCH) as $batch) {
            $response = $this->get('/v2/snapshot/locale/us/markets/stocks/tickers', [
                'tickers' => implode(',', $batch),
                'include_otc' => $includeOtc,
            ]);

            array_push($tickers, ...($response['tickers'] ?? []));
        }

        return $tickers;
    }

    /** Fetch detailed company/reference data for multiple symbols concurrently. */
    public function tickerDetails(array $symbols): array
    {
        $symbols = $this->normalizeSymbols($symbols);

        return $this->fetchPerSymbol(
            $symbols,
            static fn (string $symbol): string =>
                '/v3/reference/tickers/' . rawurlencode($symbol),
        );
    }

    /** Fetch daily financial ratios, including P/E, per symbol. */
    public function financialRatios(array $symbols, array $query = []): array
    {
        $symbols = $this->normalizeSymbols($symbols);

        return $this->fetchPerSymbol(
            $symbols,
            static fn (string $symbol): string => '/stocks/financials/v1/ratios',
            static fn (string $symbol): array => ['ticker' => $symbol] + $query,
        );
    }

    /** Fetch trade history for one symbol. The response may include next_url. */
    public function trades(string $symbol, array $query = []): array
    {
        return $this->get(
            '/v3/trades/' . rawurlencode($this->normalizeSymbol($symbol)),
            $query,
        );
    }

    /** Fetch NBBO quote history for one symbol. The response may include next_url. */
    public function quotes(string $symbol, array $query = []): array
    {
        return $this->get(
            '/v3/quotes/' . rawurlencode($this->normalizeSymbol($symbol)),
            $query,
        );
    }

    /** Fetch aggregate bars for one symbol and date/time range. */
    public function aggregates(
        string $symbol,
        int $multiplier,
        string $timespan,
        string $from,
        string $to,
        array $query = [],
    ): array {
        if ($multiplier < 1) {
            throw new \InvalidArgumentException('Aggregate multiplier must be positive.');
        }

        $path = sprintf(
            '/v2/aggs/ticker/%s/range/%d/%s/%s/%s',
            rawurlencode($this->normalizeSymbol($symbol)),
            $multiplier,
            rawurlencode($timespan),
            rawurlencode($from),
            rawurlencode($to),
        );

        return $this->get($path, $query);
    }

    /** Fetch one technical indicator series for one symbol. */
    public function indicator(
        string $name,
        string $symbol,
        array $query = [],
    ): array {
        $name = strtolower($name);

        if (!in_array($name, ['sma', 'ema', 'macd', 'rsi'], true)) {
            throw new \InvalidArgumentException("Unsupported indicator: {$name}");
        }

        return $this->get(
            '/v1/indicators/' . $name . '/' . rawurlencode($this->normalizeSymbol($symbol)),
            $query,
        );
    }

    /** Fetch all pages of the ticker reference catalog. */
    public function allTickers(array $query = []): array
    {
        $url = self::BASE_URL . '/v3/reference/tickers';
        $params = $query;
        $results = [];

        do {
            $response = $this->requestUrl($url, $params);
            array_push($results, ...($response['results'] ?? []));

            $url = $response['next_url'] ?? '';
            $params = []; // next_url already contains the pagination cursor
        } while ($url !== '');

        return $results;
    }

    private function get(string $path, array $query = []): array
    {
        return $this->requestUrl(self::BASE_URL . $path, $query);
    }

    private function requestUrl(string $url, array $query = []): array
    {
        $query['apiKey'] = $this->apiKey;

        $response = $this->http->request('GET', $url, [
            'query' => $query,
            'headers' => ['Accept' => 'application/json'],
        ]);

        return $response->toArray();
    }

    /**
     * Fetch per-symbol endpoints in bounded concurrent groups.
     *
     * @return array<string, array<string, mixed>>
     */
    private function fetchPerSymbol(
        array $symbols,
        callable $pathFor,
        ?callable $queryFor = null,
    ): array {
        $results = [];

        foreach (array_chunk($symbols, self::MAX_CONCURRENT_REQUESTS) as $batch) {
            $pending = [];

            foreach ($batch as $symbol) {
                $query = $queryFor ? $queryFor($symbol) : [];
                $query['apiKey'] = $this->apiKey;

                $pending[$symbol] = $this->http->request(
                    'GET',
                    self::BASE_URL . $pathFor($symbol),
                    [
                        'query' => $query,
                        'headers' => ['Accept' => 'application/json'],
                    ],
                );
            }

            foreach ($pending as $symbol => $response) {
                $results[$symbol] = $response->toArray();
            }
        }

        return $results;
    }

    /** @return list<string> */
    private function normalizeSymbols(array $symbols): array
    {
        $normalized = [];

        foreach ($symbols as $symbol) {
            $symbol = $this->normalizeSymbol((string) $symbol);
            $normalized[$symbol] = $symbol;
        }

        return array_values($normalized);
    }

    private function normalizeSymbol(string $symbol): string
    {
        $symbol = strtoupper(trim($symbol));

        if ($symbol === '') {
            throw new \InvalidArgumentException('Ticker symbol cannot be empty.');
        }

        return $symbol;
    }
}
