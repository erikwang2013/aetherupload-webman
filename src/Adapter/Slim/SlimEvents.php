<?php

namespace AetherUpload\Adapter\Slim;

use AetherUpload\Kernel\Event as KernelEvent;
use AetherUpload\Kernel\Psr14Events;

/**
 * 事件端口（Slim 这一份）：本地监听器 + 可选的外部 PSR-14 派发器。
 *
 * 「监听器抛异常绝不冒泡到上传响应」这条契约的实现与理由都在
 * `AetherUpload\Kernel\Psr14Events`：Yii3 的 yiisoft/event-dispatcher 同样是 PSR-14，
 * 两边共用一份实现。
 *
 * 保留本类名的实际原因：派发出去的事件对象必须是 `Adapter\Slim\Event`（监听器的类型声明就是它），
 * 所以这里覆写事件工厂。
 *
 * @see Psr14Events
 */
class SlimEvents extends Psr14Events
{
    /**
     * @param mixed $payload
     */
    protected function createEvent(string $name, $payload): KernelEvent
    {
        return new Event($name, $payload);
    }
}
