<?php

namespace AetherUpload\Contract;

/**
 * Redis 端口 —— 只暴露秒传索引真正用到的 8 个命令。
 *
 * 硬性语义：**原样返回底层客户端的结果**，不要归一化。
 * predis 与 phpredis 的返回类型本就不同（hget 缺失分别是 null/false、hexists 命中是 int/bool），
 * 内核既有的分支已经同时兼容两者，适配器若擅自转换反而会破坏那套判断。
 */
interface RedisInterface
{
    /** @return mixed */
    public function exists(string $key);

    /** @return mixed */
    public function hexists(string $key, string $field);

    /** @return mixed */
    public function get(string $key);

    /** @return mixed */
    public function hget(string $key, string $field);

    /** @return mixed */
    public function setex(string $key, int $seconds, string $value);

    /** @return mixed */
    public function del(string $key);

    /** @return mixed */
    public function hdel(string $key, string $field);

    /** @return array 匹配到的 key 列表 */
    public function keys(string $pattern): array;
}
