<?php

namespace AetherUpload\Adapter\Laravel;

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
 * Laravel 适配器：把内核端口代理到 Laravel 的容器、门面与辅助函数。
 *
 * 一行的绑定位置：AetherUploadServiceProvider::register()（见同目录 ServiceProvider）。
 * 与 webman 版一样，本类只持有**不可变**的进程级绑定，可变状态全在 RequestContext。
 */
class LaravelAdapter extends AbstractAdapter
{
    /** 配置前缀：config/aetherupload.php 由 ServiceProvider 的 mergeConfigFrom 合并进来 */
    const CONFIG_PREFIX = 'aetherupload.';

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
        return 'laravel';
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
        return $this->translator ?: ($this->translator = new LaravelTranslator());
    }

    public function request(): RequestInterface
    {
        return $this->request ?: ($this->request = new LaravelRequest());
    }

    public function response(): ResponseFactoryInterface
    {
        return $this->response ?: ($this->response = new LaravelResponseFactory());
    }

    public function paths(): PathsInterface
    {
        return $this->paths ?: ($this->paths = new LaravelPaths());
    }

    public function redis(): RedisInterface
    {
        return $this->redis ?: ($this->redis = new LaravelRedis());
    }

    public function events(): EventDispatcherInterface
    {
        return $this->events ?: ($this->events = new LaravelEvents());
    }

    /**
     * 当前请求对象即执行上下文身份。
     *
     * HTTP 处理期间 Laravel 会把请求实例绑进容器（Kernel 里的 instance('request', ...)），
     * 于是上下文切换时 RequestContext 会自动换一份；CLI（artisan）下容器里没有请求绑定，
     * 返回 null 退化为单上下文。这也让本适配器在 Octane 这类常驻进程下不会串请求配置。
     *
     * @return mixed
     */
    public function contextToken()
    {
        try {
            $container = function_exists('app') ? app() : null;

            return $container !== null && $container->bound('request') ? $container->make('request') : null;
        } catch ( Throwable $e ) {
            return null;
        }
    }
}
