<?php
declare(strict_types=1);

namespace BigStocks\Commands\MarketData;

use BigStocks\MarketData\ProviderFactory;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'market-data:profile',
    description: 'Fetch and output company profiles for symbols',
)]
final class ProfileCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addArgument(
                'symbols',
                InputArgument::IS_ARRAY | InputArgument::REQUIRED,
                'One or more ticker symbols, e.g. AAPL MSFT',
            )
            ->addOption('provider', null, InputOption::VALUE_REQUIRED, 'Provider name', 'massive');
    }

    protected function execute(
        InputInterface $input,
        OutputInterface $output,
    ): int {
        try {
            $provider = ProviderFactory::create((string) $input->getOption('provider'));
            $data = $provider->tickerDetails($input->getArgument('symbols'));

            $output->writeln(json_encode(
                $data,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            ));

            return Command::SUCCESS;
        } catch (\Throwable $exception) {
            $output->writeln('<error>' . $exception->getMessage() . '</error>');

            return Command::FAILURE;
        }
    }
}
