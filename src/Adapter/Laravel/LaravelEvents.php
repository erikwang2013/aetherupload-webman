<?php

namespace AetherUpload\Adapter\Laravel;

use AetherUpload\Contract\EventDispatcherInterface;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Throwable;

class LaravelEvents implements EventDispatcherInterface
{
    /**
     * @param mixed $payload
     */
    public function emit(string $name, $payload): void
    {
        try {
            // 第二个参数是监听器的入参数组，监听器签名即 function ($resource) {...}
            Event::dispatch($name, [$payload]);
        } catch ( Throwable $e ) {
            // Laravel 的派发器默认让监听器异常冒泡，会直接打断上传响应（用户文件已落盘却报 500）。
            // 这里对齐 webman 语义：吞掉并记日志。
            try {
                Log::error('aetherupload event listener failed: ' . $name, ['exception' => $e]);
            } catch ( Throwable $logFailure ) {
                // 记日志本身失败同样不能冒泡 —— 本方法的硬性契约是「绝不打断上传响应」
            }
        }
    }
}
