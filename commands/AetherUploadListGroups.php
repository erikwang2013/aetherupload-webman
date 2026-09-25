<?php

namespace app\command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Command\Command;
use AetherUpload\Console\ListGroupsRunner;


class AetherUploadListGroups extends Command
{
    protected static $defaultName = 'aetherupload:groups';
    protected static $defaultDescription = 'List and create the directories for the groups';

    /**
     * 只做输出通道转接：业务逻辑在 ListGroupsRunner，另一份在另外五个框架的命令壳里复用。
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
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
