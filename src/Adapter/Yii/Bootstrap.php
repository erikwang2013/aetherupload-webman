<?php

namespace AetherUpload\Adapter\Yii;

use AetherUpload\Runtime;
use yii\base\BootstrapInterface;

/**
 * Yii 适配器的唯一入口。宿主只要在应用配置里加一行：
 *
 *   'bootstrap' => [
 *       'aetherupload' => \AetherUpload\Adapter\Yii\Bootstrap::class,
 *   ],
 *
 * 以及一条前置条件：urlManager 必须开着 enablePrettyUrl（否则路径参数根本解析不出来）。
 *
 * 为什么必须是 bootstrap 而不是某个控制器的 init()：四条路由规则本身就是从配置
 * （route_preprocess 等逻辑键）读出来的，而 ConfigMapper/Runtime 的所有读取都要求
 * 适配器已绑定 —— 绑定必须早于任何业务代码，bootstrap 正是那个时机
 * （Application::bootstrap() 里，组件已就绪、请求尚未分发）。
 */
class Bootstrap implements BootstrapInterface
{
    /** 控制器 id：四条业务路由都指向它，控制台命令也用它 */
    const CONTROLLER_ID = 'aetherupload';

    /**
     * @param \yii\base\Application $app
     */
    public function bootstrap($app)
    {
        // 每次 bootstrap 都换一个新适配器实例：端口内部状态（已登记的翻译目录等）
        // 跟着上下文走，不跨应用实例泄漏。
        Runtime::bind(new YiiAdapter());

        if ( $app instanceof \yii\web\Application ) {
            $this->registerRoutes($app);
        } elseif ( $app instanceof \yii\console\Application ) {
            // 控制台命令：aetherupload/groups、aetherupload/build、aetherupload/clean、aetherupload/publish
            $app->controllerMap[self::CONTROLLER_ID] = Console\AetherUploadController::class;
        }
    }

    /**
     * 注册四条业务路由。参数语法是 `<uri>`（Yii 的占位符语法），不是 webman 的 `{uri}`。
     *
     * 路径一律取自配置的逻辑键，与另外五个框架保持一致；因此这条 addRules() 与
     * config/aetherupload.php 是一对，改路由要两边同步（前端也要跟着改）。
     */
    private function registerRoutes(\yii\web\Application $app): void
    {
        $config = Runtime::config();

        $app->controllerMap[self::CONTROLLER_ID] = AetherUploadController::class;

        $app->getUrlManager()->addRules([
            [
                'pattern' => self::pattern($config->get('route_preprocess')),
                'route'   => self::CONTROLLER_ID . '/preprocess',
                'verb'    => ['POST'],
            ],
            [
                'pattern' => self::pattern($config->get('route_uploading')),
                'route'   => self::CONTROLLER_ID . '/uploading',
                'verb'    => ['POST'],
            ],
            [
                'pattern' => self::pattern($config->get('route_display')) . '/<uri>',
                'route'   => self::CONTROLLER_ID . '/display',
                'verb'    => ['GET'],
            ],
            [
                'pattern' => self::pattern($config->get('route_download')) . '/<uri>/<newName>',
                'route'   => self::CONTROLLER_ID . '/download',
                'verb'    => ['GET'],
            ],
        ]);
    }

    /** 配置里写的是 '/aetherupload/display' 这种带前导斜杠的路径，urlManager 的 pattern 不能带 */
    private function pattern($route): string
    {
        return trim((string)$route, '/');
    }
}
