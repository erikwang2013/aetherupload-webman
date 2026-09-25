<?php

namespace AetherUpload\Kernel;

use AetherUpload\Contract\EventDispatcherInterface;

/**
 * 没有事件系统时的默认实现：派发即丢弃。
 *
 * 这是安全的默认值 —— 注册了 0 个监听器与什么都不派发，对上传结果等价。
 */
class NullEventDispatcher implements EventDispatcherInterface
{
    /**
     * @param mixed $payload
     */
    public function emit(string $name, $payload): void
    {
        // 无事件系统，静默丢弃
    }
}
