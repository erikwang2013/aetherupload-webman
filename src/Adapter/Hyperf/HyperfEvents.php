<?php

namespace AetherUpload\Adapter\Hyperf;

use AetherUpload\Adapter\Hyperf\Event\AetherUploadEvent;
use AetherUpload\Contract\EventDispatcherInterface;
use Hyperf\Context\ApplicationContext;
use Hyperf\Contract\StdoutLoggerInterface;
use Psr\EventDispatcher\ListenerProviderInterface;
use Throwable;

class HyperfEvents implements EventDispatcherInterface
{
    /**
     * @param mixed $payload
     */
    public function emit(string $name, $payload): void
    {
        $event = new AetherUploadEvent($name, $payload);

        try {
            $provider = ApplicationContext::getContainer()->get(ListenerProviderInterface::class);
        } catch ( Throwable $e ) {
            return; // 容器还没有事件系统（安装脚本等极早阶段）：与 NullEventDispatcher 等价
        }

        // 刻意自己遍历而不是调 Hyperf\Event\EventDispatcher::dispatch()：
        // 那一个直接把异常交给调用方（与 webman 相反），一个抛异常的监听器会顺带掐掉排在它后面的监听器。
        // 契约要求的是 webman 的语义 —— 监听器异常绝不冒泡、且不影响其它监听器，所以逐个兜住。
        foreach ( $provider->getListenersForEvent($event) as $listener ) {
            try {
                $listener($event);
            } catch ( Throwable $e ) {
                $this->log($e);
            }
        }
    }

    private function log(Throwable $e): void
    {
        try {
            ApplicationContext::getContainer()
                ->get(StdoutLoggerInterface::class)
                ->error('AetherUpload event listener failed: ' . $e->getMessage());
        } catch ( Throwable $ignored ) {
            // 连日志器都取不到就彻底放弃：事件不是上传链路的关键路径，绝不能反过来影响上传
        }
    }
}
