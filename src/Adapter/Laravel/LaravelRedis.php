<?php

namespace AetherUpload\Adapter\Laravel;

use AetherUpload\Contract\RedisInterface;
use Illuminate\Support\Facades\Redis;

/**
 * Laravel 的 Redis 门面按 redis.client 配置返回 PhpRedisConnection 或 Predis 连接。
 *
 * 所有方法**原样返回**底层结果，不做归一化：两者的返回类型本就不同
 * （hget 缺失分别是 null/false、hexists 命中是 int/bool），内核既有分支同时兼容两者。
 */
class LaravelRedis implements RedisInterface
{
    public function exists(string $key)
    {
        return Redis::exists($key);
    }

    public function hexists(string $key, string $field)
    {
        return Redis::hexists($key, $field);
    }

    public function get(string $key)
    {
        return Redis::get($key);
    }

    public function hget(string $key, string $field)
    {
        return Redis::hget($key, $field);
    }

    public function setex(string $key, int $seconds, string $value)
    {
        return Redis::setex($key, $seconds, $value);
    }

    public function del(string $key)
    {
        return Redis::del($key);
    }

    public function hdel(string $key, string $field)
    {
        return Redis::hdel($key, $field);
    }

    public function keys(string $pattern): array
    {
        $result = Redis::keys($pattern);

        return is_array($result) ? $result : [];
    }
}
