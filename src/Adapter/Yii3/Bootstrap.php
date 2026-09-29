<?php

namespace AetherUpload\Adapter\Yii3;

use AetherUpload\Runtime;
use Yiisoft\Router\Route;

/**
 * Yii3 的唯一入口：三处显式调用，没有自动装配、没有全局函数可用。
 *
 *   1) 入口脚本（`public/index.php`，**在 HttpApplicationRunner 之前**；控制台入口 `yii` 同样）：
 *
 *        \AetherUpload\Adapter\Yii3\Bootstrap::bind(
 *            require __DIR__ . '/../config/aetherupload.php',
 *            $root = dirname(__DIR__),
 *            ['redis' => static fn () => new \Predis\Client(['database' => 0])]
 *        );
 *
 *   2) `config/common/routes.php`（四条业务路由）：
 *
 *        return [
 *            // …宿主自己的路由…
 *            ...\AetherUpload\Adapter\Yii3\Bootstrap::routes(require __DIR__ . '/aetherupload.php'),
 *        ];
 *
 *   3) `config/common/params.php`（控制台命令）：
 *
 *        'yiisoft/yii-console' => ['commands' => \AetherUpload\Adapter\Yii3\Bootstrap::consoleCommands()],
 *
 * **宿主不需要改中间件栈**：四条路由的 action 自己拿得到当前 PSR-7 请求
 * （yiisoft/middleware-dispatcher 按类型注入 `ServerRequestInterface`），适配器在 action 里登记与清理。
 *
 * 为什么在入口脚本里绑定而不是走 `config/common/bootstrap.php`：那类回调在容器构建之后才执行，
 * 而本包的路由 pattern 来自配置、路由文件在容器构建期就被 require 了 —— 绑定点必须更早，
 * 入口脚本正是那个位置（与其它宿主「启动点显式 bind」的约定一致）。
 */
class Bootstrap
{
    /**
     * 绑定适配器，返回它。
     *
     * @param array<string,mixed> $config   逻辑键配置；顶层覆盖包内默认值
     * @param string              $basePath 应用根目录（Yii3 下就是 `$root = dirname(__DIR__)`）
     * @param array<string,mixed> $options  见 Yii3Adapter 类注释（redis / listeners / dispatcher / logger / …）
     */
    public static function bind(array $config = [], string $basePath = '', array $options = []): Yii3Adapter
    {
        $adapter = new Yii3Adapter($config, $basePath, $options);

        Runtime::bind($adapter);

        return $adapter;
    }

    /**
     * 四条业务路由，供宿主展开进自己的路由数组。
     *
     * 配置是**显式入参**（省略则用包内 config/aetherupload.php 的默认值），刻意不读 `Runtime::config()`：
     * 路由文件在容器构建期被 require，那一刻 `Runtime` 还没绑定（绑定点是入口脚本，晚于配置读取），
     * 读 Runtime 必然抛「尚未绑定宿主适配器」。
     *
     * 配置里的 `middleware_*` 在此挂到对应路由上，值是 yiisoft/router 认识的定义形态
     * （PSR-15 中间件类名、实例，或 `function ($request, $handler): ResponseInterface` 可调用对象）。
     *
     * @param array<string,mixed> $config
     *
     * @return Route[] 元素是 yiisoft/router 的 Route；顺序与其余适配器一致（preprocess 在前）
     */
    public static function routes(array $config = []): array
    {
        // 借适配器读配置：合并规则（顶层覆盖包内默认值）与点号取值口径必须与真正绑定的那份**同源**，
        // 否则「路由 pattern 读出来的值」与「内核运行时读到的值」会悄悄分叉
        $config = (new Yii3Adapter($config))->config();

        $preprocess = Route::post((string)$config->get('route_preprocess'))
            ->name('aetherupload/preprocess')
            ->action([AetherUploadController::class, 'preprocess']);
        self::attachMiddleware($preprocess, $config->get('middleware_preprocess'));

        $uploading = Route::post((string)$config->get('route_uploading'))
            ->name('aetherupload/uploading')
            ->action([AetherUploadController::class, 'uploading']);
        self::attachMiddleware($uploading, $config->get('middleware_uploading'));

        // uri 是**一段**：savedPath 用下划线编码三段，本身不含 /
        $display = Route::get(rtrim((string)$config->get('route_display'), '/') . '/{uri}')
            ->name('aetherupload/display')
            ->action([AetherUploadController::class, 'display']);
        self::attachMiddleware($display, $config->get('middleware_display'));

        $download = Route::get(rtrim((string)$config->get('route_download'), '/') . '/{uri}/{newName}')
            ->name('aetherupload/download')
            ->action([AetherUploadController::class, 'download']);
        self::attachMiddleware($download, $config->get('middleware_download'));

        return [$preprocess, $uploading, $display, $download];
    }

    /**
     * 控制台命令表（命令名 => 命令类），直接交给 yiisoft/yii-console 的 params：
     *
     *     'yiisoft/yii-console' => ['commands' => Bootstrap::consoleCommands()],
     *
     * 命令名用 `aetherupload/xxx` 而不是 `aetherupload:xxx`：Yii3 的命令名沿用 Yii2 的斜杠约定
     * （yiisoft/yii-console 建在 Symfony Console 之上，名字本身合法即可，但同一族的框架用同一种写法更省心）。
     *
     * @return array<string,class-string>
     */
    public static function consoleCommands(): array
    {
        return [
            'aetherupload/groups' => Command\GroupsCommand::class,
            'aetherupload/build'  => Command\BuildRedisHashesCommand::class,
            'aetherupload/clean'  => Command\CleanUpDirectoryCommand::class,
            'aetherupload/publish' => Command\PublishCommand::class,
        ];
    }

    /**
     * 把配置里的中间件挂到路由上。值可以是单个，也可以是数组（与其它适配器的 middleware_* 同形）。
     *
     * @param mixed $middleware
     */
    private static function attachMiddleware(Route $route, $middleware): void
    {
        foreach ( (array)$middleware as $one ) {
            $route->middleware($one);
        }
    }
}
