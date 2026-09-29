<?php

namespace AetherUpload\Kernel;

/**
 * 上传生命周期事件对象（PSR-14 的 dispatch() 只接受 object，所以要有个载体）。
 *
 * 事件名与载荷都在这里：`$event->name` 形如 'aetherupload.upload_complete'，
 * `$event->payload` 是 PartialResource 或 Resource 实例。监听器签名即 `function (Event $e)`。
 *
 * 各宿主的事件类是本类的子类（`Adapter\Slim\Event` / `Adapter\Yii3\Event`）：事件的**类名**
 * 就是订阅键（Yii3 的 yiisoft/event-dispatcher 按类名派发、按类名配置监听器），
 * 所以每个宿主必须有自己那个名字，而实现只此一份。
 */
class Event
{
    /** @var string */
    public $name;

    /** @var mixed */
    public $payload;

    /**
     * @param mixed $payload
     */
    public function __construct(string $name, $payload)
    {
        $this->name = $name;
        $this->payload = $payload;
    }

    public function getName(): string
    {
        return $this->name;
    }

    /**
     * @return mixed
     */
    public function getPayload()
    {
        return $this->payload;
    }
}
