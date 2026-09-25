<?php

declare(strict_types=1);

return [
    // 端到端应用全部路由与命令都由 ConfigProvider / config/routes.php 显式注册，
    // 不做注解扫描（这也正是本插件 ConfigProvider 不用注解的原因：不该要求宿主把包目录写进 scan.paths）
    'scan' => [
        'paths' => [],
        'ignore_annotations' => [
            'mixin',
        ],
    ],
];
