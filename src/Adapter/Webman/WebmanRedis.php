<?php

namespace AetherUpload\Adapter\Webman;

use AetherUpload\Contract\RedisInterface;
use support\Redis;

/**
 * webman 的 support\Redis 是对 predis/phpredis 的薄封装。
 *
 * 所有方法**原样返回**底层结果，不做归一化：predis 与 phpredis 的返回类型本就不同，
 * 内核既有的分支同时兼容两者（hget 缺失 = null/false、hexists 命中 = int/bool）。
 */
class WebmanRedis implements RedisInterface
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
