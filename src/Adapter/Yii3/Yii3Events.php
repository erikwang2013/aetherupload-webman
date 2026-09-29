<?php

namespace AetherUpload\Adapter\Yii3;

use AetherUpload\Kernel\Event as KernelEvent;
use AetherUpload\Kernel\Psr14Events;

/**
 * 事件端口（Yii3 这一份）：本地监听器 + 可选的外部 PSR-14 派发器。
 *
 * Yii3 的 `yiisoft/event-dispatcher` 就是 PSR-14，直接当 `dispatcher` 选项传进来即可：
 *
 *     'dispatcher' => $container->get(\Psr\EventDispatcher\EventDispatcherInterface::class),
 *
 * 「监听器抛异常绝不冒泡到上传响应」这条契约的实现与理由在 `AetherUpload\Kernel\Psr14Events`。
 * 派发出去的对象是 `Adapter\Yii3\Event`（见该类的类注释：类名就是订阅键），所以这里覆写事件工厂。
 *
 * @see Psr14Events
 */
class Yii3Events extends Psr14Events
{
    /**
     * @param mixed $payload
     */
    protected function createEvent(string $name, $payload): KernelEvent
    {
        return new Event($name, $payload);
    }
}
