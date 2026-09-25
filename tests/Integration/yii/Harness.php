<?php

namespace AetherUpload\Tests\Integration\Yii;

use RuntimeException;
use Throwable;
use yii\redis\Connection;

/**
 * Yii 端到端测试的工作目录与环境参数：ci.sh 是入口，本类是它与测试用例共用的常量表。
 *
 * 刻意不依赖「应用已就绪」：redis 探测用的 Connection 自己建、自己关。
 */
final class Harness
{
    /** @var bool|null 进程内缓存：redis 可用性只探一次 */
    private static $redisAvailable;

    /** 没跑过 ci.sh 时给出人话，而不是让 autoload 抛 file not found */
    public static function assertPrepared(): void
    {
        if ( ! is_file(self::workRoot() . '/vendor/autoload.php') ) {
            throw new RuntimeException(
                '工作目录尚未装配：请先跑 bash tests/Integration/yii/ci.sh'
                . '（期望的宿主自动加载器：' . self::workRoot() . '/vendor/autoload.php）'
            );
        }
    }

    public static function repoRoot(): string
    {
        return dirname(__DIR__, 3);
    }

    public static function workRoot(): string
    {
        return self::env('AETHERA_YII_WORK', sys_get_temp_dir() . '/aetherupload-yii-e2e');
    }

    /** 被测的宿主应用根：上传落盘、发布产物都在它下面 */
    public static function appRoot(): string
    {
        return self::workRoot() . '/app';
    }

    /** 事件探针的日志文件（第 7 条按它的内容断言） */
    public static function probeLog(): string
    {
        return self::workRoot() . '/probe.log';
    }

    public static function redisDisabled(): bool
    {
        return self::env('AETHERA_E2E_REDIS', '1') === '0';
    }

    /**
     * yii2-redis 的连接参数（它的 Connection 用裸 socket，不需要 phpredis/predis 扩展）。
     *
     * 超时必须设：本机没起 redis 时没有超时就是长时间挂住，而第 8 条本来就允许跳过。
     * db 选 9：六个框架的端到端会并行跑，各自 flush 'aetherupload*' 会互相破坏
     * （典型症状是别人的 flush 正好落在本框架第 8 条两次上传之间，秒传命中不了，随机假红）。
     * 分配：webman 7 · laravel 4 · thinkphp 5 · hyperf 3 · slim 6 · symfony 8 · yii 9。
     */
    public static function redisConfig(): array
    {
        return [
            'hostname'          => self::env('AETHERA_E2E_REDIS_HOST', '127.0.0.1'),
            'port'              => (int)self::env('AETHERA_E2E_REDIS_PORT', '6379'),
            'database'          => (int)self::env('AETHERA_E2E_REDIS_DB', '9'),
            'connectionTimeout' => 1.0,
            'dataTimeout'       => 2.0,
        ];
    }

    /**
     * 秒传是否真的可用。
     *
     * 判据不是「端口通」，而是内核依赖的那套返回值语义确实成立：setex 之后能读回同一个值。
     */
    public static function redisAvailable(): bool
    {
        if ( self::$redisAvailable === null ) {
            self::$redisAvailable = self::probeRedis();
        }

        return self::$redisAvailable;
    }

    public static function redisSkipReason(): string
    {
        if ( self::redisDisabled() ) {
            return 'AETHERA_E2E_REDIS=0 强制关掉了 redis；第 8 条秒传未验证，其余 7 条不受影响';
        }

        return '本机 ' . self::env('AETHERA_E2E_REDIS_HOST', '127.0.0.1') . ':' . self::env('AETHERA_E2E_REDIS_PORT', '6379')
            . ' 上没有可用的 redis（装了 redis 但返回值不对也会走到这里）；第 8 条秒传未验证，其余 7 条不受影响';
    }

    /** 清空秒传索引：命中秒传会让 preprocess 直接回 savedPath，跳过 .part 建立与事件派发 */
    public static function flushRedis(): void
    {
        try {
            $redis = new Connection(self::redisConfig());

            foreach ( (array)$redis->keys('aetherupload*') as $key ) {
                $redis->del($key);
            }

            $redis->close();
        } catch ( Throwable $e ) {
            // redis 不可用时第 8 条会跳过，这里无需处理
        }
    }

    private static function probeRedis(): bool
    {
        if ( self::redisDisabled() ) {
            return false;
        }

        try {
            $redis = new Connection(self::redisConfig());
            $redis->setex('aetherupload-e2e-probe', 10, 'ok');
            $ok = $redis->get('aetherupload-e2e-probe') === 'ok';
            $redis->del('aetherupload-e2e-probe');
            $redis->close();

            return $ok;
        } catch ( Throwable $e ) {
            return false;
        }
    }

    private static function env(string $name, string $default): string
    {
        $value = getenv($name);

        return is_string($value) && $value !== '' ? $value : $default;
    }
}
