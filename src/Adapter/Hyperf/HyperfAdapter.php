<?php

namespace AetherUpload\Adapter\Hyperf;

use AetherUpload\Contract\ConfigInterface;
use AetherUpload\Contract\ContextStoreInterface;
use AetherUpload\Contract\EventDispatcherInterface;
use AetherUpload\Contract\PathsInterface;
use AetherUpload\Contract\RedisInterface;
use AetherUpload\Contract\RequestInterface;
use AetherUpload\Contract\ResponseFactoryInterface;
use AetherUpload\Contract\TranslatorInterface;
use AetherUpload\Kernel\AbstractAdapter;
use AetherUpload\Kernel\PrefixedConfig;
use Hyperf\Context\ApplicationContext;
use Hyperf\Context\Context;
use Hyperf\Contract\ConfigInterface as HyperfConfigInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;

/**
 * Hyperf 适配器：把内核端口接到 Hyperf 的容器、协程上下文（Hyperf\Context\Context）与 PSR-7 请求/响应上。
 *
 * 绑定位置：Listener\BootApplicationListener（由 ConfigProvider 的 listeners 键注册），
 * 该事件在 console 与 http 两条启动路径上都会派发一次，且早于命令实例化。
 */
class HyperfAdapter extends AbstractAdapter
{
    /** 配置前缀：与 config/autoload/aetherupload.php 对应 */
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

    /** @var array|null 插件自带的默认配置（config/aetherupload.php），进程级缓存 */
    private static $defaults;

    public function name(): string
    {
        return 'hyperf';
    }

    public function config(): ConfigInterface
    {
        if ( $this->config === null ) {
            $this->config = new PrefixedConfig(
                static function ($key, $default = null) {
                    // 安装脚本、composer 钩子等极早的阶段容器尚不存在：回落默认值而不是抛异常，
                    // 否则 Install::install() 这类「配置可以缺失」的调用点会直接崩掉。
                    if ( ! ApplicationContext::hasContainer() ) {
                        return $default;
                    }

                    try {
                        $value = ApplicationContext::getContainer()
                            ->get(HyperfConfigInterface::class)
                            ->get($key, null);
                    } catch ( Throwable $e ) {
                        $value = null;
                    }

                    if ( $value !== null ) {
                        return $value;
                    }

                    $shipped = self::shippedDefault(substr($key, strlen(self::CONFIG_PREFIX)));

                    return $shipped !== null ? $shipped : $default;
                },
                self::CONFIG_PREFIX
            );
        }

        return $this->config;
    }

    /**
     * 宿主还没发布 config/autoload/aetherupload.php 时的回落值（插件自带的 config/aetherupload.php）。
     *
     * 安装流程第一跳就会踩到：`php bin/hyperf.php aetherupload:publish` 也要先起容器，
     * 那一刻宿主配置还不存在，路由路径会是空串（FastRoute 直接抛「两条空路由」）。
     * 另有宿主忘了执行发布的情形 —— 回落自带默认值让「装了就能跑」，发布之后一律以宿主配置为准。
     *
     * 只做「宿主没有就补上」，宿主显式给的值（含空数组/false）不会被顶掉。
     *
     * @return mixed
     */
    private static function shippedDefault(string $key)
    {
        if ( self::$defaults === null ) {
            $file = dirname(__DIR__, 3) . '/config/aetherupload.php';
            self::$defaults = is_file($file) ? (array)require $file : [];
        }

        $value = self::$defaults;

        foreach ( explode('.', $key) as $segment ) {
            if ( ! is_array($value) || ! array_key_exists($segment, $value) ) {
                return null;
            }

            $value = $value[$segment];
        }

        return $value;
    }

    public function translator(): TranslatorInterface
    {
        return $this->translator ?: ($this->translator = new HyperfTranslator());
    }

    public function request(): RequestInterface
    {
        return $this->request ?: ($this->request = new HyperfRequest());
    }

    public function response(): ResponseFactoryInterface
    {
        return $this->response ?: ($this->response = new HyperfResponseFactory());
    }

    public function paths(): PathsInterface
    {
        return $this->paths ?: ($this->paths = new HyperfPaths());
    }

    public function redis(): RedisInterface
    {
        return $this->redis ?: ($this->redis = new HyperfRedis());
    }

    public function events(): EventDispatcherInterface
    {
        return $this->events ?: ($this->events = new HyperfEvents());
    }

    /**
     * 协程局部的上下文存储 —— 这一家唯一不能退化成进程级数组的端口。
     *
     * Swoole 在同一个 worker 进程里用协程交错处理多个请求，进程级静态数组会被下一个请求
     * 覆盖；Hyperf\Context\Context 按协程 id 取 ArrayObject，天然隔离（CLI 下自动退化为
     * 进程级数组，正是命令与安装脚本需要的语义）。
     */
    public function contextStore(): ContextStoreInterface
    {
        return $this->contextStore ?: ($this->contextStore = new HyperfContextStore());
    }

    /**
     * 当前协程里的 PSR-7 请求对象即执行上下文身份。
     *
     * 服务端在每个请求的协程里把它放进 Context（HttpServer\Server::initRequestAndResponse），
     * 于是 Runtime::context() 会随协程切换自动换一份 RequestContext；
     * 命令、安装脚本等没有请求的场景读不到它，返回 null（退化为单上下文）。
     *
     * @return mixed
     */
    public function contextToken()
    {
        try {
            return Context::get(ServerRequestInterface::class);
        } catch ( Throwable $e ) {
            return null;
        }
    }
}
