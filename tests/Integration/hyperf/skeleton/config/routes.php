<?php

declare(strict_types=1);

use Hyperf\HttpServer\Router\Router;

// 宿主应用自己的路由；本插件的四条路由由 AetherUpload\Adapter\Hyperf\ConfigProvider 注册，
// 两者共存是这份骨架顺带要验证的事：插件的注册方式不该要求宿主让出整张路由表。
//
// 应答里带上服务端日期与日期时区：就绪探测用它，跨时区导致的 groupSubDir 漂移也能一眼看出来
Router::get('/aetherupload-e2e-app-route', static function () {
    return 'app-route-ok|' . date('Y-m-d H:i:s') . '|' . date_default_timezone_get();
});
