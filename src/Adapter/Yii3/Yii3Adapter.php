<?php

namespace AetherUpload\Adapter\Yii3;

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
use AetherUpload\Kernel\Psr7Request;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Yii3（yiisoft/* 包化重写的那一代）适配器。
 *
 * **它不是 Adapter\Yii 的翻版**：Yii2 是自带 app/request/response 的全栈框架，Yii3 全是 PSR 接口 ——
 * PSR-7 请求/响应、PSR-15 中间件、PSR-11 容器、PSR-14 事件，没有全局函数、没有 `Yii::$app`，
 * 配置来自 `yiisoft/config` 的配置组。所以 Yii3 这边**只能显式传配置进来**（与 Slim / 原生 PHP 同一路数）：
 *
 *     Runtime::bind(new Yii3Adapter($config, $basePath, $options));   // 或用 Bootstrap::bind()
 *
 * 默认值来自包内的 config/aetherupload.php，传入的数组在**顶层**覆盖它（与其他适配器同为浅合并：
 * 给了 groups 就整块替换，避免默认分组与自定义分组被悄悄深合并）。键名是内核的逻辑键，没有宿主前缀。
 *
 * $options（全部可选，只列有意义的）：
 *   base_path          由第 2 个构造参数给出，这里不重复
 *   redis              客户端实例，或 function (): object；phpredis 的 \Redis 或 Predis\Client 都行。
 *                      不给则用 NullRedis（开启秒传却不给 Redis 会直接报错，而不是静默失效）
 *   listeners          ['aetherupload.upload_complete' => callable|callable[]] 事件监听器
 *   dispatcher         PSR-14 派发器（Yii3 的 yiisoft/event-dispatcher 就是）或 function (Event $event): void，
 *                      异常由桥吞掉
 *   logger             function (string $message, \Throwable $e) 或带 error() 的对象（PSR-3）
 *   translations_path  {{base_path}}/resource/translations 的替代
 *   asset_path         {{base_path}}/public/vendor/aetherupload/js 的替代
 *   stream_factory     PSR-17 流工厂（文件响应零拷贝用）；不传则由响应的转换点按容器/缺省处理
 *
 * 请求侧：Yii3 没有 request() 这类全局函数，当前 PSR-7 请求由四条业务路由的 action 交给本适配器
 * （见 AetherUploadController 的 begin/end），`contextToken()` 就是那个请求对象 —— 于是
 * `Runtime::context()` 会在请求切换时自动换一份 RequestContext（常驻运行器下同进程连续处理多个请求，
 * 上一段的语种与分组配置快照绝不能留给下一段）。
 */
class Yii3Adapter extends AbstractAdapter
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

    /** @var Psr7Request|null */
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
     * @param string              $basePath 应用根目录（传 Yii3 的 $root = dirname(__DIR__) 最稳）；留空取 getcwd()
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
        return 'yii3';
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
        return $this->translatorPort ?: ($this->translatorPort = new Yii3Translator());
    }

    public function request(): RequestInterface
    {
        return $this->requestPort();
    }

    public function response(): ResponseFactoryInterface
    {
        return $this->responsePort ?: ($this->responsePort = new Yii3ResponseFactory());
    }

    public function paths(): PathsInterface
    {
        if ( $this->pathsPort === null ) {
            $this->pathsPort = new Yii3Paths(
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

            $this->redisPort = is_object($client) ? new Yii3Redis($client) : new NullRedis();
        }

        return $this->redisPort;
    }

    public function events(): EventDispatcherInterface
    {
        if ( $this->eventsPort === null ) {
            $this->eventsPort = new Yii3Events(
                (array)$this->option('listeners'),
                $this->option('dispatcher'),
                $this->option('logger')
            );
        }

        return $this->eventsPort;
    }

    /**
     * 执行上下文身份 = 当前 PSR-7 请求对象（Yii3 每个请求一个新实例）。
     * CLI（控制台命令、安装脚本）下没有请求，返回 null，退化为进程级单上下文。
     *
     * @return mixed
     */
    public function contextToken()
    {
        return $this->requestPort()->current();
    }

    /**
     * 每请求开始：登记当前请求（由 AetherUploadController 的 action 调用）。
     *
     * 与 Slim 不同，这里**不需要宿主往中间件栈里加东西**：Yii3 的路由 action 直接拿得到
     * `ServerRequestInterface`（yiisoft/middleware-dispatcher 的 MiddlewareFactory 按类型注入），
     * 于是登记与清理都留在本包内。
     */
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

    private function requestPort(): Psr7Request
    {
        return $this->requestPort ?: ($this->requestPort = new Psr7Request());
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
