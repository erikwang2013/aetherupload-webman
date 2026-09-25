<?php

namespace AetherUpload\Adapter\Laravel\Console;

use AetherUpload\Console\CleanUpDirectoryRunner;
use Illuminate\Console\Command;

class CleanUpDirectoryCommand extends Command
{
    protected $signature = 'aetherupload:clean {days=2 : The number of days from today}';

    protected $description = 'Remove partial files which are created a few days ago';

    /**
     * 只做参数解析与输出通道转接：业务逻辑在 CleanUpDirectoryRunner。
     */
    public function handle(): int
    {
        return (new CleanUpDirectoryRunner())->run(function (string $line): void {
            $this->line($line);
        }, (int)$this->argument('days'));
    }
}
