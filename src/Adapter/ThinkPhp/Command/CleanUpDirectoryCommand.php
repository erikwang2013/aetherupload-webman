<?php

namespace AetherUpload\Adapter\ThinkPhp\Command;

use AetherUpload\Console\CleanUpDirectoryRunner;
use think\console\Command;
use think\console\Input;
use think\console\input\Argument;
use think\console\Output;

/**
 * aetherupload:clean {days=2}
 *
 * 只做参数解析与输出通道转接，业务逻辑在 CleanUpDirectoryRunner。
 */
class CleanUpDirectoryCommand extends Command
{
    protected function configure()
    {
        $this->setName('aetherupload:clean')
            ->addArgument('days', Argument::OPTIONAL, 'The number of days from today', 2)
            ->setDescription('Remove partial files which are created a few days ago');
    }

    protected function execute(Input $input, Output $output): int
    {
        return (new CleanUpDirectoryRunner())->run(
            static function (string $line) use ($output): void {
                $output->writeln($line);
            },
            (int)$input->getArgument('days')
        );
    }
}
