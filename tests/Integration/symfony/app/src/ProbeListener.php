<?php

namespace App;

use AetherUpload\Adapter\Symfony\AetherUploadEvent;
use RuntimeException;

/**
 * 事件探针（端到端断言第 7 条）：一个必抛异常的监听器 + 一个记录型监听器。
 *
 * 两者都监听 aetherupload.upload_complete，抛异常的那个优先级更高（注册见 config/services.yaml）。
 * 期望：抛出的异常不冒泡（上传仍 200/error=0），且记录型监听器照样被调用（日志里有那一行）。
 */
class ProbeListener
{
    public function throwOnUploadComplete(AetherUploadEvent $event): void
    {
        throw new RuntimeException('E2E probe: this listener exception must not bubble');
    }

    public function logUploadComplete(AetherUploadEvent $event): void
    {
        @file_put_contents(
            \dirname(__DIR__) . '/var/aetherupload-probe.log',
            'aetherupload.upload_complete ' . ($event->payload->name ?? '?') . "\n",
            FILE_APPEND
        );
    }
}
