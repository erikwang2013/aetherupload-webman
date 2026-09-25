<?php

namespace AetherUpload\Adapter\Symfony;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * 上传生命周期事件对象（事件名仍是 aetherupload.before_upload_complete / upload_complete）。
 *
 * 监听器签名：`function (AetherUploadEvent $event) { $event->payload->name; }`
 */
class AetherUploadEvent extends Event
{
    /** @var mixed 载荷：PartialResource（before_upload_complete）或 Resource（upload_complete） */
    public $payload;

    /**
     * @param mixed $payload
     */
    public function __construct($payload)
    {
        $this->payload = $payload;
    }
}
