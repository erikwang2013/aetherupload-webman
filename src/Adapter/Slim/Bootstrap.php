<?php

namespace AetherUpload\Adapter\Slim;

use AetherUpload\ResourceController;
use AetherUpload\Runtime;
use AetherUpload\UploadController;
use Closure;
use Psr\Http\Message\StreamFactoryInterface;
use Slim\App;
use Slim\Factory\AppFactory;
use Slim\Interfaces\RouteInterface;
use RuntimeException;

/**
 * Slim 的引导工厂：**一行接入**。
 *
 *     // public/index.php
 *     $app = \AetherUpload\Adapter\Slim\Bootstrap::create([
 *         'config'    => require __DIR__ . '/../config/aetherupload.php',   // 省略则用包内默认值
 *         'base_path' => dirname(__DIR__),
 *         'redis'     => static fn () => new Predis\Client(['database' => 0]),   // 秒传需要
 *     ]);
 *     $app->run();
 *
 * create() 做四件事：建/复用 Slim\App → 绑定适配器（Runtime::bind）→ 挂「当前请求」中间件 →
 * 按配置里的路径与中间件注册四条业务路由。要自己拼装中间件栈就传 `app` 选项（已有 App）或改用
 * bind() + 自己注册路由。
 *
 * @see SlimAdapter 配置与选项清单
 */
class Bootstrap
{
    /**
     * 绑定适配器，返回它（回调式接入用，例如控制台入口只需这一行 + 一个 Application）。
     *
     * @param array<string,mixed> $config
     * @param array<string,mixed> $options
     */
    public static function bind(array $config = [], string $basePath = '', array $options = []): SlimAdapter
    {
        $adapter = new SlimAdapter($config, $basePath, $options);

        Runtime::bind($adapter);

        return $adapter;
    }

    /**
     * 建好一个可直接 run() 的 Slim 应用。
     *
     * @param array<string,mixed> $options 见 SlimAdapter 类注释，外加：
     *                                     app（已有 Slim\App，在其上追加）、container（PSR-11，交给 AppFactory；
     *                                     redis 选项写服务 id 时也靠它解析）、
     *                                     stream_factory（PSR-17 流工厂）、display_error_details（默认 false）
     */
    public static function create(array $options = []): App
    {
        $app = isset($options['app']) && $options['app'] instanceof App
            ? $options['app']
            : AppFactory::create(null, isset($options['container']) ? $options['container'] : null);

        $adapter = self::bind(
            isset($options['config']) ? (array)$options['config'] : [],
            isset($options['base_path']) ? (string)$options['base_path'] : '',
            self::resolveServices($app, $options)
        );

        // 每请求把当前 PSR-7 请求交给适配器（内核的 Runtime::request() 由它供数），
        // 请求结束时删掉适配器自己落盘的上传临时副本。放在最内层：从路由中间件往里都能拿到请求。
        //
        // 这里**不能**写成 static function：Slim 在有 PSR-11 容器时会对闭包做
        // `$closure->bindTo($container)`（MiddlewareDispatcher::addCallable、CallableResolver），
        // 而 bindTo 一个 static 闭包返回 null，Slim 随后就把 null 当中间件用，直接 TypeError。
        $app->add(function ($request, $handler) use ($adapter) {
            $adapter->beginRequest($request);

            try {
                return $handler->handle($request);
            } finally {
                $adapter->endRequest();
            }
        });

        $app->addRoutingMiddleware();

        // 错误中间件要挂在最外层（Slim 的惯例：最后 add），否则未知路由会抛 HttpNotFoundException
        // 直接变成 500 —— 其余五个框架那里都是 404，行为必须一致。展示细节默认关（生产安全），
        // 开发时用 display_error_details=true 打开
        // 第三个参数（logErrorDetails）刻意给 false：Slim 默认会把整份堆栈写进 error_log，
        // 而 404 是常规路径，日志里堆栈刷屏没有价值（要排障时改成 true 即可）
        $app->addErrorMiddleware(
            isset($options['display_error_details']) ? (bool)$options['display_error_details'] : false,
            true,
            false
        );

        self::registerRoutes($app, $adapter, self::convert($app, $options));

        return $app;
    }

