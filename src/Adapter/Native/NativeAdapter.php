<?php

namespace AetherUpload\Adapter\Native;

use AetherUpload\Contract\ConfigInterface;
use AetherUpload\Contract\EventDispatcherInterface;
use AetherUpload\Contract\PathsInterface;
use AetherUpload\Contract\RedisInterface;
use AetherUpload\Contract\RequestInterface;
use AetherUpload\Contract\ResponseFactoryInterface;
use AetherUpload\Contract\TranslatorInterface;
use AetherUpload\Kernel\AbstractAdapter;
use AetherUpload\Kernel\BasePaths;
use AetherUpload\Kernel\ClientRedis;
use AetherUpload\Kernel\NullRedis;
use AetherUpload\Kernel\PhpFileTranslator;
use AetherUpload\Kernel\PrefixedConfig;

/**
 * 原生 PHP（无框架）适配器。
 *
 * 这一份是「宿主什么都没有」的形态：没有配置系统、没有翻译组件、没有请求对象、没有容器。
 * 于是能内置的都内置了（请求读超全局、响应直接发、翻译直读 messages.php、路径按惯例拼），
 * 只有三样必须由使用者决定：**项目根目录、配置数组、Redis 客户端（要秒传的话）**。
 *
 *     // public/index.php（或 php -S 的 router.php）
 *     require __DIR__ . '/../vendor/autoload.php';
 *     exit(\AetherUpload\Adapter\Native\Bootstrap::handle([
 *         'base_path' => dirname(__DIR__),
 *         'config'    => require __DIR__ . '/../config/aetherupload.php',   // 省略则用包内默认值
 *         'redis'     => static fn () => new \Redis(),                       // 秒传需要，可选
 *     ]));
 *
 * 默认值来自包内的 config/aetherupload.php（同一份文件也是其余非 webman 适配器的参考默认值），
 * 传入的数组在**顶层**覆盖它（与 Laravel 的 mergeConfigFrom 同为浅合并：给了 groups 就整块替换，
 * 避免默认分组与自定义分组被悄悄深合并）。键名是内核的逻辑键，没有宿主前缀。
 *
 * $options（全部可选，只列有意义的）：
 *   redis              客户端实例，或 function (): object；phpredis 的 \Redis 或 Predis\Client 都行。
 *                      不给则用 NullRedis（开启秒传却不给 Redis 会直接报错，而不是静默失效）
 *   listeners          ['aetherupload.upload_complete' => callable|callable[]] 事件监听器
 *   logger             function (string $message, \Throwable $e) 或带 error() 的对象（PSR-3）
 *   translations_path  {base_path}/resource/translations 的替代
 *   asset_path         {base_path}/public/vendor/aetherupload/js 的替代
 *
 * 请求侧：`$_GET`/`$_POST`/`$_FILES` 由 PHP 自己按请求填充，适配器不缓存输入；
 * 上下文身份由 Bootstrap::handle() 每请求发一个新 token —— 原生 PHP 虽然多是「一请求一进程」，
 * 但 `php -S` 与常驻 worker 会在同一进程里连续处理请求，不分上下文就会串组。
 */
class NativeAdapter extends AbstractAdapter
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

    /** @var NativeRequest|null */
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
     * @param string              $basePath 应用根目录（传 dirname(__DIR__) 最稳）；留空取 getcwd()
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
        return 'native';
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
        return $this->translatorPort ?: ($this->translatorPort = new PhpFileTranslator());
    }

    public function request(): RequestInterface
    {
        return $this->requestPort();
    }

    public function response(): ResponseFactoryInterface
    {
        return $this->responsePort ?: ($this->responsePort = new NativeResponseFactory());
    }

    public function paths(): PathsInterface
    {
        if ( $this->pathsPort === null ) {
            $this->pathsPort = new BasePaths(
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

            $this->redisPort = is_object($client) ? new ClientRedis($client) : new NullRedis();
        }

        return $this->redisPort;
    }

    public function events(): EventDispatcherInterface
    {
        if ( $this->eventsPort === null ) {
            $this->eventsPort = new NativeEvents(
                (array)$this->option('listeners'),
                $this->option('logger')
            );
        }

        return $this->eventsPort;
    }

    /**
     * 执行上下文身份 = 本次请求的 token（Bootstrap::handle() 每请求换一个）。
     * CLI（控制台命令、安装脚本）下没有请求，返回 null，退化为进程级单上下文。
     *
     * @return object|null
     */
    public function contextToken()
    {
        return $this->requestPort()->token();
    }

    /** 每请求开始：换上下文身份（由 Bootstrap::handle() 调用） */
    public function beginRequest(): void
    {
        $this->requestPort()->begin();
    }

    /** 每请求结束：丢掉身份，回到进程级上下文 */
    public function endRequest(): void
    {
        $this->requestPort()->end();
    }

    private function requestPort(): NativeRequest
    {
        return $this->requestPort ?: ($this->requestPort = new NativeRequest());
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
