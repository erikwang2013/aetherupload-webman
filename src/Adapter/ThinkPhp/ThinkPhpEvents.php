<?php

namespace AetherUpload\Adapter\ThinkPhp;

use AetherUpload\Contract\EventDispatcherInterface;
use Throwable;

class ThinkPhpEvents implements EventDispatcherInterface
{
    /**
     * @param mixed $payload
     */
    public function emit(string $name, $payload): void
    {
        try {
            // think\Event::trigger 会把监听器的异常原样抛上来（webman/event 则是吞掉记日志），
            // 内核契约要求「监听器异常绝不冒泡到上传响应」，于是在这里收口。
            \think\facade\Event::trigger($name, $payload);
        } catch ( Throwable $e ) {
            $this->log($e);
        }
    }

    /** 记日志这一步本身也不能把异常漏出去（监听器常在 app 尚未初始化完时被触发） */
    private function log(Throwable $e): void
    {
        try {
            \think\facade\Log::error('aetherupload event listener failed: ' . $e->getMessage());
        } catch ( Throwable $ignored ) {
            // 没有可用的 logger 就算了：事件派发是旁路，不能反过来影响上传
        }
    }
}
