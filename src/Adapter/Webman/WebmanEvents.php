<?php

namespace AetherUpload\Adapter\Webman;

use AetherUpload\Contract\EventDispatcherInterface;

class WebmanEvents implements EventDispatcherInterface
{
    /**
     * @param mixed $payload
     */
    public function emit(string $name, $payload): void
    {
        // webman/event 内部会 catch 监听器的 \Throwable 并记日志，
        // 因此「监听器异常不影响上传响应」这条契约由它保证。
        \Webman\Event\Event::emit($name, $payload);
    }
}
