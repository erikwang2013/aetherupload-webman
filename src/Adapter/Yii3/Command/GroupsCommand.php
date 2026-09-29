<?php

namespace AetherUpload\Adapter\Yii3\Command;

use AetherUpload\Console\ListGroupsRunner;
use AetherUpload\Console\RunnerCommand;

/**
 * aetherupload/groups：列出配置里的分组，并建好上传根目录与各分组目录。
 *
 * 参数解析与输出通道转接都在 `AetherUpload\Console\RunnerCommand`（Symfony Console 基座，
 * yiisoft/yii-console 正是建在它之上），业务逻辑在 `AetherUpload\Console\ListGroupsRunner`
 * —— 各宿主的命令壳共用同一份。命令名带 `aetherupload/` 前缀，见 Bootstrap::consoleCommands()。
 */
final class GroupsCommand extends RunnerCommand
{
    public function __construct()
    {
        parent::__construct(
            'aetherupload/groups',
            'List and create the directories for the groups',
            static function (array $arguments, callable $write): int {
                return (new ListGroupsRunner())->run($write);
            }
        );
    }
}
