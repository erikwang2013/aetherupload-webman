<?php

namespace AetherUpload\Adapter\Slim;

use AetherUpload\Contract\ConfigInterface;
use AetherUpload\Contract\EventDispatcherInterface;
use AetherUpload\Contract\PathsInterface;
use AetherUpload\Contract\RedisInterface;
use AetherUpload\Contract\RequestInterface;
use AetherUpload\Contract\ResponseFactoryInterface;
use AetherUpload\Contract\TranslatorInterface;
use AetherUpload\Kernel\AbstractAdapter;
use AetherUpload\Kernel\NullRedis;
use AetherUpload\Kernel\PrefixedConfig;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Slim 4 适配器。
 *
 * **Slim 没有配置概念**，所以配置不是去宿主里找，而是显式传进来：
 *
 *     Runtime::bind(new SlimAdapter($config, $basePath, $options));   // 或用 Bootstrap::bind()/create()
 *
 * 默认值来自包内的 config/aetherupload.php（同一份文件也是其余非 webman 适配器的参考默认值），
 * 传入的数组在**顶层**覆盖它（与 Laravel 的 mergeConfigFrom 同为浅合并：给了 groups 就整块替换，
 * 避免默认分组与自定义分组被悄悄深合并）。键名是内核的逻辑键，没有宿主前缀。
 *
 * $options（全部可选，只列有意义的）：
 *   base_path          由第 2 个构造参数给出，这里不重复
 *   redis              客户端实例，或 function (): object；phpredis 的 \Redis 或 Predis\Client 都行。
 *                      经 Bootstrap::create() 接入时还可以写成 PSR-11 容器里的服务 id（见该类）。
 *                      不给则用 NullRedis（开启秒传却不给 Redis 会直接报错，而不是静默失效）
 *   listeners          ['aetherupload.upload_complete' => callable|callable[]] 事件监听器
 *   dispatcher         PSR-14 派发器或 function (Event $event): void，异常由桥吞掉
 *   logger             function (string $message, \Throwable $e) 或带 error() 的对象（PSR-3）
 *   translations_path  {{base_path}}/resource/translations 的替代
 *   asset_path         {{base_path}}/public/vendor/aetherupload/js 的替代
 *   stream_factory     PSR-17 流工厂（文件响应零拷贝用）；默认交给 Bootstrap 探测
 *
 * 请求侧：Slim 没有 request() 这类全局函数，当前 PSR-7 请求由 Bootstrap::create() 注册的中间件
 * 用 beginRequest()/endRequest() 交给本适配器；contextToken() 就是那个请求对象，
 * 于是 Runtime::context() 会在请求切换时自动换一份 RequestContext。
 */
class SlimAdapter extends AbstractAdapter
{
    /** @var array<string,mixed> 合并后的配置快照 */
    private $config;

    /** @var string 项目根目录（无尾部分隔符） */
    private $basePath;

    /** @var array<string,mixed> */
    private $options;

    /** @var ConfigInterface|null */
    private $configPort;

    /** @var TranslatorInterface|null */
    private $translatorPort;

    /** @var SlimRequest|null */
    private $requestPort;

    /** @var ResponseFactoryInterface|null */
    private $responsePort;

    /** @var PathsInterface|null */
    private $pathsPort;

    /** @var RedisInterface|null */
    private $redisPort;

    /** @var EventDispatcherInterface|null */
    private $eventsPort;

    /**
     * @param array<string,mixed> $config   逻辑键配置；顶层覆盖包内默认值
     * @param string              $basePath 应用根目录（传 __DIR__ / dirname(__DIR__) 最稳）；留空取 getcwd()
     * @param array<string,mixed> $options  见类注释
     */
    public function __construct(array $config = [], string $basePath = '', array $options = [])
    {
        $this->config = array_merge(self::defaults(), $config);
        $this->basePath = rtrim($basePath !== '' ? $basePath : (string)getcwd(), '/\\');
        $this->options = $options;
    }

    public function name(): string
    {
        return 'slim';
    }

    public function config(): ConfigInterface
    {
        if ( $this->configPort === null ) {
            $this->configPort = new PrefixedConfig(
                function ($key, $default = null) {
                    return $this->configValue($key, $default);
                },
                ''
            );
        }

        return $this->configPort;
    }

    public function translator(): TranslatorInterface
    {
        return $this->translatorPort ?: ($this->translatorPort = new SlimTranslator());
    }

    public function request(): RequestInterface
    {
        return $this->requestPort();
    }

    public function response(): ResponseFactoryInterface
    {
        return $this->responsePort ?: ($this->responsePort = new SlimResponseFactory());
    }

    public function paths(): PathsInterface
    {
        if ( $this->pathsPort === null ) {
            $this->pathsPort = new SlimPaths(
                $this->basePath,
                $this->option('translations_path'),
                $this->option('asset_path')
            );
        }

        return $this->pathsPort;
    }

    public function redis(): RedisInterface
    {
        if ( $this->redisPort === null ) {
            $client = $this->option('redis');

            if ( is_callable($client) ) {
                $client = $client();
            }

            $this->redisPort = is_object($client) ? new SlimRedis($client) : new NullRedis();
        }

        return $this->redisPort;
    }

    public function events(): EventDispatcherInterface
    {
        if ( $this->eventsPort === null ) {
            $this->eventsPort = new SlimEvents(
                (array)$this->option('listeners'),
                $this->option('dispatcher'),
                $this->option('logger')
            );
        }

        return $this->eventsPort;
    }

    /**
     * 执行上下文身份 = 当前 PSR-7 请求对象（Slim 里每个请求都是新实例）。
     * CLI（命令、安装脚本）下没有请求，返回 null，退化为进程级单上下文。
     *
     * @return mixed
     */
    public function contextToken()
    {
        return $this->requestPort()->current();
    }

    /** 每请求开始：登记当前请求（由 Bootstrap::create() 的中间件调用） */
    public function beginRequest(ServerRequestInterface $request): void
    {
        $this->requestPort()->setCurrent($request);
    }

    /** 每请求结束：清掉当前请求与本次请求落盘的上传临时副本 */
    public function endRequest(): void
    {
        $this->requestPort()->cleanupTempFiles();
        $this->requestPort()->setCurrent(null);
    }

    private function requestPort(): SlimRequest
    {
        return $this->requestPort ?: ($this->requestPort = new SlimRequest());
    }

    /**
     * 点号取嵌套配置（内核的逻辑键形态：groups.file.group_dir）。
     *
     * @param mixed $default
     * @return mixed
     */
    private function configValue(string $key, $default = null)
    {
        $value = $this->config;

        foreach ( explode('.', $key) as $segment ) {
            if ( ! is_array($value) || ! array_key_exists($segment, $value) ) {
                return $default;
            }

            $value = $value[$segment];
        }

        return $value;
    }

    /**
     * @param string $name
     * @return mixed
     */
    private function option(string $name)
    {
        return isset($this->options[$name]) ? $this->options[$name] : null;
    }

    /**
     * 参考默认值 = 包内的 config/aetherupload.php（与 webman 版逐键一致，由 tests/ConfigParityTest.php 守着）。
     * 文件缺失（异常安装形态）时给出空数组，交给内核自己的缺失兜底，不在构造期抛异常。
     *
     * @return array<string,mixed>
     */
    private static function defaults(): array
    {
        $file = dirname(__DIR__, 3) . '/config/aetherupload.php';

        if ( ! is_file($file) ) {
            return [];
        }

        $config = require $file;

        return is_array($config) ? $config : [];
    }
}
