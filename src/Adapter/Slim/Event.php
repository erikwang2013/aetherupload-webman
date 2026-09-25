<?php

namespace AetherUpload\Adapter\Slim;

/**
 * 上传生命周期事件对象（PSR-14 的 dispatch() 只接受 object，所以要有个载体）。
 *
 * 事件名与载荷都在这里：`$event->name` 形如 'aetherupload.upload_complete'，
 * `$event->payload` 是 PartialResource 或 Resource 实例。监听器签名即 `function (Event $e)`。
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
