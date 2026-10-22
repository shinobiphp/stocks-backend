<?php

declare(strict_types=1);

namespace BigStocks\Commands;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'serve',
    description: 'Serve frontend clients',
)]
final class Serve extends Command
{
    protected function execute(
        InputInterface $input,
        OutputInterface $output,
    ): int {
        $output->writeln('Server started. Press Ctrl+C to stop.');

        while (true) {
            try {
                // TODO: Fetch and publish a batch of stock data.
                $output->writeln(sprintf(
                    '[%s] Serving...',
                    date(DATE_ATOM),
                ));
            } catch (\Throwable $exception) {
                $output->writeln(sprintf(
                    '<error>Server failed: %s</error>',
                    $exception->getMessage(),
                ));
            }

            sleep(10);
        }

        return Command::SUCCESS;
    }
}
