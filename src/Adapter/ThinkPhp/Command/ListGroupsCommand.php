<?php

namespace AetherUpload\Adapter\ThinkPhp\Command;

use AetherUpload\Console\ListGroupsRunner;
use think\console\Command;
use think\console\Input;
use think\console\Output;

/**
 * aetherupload:groups
 *
 * 只做输出通道转接，业务逻辑在 ListGroupsRunner。
 */
class ListGroupsCommand extends Command
{
    protected function configure()
    {
        $this->setName('aetherupload:groups')
            ->setDescription('List and create the directories for the groups');
    }

    protected function execute(Input $input, Output $output): int
    {
        return (new ListGroupsRunner())->run(
            static function (string $line) use ($output): void {
                $output->writeln($line);
            }
        );
    }
}
