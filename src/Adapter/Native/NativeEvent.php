<?php

namespace AetherUpload\Adapter\Native;

/**
 * 上传生命周期事件对象。
 *
 * 事件名与载荷都在这里：`$event->name` 形如 'aetherupload.upload_complete'，
 * `$event->payload` 是 PartialResource 或 Resource 实例。监听器签名即 `function (NativeEvent $e)`。
 *
 * 与 webman 侧的区别只有一个：webman 的监听器收的是裸载荷（`function ($resource)`），
 * 这里连事件名一起给 —— 原生 PHP 的监听器常常是几个事件共用一个可调用对象，拿不到名字就没法分辨。
 */
class NativeEvent
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
