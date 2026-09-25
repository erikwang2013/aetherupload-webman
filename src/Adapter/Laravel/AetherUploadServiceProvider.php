<?php

namespace AetherUpload\Adapter\Laravel;

use AetherUpload\Adapter\Laravel\Console\BuildRedisHashesCommand;
use AetherUpload\Adapter\Laravel\Console\CleanUpDirectoryCommand;
use AetherUpload\Adapter\Laravel\Console\ListGroupsCommand;
use AetherUpload\ConfigMapper;
use AetherUpload\ResourceController;
use AetherUpload\Runtime;
use AetherUpload\UploadController;
use Illuminate\Support\ServiceProvider;

/**
 * Laravel 接入点。
 *
 * 注册方式（本包 composer.json 里没有 extra.laravel.providers，无法自动发现）：
 * 在应用的 bootstrap/providers.php（Laravel 11+）或 config/app.php 的 providers 数组里加上
 * AetherUpload\Adapter\Laravel\AetherUploadServiceProvider::class。
 *
 * 装好之后：
 *   php artisan vendor:publish --tag=aetherupload-config        发布 config/aetherupload.php
 *   php artisan vendor:publish --tag=aetherupload-translations  发布语言文件到 lang/aetherupload/
 *   php artisan vendor:publish --tag=aetherupload-assets        发布前端 js 到 public/vendor/aetherupload/js
 *   php artisan aetherupload:groups | aetherupload:build | aetherupload:clean
 *
 * aetherupload:groups 是**必做**的一步：webman 靠 composer require 时的 Install::install() 建分组目录，
 * Laravel 没有这一步，不建目录的话每次 preprocess 都会失败（create_subfolder_fail）。
 *
 * 配置合并是**浅合并**（Laravel 的 mergeConfigFrom 语义）：应用一旦发布了
 * config/aetherupload.php，文件里的 groups 会**整体替换**插件默认值，
 * 不是逐组叠加 —— 新增分组时请把默认分组一并抄进去。
 */
class AetherUploadServiceProvider extends ServiceProvider
{
    /** 发布标签前缀，三个 publishes 共用 */
    const PUBLISH_TAG_PREFIX = 'aetherupload-';

    public function register(): void
    {
        $this->mergeConfigFrom($this->packagePath('config/aetherupload.php'), 'aetherupload');

        // 与 webman 的 config/route.php 首行、tests/bootstrap.php 同理：
        // 宿主启动点显式绑定一次，内核不做任何框架探测。
        Runtime::bind(new LaravelAdapter());
    }

    public function boot(): void
    {
        $this->registerRoutes();
        $this->registerPublishes();
        $this->registerCommands();
    }

    /**
     * 四条业务路由。
     *
     * 控制器的 display()/download() 只接收路由变量（请求一律经 Runtime::request() 取），
     * 因此 Route::get 的 {uri}/{newName} 直接对应它们的形参。
     */
    private function registerRoutes(): void
    {
        $router = $this->app['router'];

        $router->post(ConfigMapper::get('route_preprocess'), [UploadController::class, 'preprocess'])
            ->middleware((array)ConfigMapper::get('middleware_preprocess'));

        $router->post(ConfigMapper::get('route_uploading'), [UploadController::class, 'saveChunk'])
            ->middleware((array)ConfigMapper::get('middleware_uploading'));

        $router->get(ConfigMapper::get('route_display') . '/{uri}', [ResourceController::class, 'display'])
            ->middleware((array)ConfigMapper::get('middleware_display'));

        $router->get(ConfigMapper::get('route_download') . '/{uri}/{newName}', [ResourceController::class, 'download'])
            ->middleware((array)ConfigMapper::get('middleware_download'));
    }

    private function registerPublishes(): void
    {
        if ( ! $this->app->runningInConsole() ) {
            return;
        }

        $this->publishes(
            [$this->packagePath('config/aetherupload.php') => config_path('aetherupload.php')],
            self::PUBLISH_TAG_PREFIX . 'config'
        );

        // 内核在 translationsPath()/aetherupload/<locale>/messages.php 找语言文件，
        // 所以这里必须落到 lang/aetherupload 而不是 Laravel 惯例的 lang/vendor/aetherupload
        $this->publishes(
            [$this->packagePath('translations') => lang_path('aetherupload')],
            self::PUBLISH_TAG_PREFIX . 'translations'
        );

        $this->publishes(
            [$this->packagePath('docs/js') => public_path('vendor/aetherupload/js')],
            self::PUBLISH_TAG_PREFIX . 'assets'
        );
    }

    private function registerCommands(): void
    {
        if ( ! $this->app->runningInConsole() ) {
            return;
        }

        $this->commands([
            BuildRedisHashesCommand::class,
            CleanUpDirectoryCommand::class,
            ListGroupsCommand::class,
        ]);
    }

    private function packagePath(string $relative): string
    {
        return dirname(__DIR__, 3) . '/' . ltrim($relative, '/');
    }
}
