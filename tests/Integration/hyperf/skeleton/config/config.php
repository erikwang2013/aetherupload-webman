<?php

declare(strict_types=1);

use Hyperf\Contract\StdoutLoggerInterface;
use Psr\Log\LogLevel;

// 本插件的接入点。
//
// 真实宿主的标准做法是让 composer 自动发现：本包的 composer.json 里写
//   "extra": { "hyperf": { "config": "AetherUpload\\Adapter\\Hyperf\\ConfigProvider" } }
// Composer::getMergedExtra('hyperf') 读的是 composer.lock 里各**已安装包**的 extra，
// 而本仓库根 composer.json 不在本次交付可改范围内，所以 E2E 在这里显式展开 __invoke() 的返回值：
// 路由监听器、四个命令、publish 元数据都还是由 ConfigProvider 那一份代码给出，不是另抄一遍。
$provider = (new \AetherUpload\Adapter\Hyperf\ConfigProvider())();

return array_merge($provider, [

    'app_name' => 'aetherupload-e2e',
    'scan_cacheable' => false,

    // 只留 ERROR 以上：事件探针抛出的异常会被 HyperfEvents 记到这里，
    // 排查端到端失败时 App.php 会把 stdout 日志尾部一并打出来
    StdoutLoggerInterface::class => [
        'log_level' => [
            LogLevel::ERROR,
            LogLevel::CRITICAL,
            LogLevel::ALERT,
            LogLevel::EMERGENCY,
        ],
    ],
]);
