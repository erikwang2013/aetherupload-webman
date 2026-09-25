<?php

namespace AetherUpload\Adapter\Laravel\Console;

use AetherUpload\Console\BuildRedisHashesRunner;
use Illuminate\Console\Command;

class BuildRedisHashesCommand extends Command
{
    /** 用 $signature 而非 $defaultName：后者已从 Symfony 7 的命令基类移除 */
    protected $signature = 'aetherupload:build';

    protected $description = 'Rebuild the correlations between hashes and file storage paths in Redis';

    /**
     * 只做输出通道转接：业务逻辑在 BuildRedisHashesRunner，六个框架的命令壳共用同一份。
     */
    public function handle(): int
    {
        return (new BuildRedisHashesRunner())->run(function (string $line): void {
            $this->line($line);
        });
    }
}
