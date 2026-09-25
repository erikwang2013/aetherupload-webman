<?php

namespace AetherUpload\Adapter\Hyperf\Command;

use AetherUpload\Console\ListGroupsRunner;
use Hyperf\Command\Command;

class AetherUploadListGroupsCommand extends Command
{
    /** @var string|null 与 Hyperf\Command\Command::$name 的类型保持一致 */
    protected ?string $name = 'aetherupload:groups';

    /** @var string */
    protected string $description = 'List and create the directories for the groups';

    /**
     * 只做输出通道转接：业务逻辑在 ListGroupsRunner，另外五个框架的命令壳复用同一份。
     */
    public function handle(): int
    {
        return (new ListGroupsRunner())->run(
            function (string $line): void {
                $this->line($line);
            }
        );
    }
}
