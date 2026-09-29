<?php

namespace AetherUpload\Adapter\Slim;

use AetherUpload\Kernel\Event as KernelEvent;

/**
 * 上传生命周期事件对象（Slim 这一份）。
 *
 * 实现与语义在 `AetherUpload\Kernel\Event`。保留本类名是因为**事件的类名就是监听器的类型声明**：
 * 既有用例与 `tests/Integration/slim/App.php` 的探针监听器写的是
 * `function (\AetherUpload\Adapter\Slim\Event $event)`，派发出去的对象必须是本类的实例
 * （见 `SlimEvents::createEvent()`）。
 *
 * @see KernelEvent
 */
class Event extends KernelEvent
{
}
