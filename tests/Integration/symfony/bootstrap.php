<?php

/**
 * Symfony 端到端测试的 bootstrap：真装一个 Symfony 应用，再把该应用的 autoloader 挂上，
 * 让 WebTestCase 能按 KERNEL_CLASS 启动它。
 *
 * 用法（仓库根目录）：vendor/bin/phpunit -c tests/Integration/symfony/phpunit.xml
 * 排错时用 AETHERA_E2E_APP=<目录> 指定一个固定的应用目录反复看；AETHERA_E2E_REDIS=0 强制关掉秒传。
 */

require __DIR__ . '/../../../vendor/autoload.php';
// 目录名是小写 symfony，与命名空间大小写不一致，PSR-4 自动加载找不到，这里显式引入
require_once __DIR__ . '/App.php';

use AetherUpload\Tests\Integration\Symfony\App;

App::ensure();

// 应用自己的 autoloader：App\Kernel 与 Symfony 组件都来自这里。
// composer 的 loader 是 prepend 注册的，所以后注册的优先 —— 应用这边盖住仓库的 dev 依赖
require App::root() . '/vendor/autoload.php';

// WebTestCase 按这两个变量构造内核：类名必须是应用里的 App\Kernel
$_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = 'test';
$_ENV['APP_DEBUG'] = $_SERVER['APP_DEBUG'] = (bool) getenv('AETHERA_E2E_DEBUG');
$_ENV['KERNEL_CLASS'] = $_SERVER['KERNEL_CLASS'] = 'App\Kernel';