    /**
     * 注册四条业务路由。控制器的构造签名是「路由变量作参数」，与 Slim 的
     * `function ($request, $response, $args)` 不同，所以这里逐条适配参数 ——
     * 宿主请求一律经 Runtime::request() 取，不从这里透传。
     */
    private static function registerRoutes(App $app, SlimAdapter $adapter, Closure $convert): void
    {
        $config = $adapter->config();

        $preprocess = $app->post($config->get('route_preprocess'), self::handler($convert, static function (array $args) {
            return (new UploadController())->preprocess();
        }));
        self::addMiddleware($preprocess, $config->get('middleware_preprocess'));

        $uploading = $app->post($config->get('route_uploading'), self::handler($convert, static function (array $args) {
            return (new UploadController())->saveChunk();
        }));
        self::addMiddleware($uploading, $config->get('middleware_uploading'));

        $display = $app->get($config->get('route_display') . '/{uri}', self::handler($convert, static function (array $args) {
            return (new ResourceController())->display($args['uri']);
        }));
        self::addMiddleware($display, $config->get('middleware_display'));

        $download = $app->get($config->get('route_download') . '/{uri}/{newName}', self::handler($convert, static function (array $args) {
            return (new ResourceController())->download($args['uri'], isset($args['newName']) ? $args['newName'] : null);
        }));
        self::addMiddleware($download, $config->get('middleware_download'));
    }

    /**
     * 把「服务 id」形式的选项解析成实例：Slim 的惯例是服务都放 PSR-11 容器里，
     * 所以 redis 选项可以写成容器里的 id（'redis' => 'aetherupload.redis'）。
     * 直接给实例、给 `fn () => new Predis\Client()` 也照旧。
     *
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    private static function resolveServices(App $app, array $options): array
    {
        if ( ! isset($options['redis']) || ! is_string($options['redis']) ) {
            return $options;
        }

        $container = $app->getContainer();

        if ( $container === null ) {
            throw new RuntimeException(
                'AetherUpload: redis 选项给的是服务 id「' . $options['redis'] . '」，但这个 Slim 应用没有 PSR-11 容器。'
                . '请改用 AppFactory::create($responseFactory, $container) 传容器，或直接给客户端实例。'
            );
        }

        $options['redis'] = $container->get($options['redis']);

        return $options;
    }

    /**
     * 路由处理器：调用内核控制器，并把结果落成真 PSR-7（唯一的响应转换点，见 Response 类注释）。
     *
     * 同 middleware：不能是 static 闭包 —— 它是交给 Slim 的路由回调，有容器时同样会被 bindTo()。
     */
    private static function handler(Closure $convert, callable $call): Closure
    {
        return function ($request, $response, array $args = []) use ($convert, $call) {
            return $convert($call($args));
        };
    }

    /** 中间件项可以是 PSR-15 实例、容器里的服务名或可调用对象，Route::add() 三种都收 */
    private static function addMiddleware(RouteInterface $route, $middleware): void
    {
        foreach ( (array)$middleware as $one ) {
            $route->add($one);
        }
    }

    /**
     * 转换器：内核产物（可变 shim）→ 真 PSR-7。
     *
     * 必须是这一步、不能放进中间件：Slim 的 Route::run() 声明了 `: ResponseInterface`（strict_types），
     * shim 一旦从路由回调返回就会 TypeError。
     */
    private static function convert(App $app, array $options): Closure
    {
        // 响应工厂取 Slim 自己那个：内核产物用的状态码/响应头都由它落成，与宿主其它响应的行为一致
        $responseFactory = $app->getResponseFactory();
        $streamFactory = isset($options['stream_factory']) && $options['stream_factory'] instanceof StreamFactoryInterface
            ? $options['stream_factory']
            : self::detectStreamFactory();

        return static function ($result) use ($responseFactory, $streamFactory) {
            if ( ! $result instanceof Response ) {
                return $result;
            }

            return $result->toPsr7($responseFactory, $streamFactory);
        };
    }

    /**
     * 默认的 PSR-17 流工厂：slim/psr7 是 slim/slim 的标准搭档（AppFactory 的探测顺序里也排第一）。
     * 换 nyholm/psr7 等实现时用 stream_factory 选项显式传入；都没有则响应体走分块拷贝这条安全路径。
     */
    private static function detectStreamFactory(): ?StreamFactoryInterface
    {
        $class = 'Slim\Psr7\Factory\StreamFactory';

        return class_exists($class) ? new $class() : null;
    }
}
