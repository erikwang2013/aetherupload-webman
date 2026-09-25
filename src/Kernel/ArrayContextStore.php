<?php

namespace AetherUpload\Kernel;

use AetherUpload\Contract\ContextStoreInterface;

/**
 * 进程级上下文存储 —— 适用于「每请求一个进程」的宿主（PHP-FPM 下的 Laravel/Symfony/
 * ThinkPHP/Yii/Slim），也适用于 CLI。Hyperf 必须换成协程局部的实现。
 */
class ArrayContextStore implements ContextStoreInterface
{
    /** @var array<string,mixed> */
    private $values = [];

    /**
     * @param mixed $default
     * @return mixed
     */
    public function get(string $id, $default = null)
    {
        return array_key_exists($id, $this->values) ? $this->values[$id] : $default;
    }

    /**
     * @param mixed $value
     */
    public function set(string $id, $value): void
    {
        $this->values[$id] = $value;
    }
}
