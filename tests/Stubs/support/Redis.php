<?php

namespace support;

use AetherUpload\Tests\Support\TestState;

/**
 * In-memory stand-in for webman's support\Redis (predis).
 * The hash commands back the legacy aetherupload_resource store; string commands back one key per record.
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

    /** @return int 1 when the field was deleted, 0 when it did not exist */
    public static function hdel(string $key, string $field): int
    {
        if ( ! isset(TestState::$redisHash[$key][$field]) ) {
            return 0;
        }
        unset(TestState::$redisHash[$key][$field]);
        return 1;
    }

    /** @return null when the key is missing (predis semantics) */
    public static function get(string $key): ?string
    {
        return TestState::$redisStrings[$key] ?? null;
    }

    /** @return int 1 when the key exists, 0 otherwise */
    public static function exists(string $key): int
    {
        return isset(TestState::$redisStrings[$key]) ? 1 : 0;
    }

    /** Records the TTL per key so tests can assert that each record expires on its own. */
    public static function setex(string $key, int $seconds, string $value): bool
    {
        TestState::$redisStrings[$key] = $value;
        TestState::$redisStringExpire[$key] = $seconds;
        return true;
    }

    /** @return int the number of keys removed */
    public static function del(string $key): int
    {
        $removed = isset(TestState::$redisStrings[$key]) || isset(TestState::$redisHash[$key]);
        unset(TestState::$redisStrings[$key], TestState::$redisStringExpire[$key], TestState::$redisHash[$key]);
        return $removed ? 1 : 0;
    }

    public static function expire(string $key, int $seconds): bool
    {
        TestState::$redisExpireCalls[$key] = $seconds;
        return true;
    }

    /**
     * @param string $pattern glob pattern, matched with fnmatch() (Redis KEYS style)
     * @return string[] matching keys
     */
    public static function keys(string $pattern): array
    {
        return array_values(array_filter(
            array_keys(TestState::$redisStrings),
            static fn($key) => fnmatch($pattern, $key)
        ));
    }
}
