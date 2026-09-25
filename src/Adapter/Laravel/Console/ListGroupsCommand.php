<?php

namespace AetherUpload\Adapter\Laravel\Console;

use AetherUpload\Console\ListGroupsRunner;
use Illuminate\Console\Command;

class ListGroupsCommand extends Command
{
    protected $signature = 'aetherupload:groups';

    protected $description = 'List and create the directories for the groups';

    /**
     * 只做输出通道转接：业务逻辑在 ListGroupsRunner。
     */
    public function handle(): int
    {
        return (new ListGroupsRunner())->run(function (string $line): void {
            $this->line($line);
        });
    }
}
