<?php

namespace AetherUpload;

use AetherUpload\Contract\AdapterInterface;
use AetherUpload\Contract\ConfigInterface;
use AetherUpload\Contract\ContextStoreInterface;
use AetherUpload\Contract\EventDispatcherInterface;
use AetherUpload\Contract\FilesystemInterface;
use AetherUpload\Contract\PathsInterface;
use AetherUpload\Contract\RedisInterface;
use AetherUpload\Contract\RequestInterface;
use AetherUpload\Contract\ResponseFactoryInterface;
use AetherUpload\Contract\TranslatorInterface;
use RuntimeException;

/**
 * 内核访问宿主能力的唯一入口。
 *
 * 分工（这条边界是全案最容易出错的地方）：
 *  - 本类与适配器只持有**不可变绑定**（进程级，bind() 一次）
 *  - 一切**可变状态**（当前分组配置、语种、已登记的语言文件）放 RequestContext，
 *    由 Runtime::context() 按执行上下文（请求/协程）隔离
 *
 * 用静态门面而非构造注入，是因为 PartialResource/Header/Resource 的构造签名被大量既有用例
 * 钉死；门面让 136 处调用点机械替换即可，不必触碰那些用例。
 */
final class Runtime
{
    /** ContextStore 里的键名 */
    private const CONTEXT_ID = 'aetherupload.context';

    /** @var AdapterInterface|null */
    private static $adapter = null;

    /** @var RequestContext|null */
    private static $context = null;

    /** @var mixed */
    private static $contextToken = null;

    /** @var bool 区分「尚未解析上下文」与「上下文 token 恰好是 null」 */
    private static $contextResolved = false;

    private function __construct()
    {
    }

    /**
     * 绑定宿主的适配器。各框架在自己启动点显式调用一次。
     *
     * 刻意不做自动探测：function_exists('config') 在 webman 与 Laravel 下同为真，
     * 任何启发式判定都会误判。可重复调用，后者覆盖前者。
     */
    public static function bind(AdapterInterface $adapter): void
    {
        self::$adapter = $adapter;
        self::$context = null;
        self::$contextToken = null;
        self::$contextResolved = false;
    }

    /** 解除绑定并丢弃当前上下文（测试用） */
    public static function reset(): void
    {
        self::$adapter = null;
        self::$context = null;
        self::$contextToken = null;
        self::$contextResolved = false;
    }

    /** 是否已绑定适配器（供安装器/诊断在「宿主还没启动」的场景下判断） */
    public static function isBound(): bool
    {
        return self::$adapter !== null;
    }

    public static function adapter(): AdapterInterface
    {
        if ( self::$adapter === null ) {
            throw new RuntimeException(
                'AetherUpload 尚未绑定宿主适配器。请在应用启动点调用 Runtime::bind(new <Framework>Adapter(...))。'
            );
        }

        return self::$adapter;
    }

    public static function config(): ConfigInterface
    {
        return self::adapter()->config();
    }

    public static function translator(): TranslatorInterface
    {
        return self::adapter()->translator();
    }

    public static function request(): RequestInterface
    {
        return self::adapter()->request();
    }

    public static function response(): ResponseFactoryInterface
    {
        return self::adapter()->response();
    }

    public static function events(): EventDispatcherInterface
    {
        return self::adapter()->events();
    }

    public static function redis(): RedisInterface
    {
        return self::adapter()->redis();
    }

    public static function paths(): PathsInterface
    {
        return self::adapter()->paths();
    }

    public static function filesystem(): FilesystemInterface
    {
        return self::adapter()->filesystem();
    }

    public static function contextStore(): ContextStoreInterface
    {
        return self::adapter()->contextStore();
    }

    /** 项目根目录（内核里 16 处 base_path() 的归并点） */
    public static function basePath(): string
    {
        return self::adapter()->paths()->basePath();
    }

    /** 便捷方法：调用点最多（34 处）的翻译，值得省掉一层 ->translator() */
    public static function trans(string $key): string
    {
        return self::adapter()->translator()->trans($key);
    }

    /** 便捷方法：设置本次执行上下文的语种 */
    public static function setLocale(string $locale): void
    {
        self::adapter()->translator()->setLocale($locale);
    }

    /**
     * 取当前请求/协程的可变状态；执行上下文切换时自动换一个新的。
     *
     * webman 常驻进程与 Hyperf 协程下，同一个进程会交错处理多个请求，
     * 上一段的配置快照绝不能留给下一段用。
     */
    public static function context(): RequestContext
    {
        $adapter = self::adapter();
        $token = $adapter->contextToken();

        if ( self::$contextResolved && self::$context !== null && self::$contextToken === $token ) {
            return self::$context;
        }

        $store = $adapter->contextStore();
        $stored = $store->get(self::CONTEXT_ID);

        if (
            is_array($stored)
            && array_key_exists('token', $stored)
            && $stored['token'] === $token
            && isset($stored['context'])
            && $stored['context'] instanceof RequestContext
        ) {
            $context = $stored['context'];
        } else {
            $context = new RequestContext();
            $store->set(self::CONTEXT_ID, ['token' => $token, 'context' => $context]);
        }

        self::$context = $context;
        self::$contextToken = $token;
        self::$contextResolved = true;

        return $context;
    }
}
