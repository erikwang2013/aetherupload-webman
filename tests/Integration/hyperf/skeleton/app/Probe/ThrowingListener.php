<?php

declare(strict_types=1);

namespace App\Probe;

use AetherUpload\Adapter\Hyperf\Event\AetherUploadEvent;
use Hyperf\Event\Contract\ListenerInterface;
use RuntimeException;

/**
 * 事件探针之一：监听上传完成事件并**抛异常**。
 *
 * 用途：Hyperf\Event\EventDispatcher 会把监听器异常冒泡给调用方，而内核契约要求
 * 「监听器抛出的异常绝不允许冒泡到上传响应」（见 EventDispatcherInterface 的注释）。
 * 这条监听器就是那个必须被适配器吞掉的异常 —— 它一旦漏出去，上传响应就会变成 500。
 *
 * 只对 aetherupload.upload_complete 抛，避免污染 before_upload_complete 之类的其它路径。
 */
class ThrowingListener implements ListenerInterface
{
    public function listen(): array
    {
        return [
            AetherUploadEvent::class,
        ];
    }

    public function process(object $event): void
    {
        if ( $event instanceof AetherUploadEvent && $event->getName() === 'aetherupload.upload_complete' ) {
            throw new RuntimeException('E2E probe: this listener exception must not bubble');
        }
    }
}
