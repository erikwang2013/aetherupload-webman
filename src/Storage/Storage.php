<?php

namespace AetherUpload\Storage;

use AetherUpload\Runtime;

/**
 * 存储门面：按 storage.driver 造驱动实例。
 *
 * 刻意不缓存、不挂 ConfigMapper：构造成本只是数组解析；由此测试改配置无需 resetConfigMapper，
 * 常驻进程/协程下也没有单例串扰。inject() 仅供测试（仿 Runtime::bind/reset 先例）。
 */
final class Storage
{
    /** @var StorageInterface|null */
    private static $override = null;

    private function __construct()
    {
    }

    public static function inject(?StorageInterface $driver): void
    {
        self::$override = $driver;
    }

    public static function reset(): void
    {
        self::$override = null;
    }

    public static function driver(): StorageInterface
    {
        if ( self::$override !== null ) {
            return self::$override;
        }

        $driver = Runtime::config()->get('storage.driver', 'local');

        if ( $driver === 's3' ) {
            return new S3Storage((array)Runtime::config()->get('storage.s3', []));
        }

        if ( $driver === 'local' || $driver === null ) {
            return new LocalStorage();
        }

        throw new \Exception(
            "Unknown storage driver '" . (is_scalar($driver) ? (string)$driver : gettype($driver)) . "', expected 'local' or 's3'"
        );
    }
}
