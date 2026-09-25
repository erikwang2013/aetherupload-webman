<?php

namespace AetherUpload\Tests\Integration\Laravel;

use RuntimeException;

/**
 * Laravel 端到端测试的路径与连接参数。
 *
 * 目录分工（全部在仓库外的临时工作目录里，不污染仓库、也不写进 vendor/）：
 *   <work>/composer.json   ci.sh 生成的宿主应用清单（path 仓库 symlink 指向本仓库）
 *   <work>/vendor/         宿主应用的依赖（laravel/framework、orchestra/testbench、predis）
 *   <work>/app/            testbench 自带最小 Laravel 骨架的副本 = 被测宿主应用根
 *                          上传落盘、vendor:publish 的产物都在它下面
 *
 * 可用环境变量：
 *   AETHERA_LARAVEL_WORK  工作目录（默认 sys_get_temp_dir()/aetherupload-laravel-e2e）
 *   AETHERA_E2E_REDIS     '0' 强制视为无 redis（第 8 条秒传跳过）
 *   AETHERA_E2E_REDIS_HOST / _PORT / _DB   redis 连接参数（默认 127.0.0.1:6379 db 4）
 *
 * db 4 是 Laravel 的专属库：六个框架的集成测试会并行跑，共用一个 db 时别人的 flush
 * 会落在自己的第 8 条（秒传）两次上传之间，产生随机的假红。
 */
final class Harness
{
    private function __construct()
    {
    }

    /** 本仓库根目录（path 仓库指向它，装进宿主应用的是当前工作区，不是发布版） */
    public static function repoRoot(): string
    {
        return dirname(__DIR__, 3);
    }

    public static function workRoot(): string
    {
        $dir = getenv('AETHERA_LARAVEL_WORK');

        return $dir ? rtrim($dir, '/') : sys_get_temp_dir() . '/aetherupload-laravel-e2e';
    }

    /** 被测 Laravel 应用根（FlowAssertions 的 appBasePath()） */
    public static function appRoot(): string
    {
        return self::workRoot() . '/app';
    }

    /** 事件探针日志（第 7 条） */
    public static function probeLog(): string
    {
        return self::appRoot() . '/storage/aetherupload-probe.log';
    }

    /** AETHERA_E2E_REDIS=0 时强制视为无 redis（第 8 条跳过），与 webman 的 E2E 同名同语义 */
    public static function redisDisabled(): bool
    {
        return getenv('AETHERA_E2E_REDIS') === '0';
    }

    public static function redisHost(): string
    {
        return getenv('AETHERA_E2E_REDIS_HOST') ?: '127.0.0.1';
    }

    public static function redisPort(): int
    {
        return (int)(getenv('AETHERA_E2E_REDIS_PORT') ?: 6379);
    }

    public static function redisDb(): int
    {
        return (int)(getenv('AETHERA_E2E_REDIS_DB') ?: 4);
    }

    /** 工作目录是否已由 ci.sh 装配好（bootstrap.php 用它给出人话错误） */
    public static function assertPrepared(): void
    {
        $missing = [];

        foreach ([self::workRoot() . '/vendor/autoload.php', self::appRoot() . '/bootstrap/app.php'] as $path) {
            if ( ! is_file($path) ) {
                $missing[] = $path;
            }
        }

        if ( $missing !== [] ) {
            throw new RuntimeException(
                "Laravel E2E 工作目录未装配：\n  " . implode("\n  ", $missing)
                . "\n请先运行：bash " . self::repoRoot() . '/tests/Integration/laravel/ci.sh'
            );
        }
    }
}
