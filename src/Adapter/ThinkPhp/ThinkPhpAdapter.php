<?php

namespace AetherUpload\Adapter\ThinkPhp;

use AetherUpload\Contract\ConfigInterface;
use AetherUpload\Contract\EventDispatcherInterface;
use AetherUpload\Contract\PathsInterface;
use AetherUpload\Contract\RedisInterface;
use AetherUpload\Contract\RequestInterface;
use AetherUpload\Contract\ResponseFactoryInterface;
use AetherUpload\Contract\TranslatorInterface;
use AetherUpload\Kernel\AbstractAdapter;
use AetherUpload\Kernel\PrefixedConfig;
use think\App;

/**
 * ThinkPHP 适配器：把内核端口代理到 think\facade\* 与 think\* 类。
 *
 * 一行的绑定位置：AetherUploadService::register()（由宿主 app/service.php 登记该服务）。
 *
 * 与 webman 版的差异只有三处，都是 ThinkPHP 的既有语义：
 *  - 配置前缀是 aetherupload.（think\Config 的点号即嵌套）
 *  - input() 走 param($name, $default, null)：第三个参数必须是 null 才关掉过滤器
 *    （传 '' 是假值，think\Request::getFilter() 会回落到请求级过滤器）
 *  - Redis 走 Cache::store('redis')->handler() 拿原始客户端（Cache 的 key 前缀会毁掉 keys()）
 */
class ThinkPhpAdapter extends AbstractAdapter
{
    /** 配置前缀：与 config/aetherupload.php 的一级键同名 */
    const CONFIG_PREFIX = 'aetherupload.';

    /** @var App|null 由 Service 注入；为 null 时端口自己退化为「无请求」形态 */
    private $app;

    /** @var ConfigInterface|null */
    private $config;

    /** @var ThinkPhpRequest|null */
    private $request;

    /** @var TranslatorInterface|null */
    private $translator;

    /** @var ResponseFactoryInterface|null */
    private $response;

    /** @var PathsInterface|null */
    private $paths;

    public function __construct(?App $app = null)
    {
        $this->app = $app;
    }

    public function name(): string
    {
        return 'thinkphp';
    }

    public function config(): ConfigInterface
    {
        if ( $this->config === null ) {
            $this->config = new PrefixedConfig(
                static function ($key, $default = null) {
                    return \think\facade\Config::get($key, $default);
                },
                self::CONFIG_PREFIX
            );
        }

        return $this->config;
    }

    public function translator(): TranslatorInterface
    {
        return $this->translator ?: ($this->translator = new ThinkPhpTranslator());
    }

    public function request(): RequestInterface
    {
        return $this->request ?: ($this->request = new ThinkPhpRequest($this->app));
    }

    public function response(): ResponseFactoryInterface
    {
        return $this->response ?: ($this->response = new ThinkPhpResponseFactory());
    }

    public function paths(): PathsInterface
    {
        return $this->paths ?: ($this->paths = new ThinkPhpPaths($this->app));
    }

    public function redis(): RedisInterface
    {
        return $this->redis ?: ($this->redis = new ThinkPhpRedis());
    }

    public function events(): EventDispatcherInterface
    {
        return $this->events ?: ($this->events = new ThinkPhpEvents());
    }

    /**
     * 当前请求对象即执行上下文身份。
     *
     * Http::run() 每次请求都 make 出一个新的 think\Request 并 instance('request', …)，
     * 所以请求切换时 Runtime::context() 会自动换一份 RequestContext，上一段请求的
     * 分组配置不会留给下一段（think-swoole / think-worker 这类常驻进程下是必需的；
     * PHP-FPM 下每请求一个进程，返回什么值都不影响）。CLI 与命令里没有请求，返回 null。
     *
     * @return mixed
     */
    public function contextToken()
    {
        $port = $this->request();

        return $port instanceof ThinkPhpRequest ? $port->currentRequest() : null;
    }
}
