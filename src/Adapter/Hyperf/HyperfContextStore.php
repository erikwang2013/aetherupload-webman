<?php

namespace AetherUpload\Adapter\Hyperf;

use AetherUpload\Contract\ContextStoreInterface;
use Hyperf\Context\Context;

/**
 * 协程局部的执行上下文存储。
 *
 * Hyperf\Context\Context 在协程内写入当前协程的 ArrayObject，在协程外（CLI、启动阶段）
 * 写入进程级静态数组 —— 两种语义都正是内核想要的，因此这里不做任何翻译，直接转发。
 */
class HyperfContextStore implements ContextStoreInterface
{
    /**
     * @param mixed $default
     * @return mixed
     */
    public function get(string $id, $default = null)
    {
        return Context::get($id, $default);
    }

    /**
     * @param mixed $value
     */
    public function set(string $id, $value): void
    {
        Context::set($id, $value);
    }
}
