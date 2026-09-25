<?php

namespace AetherUpload\Kernel;

use AetherUpload\Contract\RedisInterface;

/**
 * 「宿主没给 Redis，只有客户端实例」时的 Redis 端口：把 8 个命令原样转给客户端
 * （phpredis 的 \Redis 或 Predis\Client 都行）。
 *
 * 两者的小写方法名一致（phpredis 是原生方法，predis 走 __call 拼命令），因此一个转发就够。
 * **所有方法原样返回底层结果，不做归一化**：predis 与 phpredis 的返回类型本就不同
 * （hget 缺失是 null/false、hexists 命中是 int/bool），内核既有的分支同时兼容两者，
 * 适配器若擅自转换反而会破坏那套判断。
 *
 * 用在 Slim 与原生 PHP 上：这两个宿主都没有「从容器里取 redis 服务」的约定，
 * 使用者直接把客户端交进来。有宿主容器的适配器（Laravel/Hyperf/Yii/ThinkPHP/Symfony）
 * 各写各的，因为它们的差别正是「怎么从宿主拿到客户端」。
 */
class ClientRedis implements RedisInterface
{
    /** @var object */
    private $client;

    public function __construct(object $client)
    {
        $this->client = $client;
    }

    public function exists(string $key)
    {
        return $this->client->exists($key);
    }

    public function hexists(string $key, string $field)
    {
        return $this->client->hexists($key, $field);
    }

    public function get(string $key)
    {
        return $this->client->get($key);
    }

    public function hget(string $key, string $field)
    {
        return $this->client->hget($key, $field);
    }

    public function setex(string $key, int $seconds, string $value)
    {
        return $this->client->setex($key, $seconds, $value);
    }

    public function del(string $key)
    {
        return $this->client->del($key);
    }

    public function hdel(string $key, string $field)
    {
        return $this->client->hdel($key, $field);
    }

    public function keys(string $pattern): array
    {
        // phpredis 无匹配时返回 false，契约要求数组
        $result = $this->client->keys($pattern);

        return is_array($result) ? $result : [];
    }
}
