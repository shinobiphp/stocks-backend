<?php

declare(strict_types=1);

namespace BigStocks\Commands\MarketData;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'market-data:providers',
    description: 'List configured market-data providers',
)]
final class ProvidersCommand extends Command
{
    protected function execute(
        InputInterface $input,
        OutputInterface $output,
    ): int {
        $output->writeln('massive');

        return Command::SUCCESS;
    }
}
