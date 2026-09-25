<?php

namespace AetherUpload\Adapter\Webman;

use AetherUpload\Contract\ConfigInterface;
use AetherUpload\Contract\EventDispatcherInterface;
use AetherUpload\Contract\PathsInterface;
use AetherUpload\Contract\RedisInterface;
use AetherUpload\Contract\RequestInterface;
use AetherUpload\Contract\ResponseFactoryInterface;
use AetherUpload\Contract\TranslatorInterface;
use AetherUpload\Kernel\AbstractAdapter;
use AetherUpload\Kernel\PrefixedConfig;
use Throwable;

/**
 * webman 适配器：把内核端口代理到 webman 既有的全局函数与 support\* 静态类。
 *
 * 一行的绑定位置：config/route.php 首行（该文件被 Install 复制到
 * config/plugin/erikwang2013/aetherupload-webman/route.php，由 webman 自动加载）。
 */
class WebmanAdapter extends AbstractAdapter
{
    /** 配置前缀：与 config/plugin/erikwang2013/aetherupload-webman/app.php 对应 */
    const CONFIG_PREFIX = 'plugin.erikwang2013.aetherupload-webman.app.';

    /** @var ConfigInterface|null */
    private $config;

    /** @var TranslatorInterface|null */
    private $translator;

    /** @var RequestInterface|null */
    private $request;

    /** @var ResponseFactoryInterface|null */
    private $response;

    /** @var PathsInterface|null */
    private $paths;

    public function name(): string
    {
        return 'webman';
    }

    public function config(): ConfigInterface
    {
        if ( $this->config === null ) {
            $this->config = new PrefixedConfig(
                static function ($key, $default = null) {
                    return config($key, $default);
                },
                self::CONFIG_PREFIX
            );
        }

        return $this->config;
    }

    public function translator(): TranslatorInterface
    {
        return $this->translator ?: ($this->translator = new WebmanTranslator());
    }

    public function request(): RequestInterface
    {
        return $this->request ?: ($this->request = new WebmanRequest());
    }

    public function response(): ResponseFactoryInterface
    {
        return $this->response ?: ($this->response = new WebmanResponseFactory());
    }

    public function paths(): PathsInterface
    {
        return $this->paths ?: ($this->paths = new WebmanPaths());
    }

    public function redis(): RedisInterface
    {
        return $this->redis ?: ($this->redis = new WebmanRedis());
    }

    public function events(): EventDispatcherInterface
    {
        return $this->events ?: ($this->events = new WebmanEvents());
    }

    /**
     * 当前请求对象即执行上下文身份：webman 每个请求都是新的 Request 实例，
     * 于是 Runtime::context() 会在请求切换时自动换一份 RequestContext，
     * 上一段请求的分组配置不会留给下一段。CLI 下没有请求，返回 null（退化为单上下文）。
     *
     * @return mixed
     */
    public function contextToken()
    {
        try {
            return function_exists('request') ? request() : null;
        } catch ( Throwable $e ) {
            return null;
        }
    }
}
