<?php

namespace AetherUpload\Adapter\Yii3\Command;

use AetherUpload\Console\BuildRedisHashesRunner;
use AetherUpload\Console\RunnerCommand;

/**
 * aetherupload/build：按磁盘现状重建 redis 里 hash → 存储路径的对应关系。
 *
 * 需要适配器绑定时就带上 redis 客户端（`Bootstrap::bind(..., ['redis' => ...])`）——
 * Runner 经 Runtime::redis() 取它，没给就是 NullRedis，命令会如实报错而不是假装成功。
 * 业务逻辑在 `AetherUpload\Console\BuildRedisHashesRunner`。
 */
final class BuildRedisHashesCommand extends RunnerCommand
{
    public function __construct()
    {
        parent::__construct(
            'aetherupload/build',
            'Rebuild the correlations between hashes and file storage paths in Redis',
            static function (array $arguments, callable $write): int {
                return (new BuildRedisHashesRunner())->run($write);
            }
        );
    }
}
