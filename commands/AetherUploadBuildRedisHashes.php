<?php

namespace app\command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Command\Command;
use AetherUpload\Console\BuildRedisHashesRunner;


class AetherUploadBuildRedisHashes extends Command
{

    protected static $defaultName = 'aetherupload:build';
    protected static $defaultDescription = 'Rebuild the correlations between hashes and file storage paths in Redis';

    /**
     * 只做输出通道转接：业务逻辑在 BuildRedisHashesRunner，另一份在另外五个框架的命令壳里复用。
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $status = (new BuildRedisHashesRunner())->run(
            static function (string $line) use ($output): void {
                $output->writeln($line);
            }
        );

        return $status === 0 ? Command::SUCCESS : Command::FAILURE;
    }


}
