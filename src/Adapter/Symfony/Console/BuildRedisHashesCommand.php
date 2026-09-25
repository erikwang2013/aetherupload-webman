<?php

namespace AetherUpload\Adapter\Symfony\Console;

use AetherUpload\Console\BuildRedisHashesRunner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * 只做输出通道转接：业务逻辑在 BuildRedisHashesRunner，另外五个框架的命令壳复用同一份逻辑。
 *
 * 命令名来自 #[AsCommand] 属性 —— 显式避开 `protected static $defaultName`（Symfony 7 已移除）。
 */
#[AsCommand(
    name: 'aetherupload:build',
    description: 'Rebuild the correlations between hashes and file storage paths in Redis'
)]
class BuildRedisHashesCommand extends Command
{
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
