<?php

namespace AetherUpload\Adapter\Slim\Console;

use AetherUpload\Console\RunnerCommand as BaseRunnerCommand;

/**
 * Slim 侧的命令壳，实现在内核侧的 `AetherUpload\Console\RunnerCommand`（与 Slim 无关，
 * 只依赖 symfony/console 与 src/Console/*Runner）。保留本类名是为了不破坏已有引用。
 *
 * @see BaseRunnerCommand
 */
class RunnerCommand extends BaseRunnerCommand
{
}
