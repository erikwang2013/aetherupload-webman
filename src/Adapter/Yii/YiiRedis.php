<?php

namespace AetherUpload\Adapter\Yii;

use AetherUpload\Contract\RedisInterface;
use Yii;
use yii\redis\Connection;

/**
 * Yii 的 redis 组件（yii2-redis 的 Connection）。
 *
 * 所有方法**原样返回**底层结果，不做归一化：predis 与 phpredis 的返回类型本就不同，
 * 内核既有的分支同时兼容两者（hget 缺失 = null/false、hexists 命中 = int/bool）。
 * 组件未配置时 get() 抛的是 InvalidConfigException（继承自 \Exception），
 * 内核在 instant_completion 分支里已经 try/catch 住，等价于「没有 redis」。
 */
class YiiRedis implements RedisInterface
{
    public function exists(string $key)
    {
        return $this->connection()->exists($key);
    }

    public function hexists(string $key, string $field)
    {
        return $this->connection()->hexists($key, $field);
    }

    public function get(string $key)
    {
        return $this->connection()->get($key);
    }

    public function hget(string $key, string $field)
    {
        return $this->connection()->hget($key, $field);
    }

    public function setex(string $key, int $seconds, string $value)
    {
        return $this->connection()->setex($key, $seconds, $value);
    }

    public function del(string $key)
    {
        return $this->connection()->del($key);
    }

    public function hdel(string $key, string $field)
    {
        return $this->connection()->hdel($key, $field);
    }

    public function keys(string $pattern): array
    {
        $result = $this->connection()->keys($pattern);

        return is_array($result) ? $result : [];
    }

    /**
     * Connection 的 __call 把方法名当命令名发给 redis，上面八个都是真实命令。
     *
     * @return Connection
     */
    private function connection()
    {
        return Yii::$app->get('redis');
    }
}
