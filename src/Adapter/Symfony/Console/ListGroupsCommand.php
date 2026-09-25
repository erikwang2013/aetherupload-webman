<?php

namespace AetherUpload\Adapter\Symfony\Console;

use AetherUpload\Console\ListGroupsRunner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'aetherupload:groups',
    description: 'List and create the directories for the groups'
)]
class ListGroupsCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $status = (new ListGroupsRunner())->run(
            static function (string $line) use ($output): void {
                $output->writeln($line);
            }
        );

        return $status === 0 ? Command::SUCCESS : Command::FAILURE;
    }
}
