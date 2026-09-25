<?php

namespace AetherUpload\Adapter\Symfony;

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
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface as SymfonyEventDispatcherInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Translation\TranslatorInterface as SymfonyTranslatorInterface;
use Throwable;

/**
 * Symfony 适配器：把内核端口接到 Symfony 的容器参数与 HttpFoundation 组件上。
 *
 * 绑定位置：AetherUploadBundle::boot()（每个进程一次；boot 时容器已编译完，参数与公开服务都可用）。
 */
class SymfonyAdapter extends AbstractAdapter
{
    /** 配置在容器里的前缀：与 Configuration 的根名、app 里 YAML 段落名一致 */
    const CONFIG_PREFIX = 'aetherupload.';

    /** redis 客户端在容器里的候选服务名（按顺序取第一个存在的） */
    const REDIS_SERVICE_IDS = ['aetherupload.redis_client', 'Redis', 'redis', 'Predis\Client'];

    /** @var ContainerInterface */
    private $container;

    /** @var RequestStack|null */
    private $requestStack;

    /** @var SymfonyTranslatorInterface|null */
    private $hostTranslator;

    /** @var SymfonyEventDispatcherInterface|null */
    private $hostDispatcher;

    /** @var LoggerInterface|null */
    private $logger;

    /** @var string */
    private $projectDir;

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

    public function __construct(
        ContainerInterface $container,
        ?RequestStack $requestStack,
        ?SymfonyTranslatorInterface $hostTranslator,
        ?SymfonyEventDispatcherInterface $hostDispatcher,
        ?LoggerInterface $logger,
        string $projectDir
    ) {
        $this->container = $container;
        $this->requestStack = $requestStack;
        $this->hostTranslator = $hostTranslator;
        $this->hostDispatcher = $hostDispatcher;
        $this->logger = $logger;
        $this->projectDir = $projectDir;
    }

    public function name(): string
    {
        return 'symfony';
    }

    /**
     * 配置端口接的是**容器参数**：每个逻辑键都是一个参数
     * （aetherupload.chunk_size、aetherupload.groups.file.group_dir …），
     * 由 AetherUploadExtension 从配置树里逐个导出；路由文件读的是同一批参数。
     */
    public function config(): ConfigInterface
    {
        if ( $this->config === null ) {
            $container = $this->container;

            $this->config = new PrefixedConfig(
                static function ($key, $default = null) use ($container) {
                    return $container->hasParameter($key) ? $container->getParameter($key) : $default;
                },
                self::CONFIG_PREFIX
            );
        }

        return $this->config;
    }

    public function translator(): TranslatorInterface
    {
        return $this->translator ?: ($this->translator = new SymfonyTranslator($this->hostTranslator));
    }

    public function request(): RequestInterface
    {
        return $this->request ?: ($this->request = new SymfonyRequest($this->requestStack));
    }

    public function response(): ResponseFactoryInterface
    {
        return $this->response ?: ($this->response = new SymfonyResponseFactory());
    }

    public function paths(): PathsInterface
    {
        return $this->paths ?: ($this->paths = new SymfonyPaths($this->projectDir));
    }

    public function redis(): RedisInterface
    {
        if ( $this->redis === null ) {
            $client = $this->redisClient();

            // 没接客户端时退化为 NullRedis：一调用就抛，比「秒传静默失效」好排查
            $this->redis = $client === null ? new NullRedis() : new SymfonyRedis($client);
        }

        return $this->redis;
    }

    public function events(): EventDispatcherInterface
    {
        return $this->events ?: ($this->events = new SymfonyEvents($this->hostDispatcher, $this->logger));
    }

    /**
     * 当前请求对象即执行上下文身份。
     *
     * 与 webman 同理：一个 Symfony 进程未必只处理一个请求 —— 长驻 worker（RoadRunner、FrankenPHP）
     * 会顺序处理多个请求，子请求（fragment/ESI）还会在当前请求内嵌套一次。请求对象换一个，
     * Runtime::context() 就换一份 RequestContext，上一段的分组配置与语种不会留给下一段。
     * CLI（命令、安装器）下没有请求，返回 null，退化为单上下文，与 webman 的 CLI 行为一致。
     *
     * @return mixed
     */
    public function contextToken()
    {
        return $this->requestStack === null ? null : $this->requestStack->getCurrentRequest();
    }

    /**
     * 找宿主容器里的 redis 客户端。
     *
     * 与 Laravel/ThinkPHP 不同，Symfony 没有框架自带的 redis，客户端是应用自己定义的服务。
     * 从容器外部只能取到**公开**服务：私有客户端请起个别名暴露（见 README 的 Symfony 一节），
     * 否则秒传不可用（其余功能不受影响）。
     *
     * @return object|null
     */
    private function redisClient()
    {
        foreach ( self::REDIS_SERVICE_IDS as $id ) {
            if ( ! $this->container->has($id) ) {
                continue;
            }

            try {
                $client = $this->container->get($id);
            } catch ( Throwable $e ) {
                continue;
            }

            if ( is_object($client) ) {
                return $client;
            }
        }

        return null;
    }
}
