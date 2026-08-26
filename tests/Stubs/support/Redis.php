<?php

namespace support;

use AetherUpload\Tests\Support\TestState;

/**
 * In-memory stand-in for webman's support\Redis (predis), mirroring hset/hget/hmset/hdel return codes.
 */
class Redis
{
    public static function hexists(string $key, string $field): int
    {
        return isset(TestState::$redisHash[$key][$field]) ? 1 : 0;
    }

    public static function hget(string $key, string $field): ?string
    {
        return TestState::$redisHash[$key][$field] ?? null;
    }

    /** @return int 1 when a new field was set, 0 when it overwrote an existing one */
    public static function hset(string $key, string $field, string $value): int
    {
        $existed = isset(TestState::$redisHash[$key][$field]);
        TestState::$redisHash[$key][$field] = $value;
        return $existed ? 0 : 1;
    }

    public static function hmset(string $key, array $data): bool
    {
        TestState::$redisHash[$key] = array_merge(TestState::$redisHash[$key] ?? [], $data);
        return true;
    }

    /** @return int 1 when the field was deleted, 0 when it did not exist */
    public static function hdel(string $key, string $field): int
    {
        if ( ! isset(TestState::$redisHash[$key][$field]) ) {
            return 0;
        }
        unset(TestState::$redisHash[$key][$field]);
        return 1;
    }

    public static function del(string $key): int
    {
        unset(TestState::$redisHash[$key]);
        return 1;
    }

    public static function expire(string $key, int $seconds): bool
    {
        TestState::$redisExpireCalls[$key] = $seconds;
        return true;
    }
}
