<?php

namespace AetherUpload\Kernel;

use AetherUpload\Contract\ConfigInterface;

/**
 * 把一个「宿主原生取配置」的可调用对象 + 前缀，适配成内核认识的逻辑键。
 *
 * webman 适配器传入前缀 `plugin.erikwang2013.aetherupload-webman.app.` 与全局 config()；
 * Laravel/Hyperf/ThinkPHP/Symfony 传入 `aetherupload.`；Yii/Slim 传入空前缀（自己拼好数组）。
 */
class PrefixedConfig implements ConfigInterface
{
    /** @var callable */
    private $fetch;

    /** @var string */
    private $prefix;

    public function __construct(callable $fetch, string $prefix = '')
    {
        $this->fetch = $fetch;
        $this->prefix = $prefix;
    }

    /**
     * @param mixed $default
     * @return mixed
     */
    public function get(string $key, $default = null)
    {
        return ($this->fetch)($this->prefix . $key, $default);
    }
}
