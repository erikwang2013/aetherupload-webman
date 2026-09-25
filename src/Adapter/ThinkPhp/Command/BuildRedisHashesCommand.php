<?php

namespace AetherUpload\Adapter\ThinkPhp\Command;

use AetherUpload\Console\BuildRedisHashesRunner;
use think\console\Command;
use think\console\Input;
use think\console\Output;

/**
 * aetherupload:build。
 *
 * 只做输出通道转接，业务逻辑在 BuildRedisHashesRunner（另外五个框架的命令壳共用同一份）。
 * 注意基类是 think\console\Command 而不是 Symfony 的 Command —— 两者 API 相似但类不同。
 */
class BuildRedisHashesCommand extends Command
{
    protected function configure()
    {
        $this->setName('aetherupload:build')
            ->setDescription('Rebuild the correlations between hashes and file storage paths in Redis');
    }

    protected function execute(Input $input, Output $output): int
    {
        return (new BuildRedisHashesRunner())->run(
            static function (string $line) use ($output): void {
                $output->writeln($line);
            }
        );
    }
}
