<?php

declare(strict_types=1);

namespace App\Probe;

use AetherUpload\Adapter\Hyperf\Event\AetherUploadEvent;
use Hyperf\Event\Contract\ListenerInterface;

/**
 * 事件探针之二：把收到的事件名追加写入 runtime/aetherupload-probe.log。
 *
 * 用途：与 ThrowingListener 配对 —— 前者抛异常，本条必须**仍然被调用**。
 * 才能证明适配器不是「用 try/catch 把整轮派发包起来」蒙混过关
 * （那样后面排队的监听器会被一起掐掉，日志就永远不出现）。
 */
class RecordingListener implements ListenerInterface
{
    public function listen(): array
    {
        return [
            AetherUploadEvent::class,
        ];
    }

    public function process(object $event): void
    {
        if ( ! $event instanceof AetherUploadEvent ) {
            return;
        }

        @file_put_contents(
            BASE_PATH . '/runtime/aetherupload-probe.log',
            $event->getName() . "\n",
            FILE_APPEND
        );
    }
}
