<?php

namespace AetherUpload\Adapter\Hyperf\Event;

/**
 * 内核的事件名是字符串（aetherupload.before_upload_complete / upload_complete），
 * 而 Hyperf 的派发器按**事件对象的类**匹配监听器，因此需要一个载体把名字与载荷带过去。
 *
 * 宿主注册监听器时监听本类，再按 getName() 过滤；见 README 的 Hyperf 一节。
 */
class AetherUploadEvent
{
    /** @var string */
    private $name;

    /** @var mixed 载荷：PartialResource 或 Resource 实例 */
    private $payload;

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
