<?php

namespace AetherUpload\Adapter\Hyperf\Listener;

use AetherUpload\Adapter\Hyperf\HyperfAdapter;
use AetherUpload\ResourceController;
use AetherUpload\Runtime;
use AetherUpload\UploadController;
use Hyperf\Event\Contract\ListenerInterface;
use Hyperf\Framework\Event\BootApplication;
use Hyperf\HttpServer\Router\DispatcherFactory;
use Hyperf\HttpServer\Router\Router;
use Psr\Container\ContainerInterface;

/**
 * 在 BootApplication 上做两件事：绑定内核的 Runtime 门面、注册四条业务路由。
 *
 * 选这个事件是因为它在 console 与 http 两条启动路径上各派发一次
 * （bin/hyperf.php 取 ApplicationInterface 时就会触发），且早于命令实例化 ——
 * 命令壳与路由都需要「Runtime 已绑定」这个前提。
 */
class BootApplicationListener implements ListenerInterface
{
    /** @var ContainerInterface */
    private $container;

    public function __construct(ContainerInterface $container)
    {
        $this->container = $container;
    }

    public function listen(): array
    {
        return [
            BootApplication::class,
        ];
    }

    public function process(object $event): void
    {
        // 先绑定：下面的路由路径来自配置，配置读取已经要走 Runtime
        Runtime::bind(new HyperfAdapter());

        $this->registerRoutes();
    }

    private function registerRoutes(): void
    {
        // 必须先让 DispatcherFactory 构造出来：它的构造函数里才执行 Router::init($this) 并
        // require config/routes.php。本监听器的执行顺序相对其它组件没有保证，
        // 少了这一行就会踩到 Router::$factory === null。
        $this->container->get(DispatcherFactory::class);

        Router::addRoute(['POST'], $this->config('route_preprocess'), [UploadController::class, 'preprocess'], $this->options('middleware_preprocess'));
        Router::addRoute(['POST'], $this->config('route_uploading'), [UploadController::class, 'saveChunk'], $this->options('middleware_uploading'));
        Router::addRoute(['GET'], $this->config('route_display') . '/{uri}', [ResourceController::class, 'display'], $this->options('middleware_display'));
        Router::addRoute(['GET'], $this->config('route_download') . '/{uri}/{newName}', [ResourceController::class, 'download'], $this->options('middleware_download'));
    }

    /**
     * @return array<string,mixed>
     */
    private function options(string $middlewareKey): array
    {
        $middlewares = (array)Runtime::config()->get($middlewareKey, []);

        // 空数组也照传：Hyperf 的 addMiddlewares 对空集合是空操作，
        // 但省掉这个分支就少一种「装了却不生效」的排查路径
        return ['middleware' => $middlewares];
    }

    private function config(string $key): string
    {
        return (string)Runtime::config()->get($key);
    }
}
