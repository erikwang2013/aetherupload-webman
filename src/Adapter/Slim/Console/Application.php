<?php

namespace AetherUpload\Adapter\Slim\Console;

use AetherUpload\Console\Application as BaseApplication;

/**
 * Slim 的控制台入口（四条命令：aetherupload:build / :clean / :groups / :publish）。
 *
 * 实现整个搬到了内核侧的 `AetherUpload\Console\Application` —— 原生 PHP 适配器要的是同一份东西，
 * 而它本身跟 Slim 一点关系都没有（只依赖 symfony/console 与 Runtime）。本类保留下来只为不破坏
 * 已有入口脚本的写法：
 *
 *     // bin/aetherupload
 *     \AetherUpload\Adapter\Slim\Bootstrap::bind(require __DIR__ . '/../config/aetherupload.php', dirname(__DIR__));
 *     exit((new \AetherUpload\Adapter\Slim\Console\Application())->run());
 *
 * @see BaseApplication
 */
class Application extends BaseApplication
{
}
