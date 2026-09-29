<?php

namespace AetherUpload\Adapter\Yii3\Command;

use AetherUpload\Console\CleanUpDirectoryRunner;
use AetherUpload\Console\RunnerCommand;

/**
 * aetherupload/clean [days]：清理 days 天前遗留的分块残留（天数默认 2，可选参数）。
 *
 * 业务逻辑在 `AetherUpload\Console\CleanUpDirectoryRunner`（各宿主共用同一份）。
 */
final class CleanUpDirectoryCommand extends RunnerCommand
{
    public function __construct()
    {
        parent::__construct(
            'aetherupload/clean',
            'Remove partial files which are created a few days ago',
            static function (array $arguments, callable $write): int {
                return (new CleanUpDirectoryRunner())->run($write, (int)$arguments['days']);
            },
            ['days' => 2]
        );
    }
}
