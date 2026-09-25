<?php

namespace AetherUpload\Adapter\Hyperf\Command;

use AetherUpload\Console\BuildRedisHashesRunner;
use Hyperf\Command\Command;

class AetherUploadBuildRedisHashesCommand extends Command
{
    /** @var string|null 与 Hyperf\Command\Command::$name 的类型保持一致 */
    protected ?string $name = 'aetherupload:build';

    /** @var string */
    protected string $description = 'Rebuild the correlations between hashes and file storage paths in Redis';

    /**
     * 只做输出通道转接：业务逻辑在 BuildRedisHashesRunner，另外五个框架的命令壳复用同一份。
     */
    public function handle(): int
    {
        return (new BuildRedisHashesRunner())->run(
            function (string $line): void {
                $this->line($line);
            }
        );
    }
}
