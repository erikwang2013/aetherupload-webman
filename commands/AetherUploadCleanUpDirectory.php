<?php

namespace app\command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use AetherUpload\Console\CleanUpDirectoryRunner;


class AetherUploadCleanUpDirectory extends Command
{

    protected static $defaultName = 'aetherupload:clean {days=2}';
    protected static $defaultDescription = 'Remove partial files which are created a few days ago';

    /**
     * @return void
     */
    protected function configure()
    {
        $this->addArgument('days', InputArgument::OPTIONAL, 'The number of days from today', 2);
    }

    /**
     * 只做参数解析与输出通道转接：业务逻辑在 CleanUpDirectoryRunner，
     * 另一份在另外五个框架的命令壳里复用。
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
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
