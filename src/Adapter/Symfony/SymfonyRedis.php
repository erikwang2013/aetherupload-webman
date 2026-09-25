<?php

namespace AetherUpload\Adapter\Symfony;

use AetherUpload\Contract\RedisInterface;

/**
 * Symfony 没有框架自带的 redis：客户端由应用提供（\Redis / \Predis\Client / 任意同名包装）。
 *
 * 所有方法**原样返回**底层客户端的结果，不做归一化：predis 与 phpredis 的返回类型本就不同
 * （hget 缺失 = null/false、hexists 命中 = int/bool），内核既有的分支同时兼容两者。
 * 唯一的例外是 keys()：契约要求返回数组，而 phpredis 在出错/空结果上可能给 false。
 */
class SymfonyRedis implements RedisInterface
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
        $result = $this->client->keys($pattern);

        return is_array($result) ? $result : [];
    }
}
