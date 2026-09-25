<?php

/**
 * Laravel 端到端测试的 bootstrap。
 *
 * 用法（推荐）：bash tests/Integration/laravel/ci.sh
 * 手动跑（工作目录已装配好时）：cd $AETHERA_LARAVEL_WORK && vendor/bin/phpunit -c <仓库>/tests/Integration/laravel/phpunit.xml
 *
 * 这里只做两件事：接上宿主应用的自动加载器，把宿主应用根指给 testbench。
 * 骨架复制与 composer 装配都在 ci.sh 里（那是入口，本地与 CI 同一条路）。
 */

// 先显式引入：工作目录未装配时下面这行之后才 require autoload.php，
// 那时 assertPrepared() 已经能给出「请先跑 ci.sh」的人话错误，而不是一句 file not found。
require_once __DIR__ . '/Harness.php';

\AetherUpload\Tests\Integration\Laravel\Harness::assertPrepared();

$work = \AetherUpload\Tests\Integration\Laravel\Harness::workRoot();

// 宿主应用自己的 autoloader（内含 path 仓库装进来的本包，以及 AetherUpload\Tests\* 的 psr-4 映射）
require $work . '/vendor/autoload.php';

// testbench 从这里取应用根（Orchestra\Testbench\Concerns\InteractsWithWorkbench::applicationBasePathUsingWorkbench）。
// 指向 <work>/app 而不是 vendor/orchestra/testbench-core/laravel：
// vendor:publish 的产物与上传落盘都发生在工作目录里，不写进 vendor/，也不污染仓库。
$_ENV['TESTBENCH_APP_BASE_PATH'] = \AetherUpload\Tests\Integration\Laravel\Harness::appRoot();
