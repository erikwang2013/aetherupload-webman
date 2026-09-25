<?php

namespace AetherUpload\Kernel;

use AetherUpload\Contract\RedisInterface;
use RuntimeException;

/**
 * 未接 Redis 的默认实现：**一调用就抛异常**，而不是静默失败。
 *
 * 秒传索引只在 instant_completion = true 时才会被内核碰；
 * 如果使用者开了秒传却没给适配器接 Redis，报错远比「秒传静默失效」更容易排查。
 */
class NullRedis implements RedisInterface
{
    private function fail(): void
    {
        throw new RuntimeException(
            'AetherUpload: 未为此宿主配置 Redis。请安装 predis/predis（或宿主自带的 Redis 客户端）'
            . '并在适配器中注册，或关闭 instant_completion。'
        );
    }

    public function exists(string $key)
    {
        $this->fail();
    }

    public function hexists(string $key, string $field)
    {
        $this->fail();
    }

    public function get(string $key)
    {
        $this->fail();
    }

    public function hget(string $key, string $field)
    {
        $this->fail();
    }

    public function setex(string $key, int $seconds, string $value)
    {
        $this->fail();
    }

    public function del(string $key)
    {
        $this->fail();
    }

    public function hdel(string $key, string $field)
    {
        $this->fail();
    }

    public function keys(string $pattern): array
    {
        $this->fail();
    }
}
