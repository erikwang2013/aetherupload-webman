<?php

namespace AetherUpload\Adapter\Yii3;

use AetherUpload\Kernel\Event as KernelEvent;

/**
 * 上传生命周期事件对象（Yii3 这一份）。
 *
 * **类名在 Yii3 里就是订阅键**：yiisoft/event-dispatcher 按事件类名派发、按类名配置监听器
 * （`'yiisoft/event-dispatcher' => ['listeners' => [Event::class => [MyListener::class]]]`），
 * 所以本包必须给出一个属于 Yii3 的名字。事件名（'aetherupload.upload_complete' 等）与载荷
 * （PartialResource / Resource）在对象上，监听器按 `$event->name` 区分即可。
 *
 * 实现与字段含义在 `AetherUpload\Kernel\Event`（Slim 侧同此）。
 *
 * @see KernelEvent
 */
class Event extends KernelEvent
{
}
