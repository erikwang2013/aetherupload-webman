<?php

namespace AetherUpload\Contract;

/**
 * 执行上下文存储端口。
 *
 * 用来把可变状态隔离到「单次请求」或「单个协程」：webman 常驻进程、Hyperf 的 Swoole 协程
 * 都会在同一个进程里交错处理多个请求，任何进程级可变状态都会互相覆盖。
 *
 * 实现约定：
 *  - webman / Laravel / Symfony / ThinkPHP / Yii / Slim：退化为静态数组（PHP-FPM 或每请求
 *    重建进程模型下天然隔离）
 *  - Hyperf：交给 Hyperf\Context\Context（协程局部）
 */
interface ContextStoreInterface
{
    /**
     * @param string $id
     * @param mixed  $default
     * @return mixed
     */
    public function get(string $id, $default = null);

    /**
     * @param string $id
     * @param mixed  $value
     */
    public function set(string $id, $value): void;
}
