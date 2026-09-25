<?php

namespace AetherUpload\Adapter\Symfony\Console;

use AetherUpload\Console\CleanUpDirectoryRunner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'aetherupload:clean',
    description: 'Remove partial files which are created a few days ago'
)]
class CleanUpDirectoryCommand extends Command
{
    protected function configure(): void
    {
        $this->addArgument('days', InputArgument::OPTIONAL, 'The number of days from today', '2');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $status = (new CleanUpDirectoryRunner())->run(
            static function (string $line) use ($output): void {
                $output->writeln($line);
            },
            (int)$input->getArgument('days')
        );

        return $status === 0 ? Command::SUCCESS : Command::FAILURE;
    }
}
