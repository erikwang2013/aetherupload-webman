<?php

namespace AetherUpload\Adapter\ThinkPhp;

use AetherUpload\Contract\RedisInterface;
use think\facade\Cache;

/**
 * 必须走 Cache::store('redis')->handler() 拿**原始客户端**。
 *
 * think\cache\driver\Redis 自己在 getCacheKey() 里拼 cache.prefix，
 * 内核的秒传索引依赖精确的 key 前缀匹配（RedisSavedPath::getKey 拼出 file_<hash>），
 * 经 Cache 的包装会变成 `prefix + file_<hash>`，keys() 的模式匹配与 setex 都跟着偏。
 * handler() 返回的是 phpredis \Redis 或 \Predis\Client 本身，正好是内核既有分支能同时吃的两种形态。
 */
class ThinkPhpRedis implements RedisInterface
{
    public function exists(string $key)
    {
        return $this->handler()->exists($key);
    }

    public function hexists(string $key, string $field)
    {
        return $this->handler()->hexists($key, $field);
    }

    public function get(string $key)
    {
        return $this->handler()->get($key);
    }

    public function hget(string $key, string $field)
    {
        return $this->handler()->hget($key, $field);
    }

    public function setex(string $key, int $seconds, string $value)
    {
        return $this->handler()->setex($key, $seconds, $value);
    }

    public function del(string $key)
    {
        return $this->handler()->del($key);
    }

    public function hdel(string $key, string $field)
    {
        return $this->handler()->hdel($key, $field);
    }

    public function keys(string $pattern): array
    {
        $result = $this->handler()->keys($pattern);

        return is_array($result) ? $result : [];
    }

    /**
     * @return \Redis|\Predis\Client
     */
    private function handler()
    {
        return Cache::store('redis')->handler();
    }
}
