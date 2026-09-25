<?php

/**
 * 插件的四条业务路由。
 *
 * 应用侧在 config/routes.yaml 里 import 本文件（type: php）：
 *
 *     aetherupload:
 *         resource: '@AetherUploadBundle/Resources/config/routes.php'
 *         type: php
 *
 * 路径取自配置（%aetherupload.route_*% 是容器参数，Router 会解析路径里的占位符），
 * 与 webman 版的 config/route.php 一一对应，改配置即可改这四个地址。
 *
 * 中间件（middleware_preprocess/uploading/display/download）：Symfony 没有路由级中间件，
 * 内核读取的这四个键在 Symfony 侧不对应任何机制，需要时用 kernel.event_listener +
 * RequestMatcher 按路由名（aetherupload_preprocess 等）挂监听器即可。
 */

use AetherUpload\ResourceController;
use AetherUpload\UploadController;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

return static function (RoutingConfigurator $routes): void {

    $routes->add('aetherupload_preprocess', '%aetherupload.route_preprocess%')
        ->controller(UploadController::class . '::preprocess')
        ->methods(['POST']);

    $routes->add('aetherupload_uploading', '%aetherupload.route_uploading%')
        ->controller(UploadController::class . '::saveChunk')
        ->methods(['POST']);

    $routes->add('aetherupload_display', '%aetherupload.route_display%/{uri}')
        ->controller(ResourceController::class . '::display')
        ->methods(['GET']);

    $routes->add('aetherupload_download', '%aetherupload.route_download%/{uri}/{newName}')
        ->controller(ResourceController::class . '::download')
        ->methods(['GET']);
};
