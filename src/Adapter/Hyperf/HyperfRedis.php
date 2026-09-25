<?php

namespace AetherUpload\Adapter\Hyperf;

use AetherUpload\Contract\RedisInterface;
use Hyperf\Context\ApplicationContext;
use RuntimeException;
use Throwable;

/**
 * Hyperf 的 Redis 客户端（phpredis 或 predis 的协程连接池代理）。
 *
 * 所有方法**原样返回**底层结果，不做归一化：phpredis 与 predis 的返回类型本就不同，
 * 而且 Hyperf\Redis\Redis 的返回还受 options 影响（bool/int/string），
 * 内核既有的分支同时兼容这些形态，适配器擅自转换反而会破坏那套判断。
 */
class HyperfRedis implements RedisInterface
{
    /** @var object|null */
    private $client;

    public function exists(string $key)
    {
        return $this->client()->exists($key);
    }

    public function hexists(string $key, string $field)
    {
        return $this->client()->hexists($key, $field);
    }

    public function get(string $key)
    {
        return $this->client()->get($key);
    }

    public function hget(string $key, string $field)
    {
        return $this->client()->hget($key, $field);
    }

    public function setex(string $key, int $seconds, string $value)
    {
        return $this->client()->setex($key, $seconds, $value);
    }

    public function del(string $key)
    {
        return $this->client()->del($key);
    }

    public function hdel(string $key, string $field)
    {
        return $this->client()->hdel($key, $field);
    }

    public function keys(string $pattern): array
    {
        $result = $this->client()->keys($pattern);

        return is_array($result) ? $result : [];
    }

    /**
     * 容器里的 \Redis 绑定由 hyperf/redis 提供（键名是 \Redis::class，值是协程安全的代理对象）。
     *
     * @return object
     */
    private function client()
    {
        if ( $this->client === null ) {
            try {
                $this->client = ApplicationContext::getContainer()->get(\Redis::class);
            } catch ( Throwable $e ) {
                throw new RuntimeException(
                    'AetherUpload: 未能从 Hyperf 容器取得 Redis 连接。'
                    . '请安装 hyperf/redis（或 predis/predis）并配置 config/autoload/redis.php，'
                    . '或关闭 instant_completion。',
                    0,
                    $e
                );
            }
        }

        return $this->client;
    }
}
