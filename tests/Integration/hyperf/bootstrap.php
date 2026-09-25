<?php

/**
 * Hyperf 端到端测试的 bootstrap：真装骨架、真起 swoole 服务，跑完自动收工。
 *
 * 用法（仓库根目录）：vendor/bin/phpunit -c tests/Integration/hyperf/phpunit.xml
 * 排错时用 AETHERA_E2E_KEEP=1 保留服务与临时应用。
 *
 * 注意：容器/协程隔离的那条用例（CoroutineIsolationTest）不依赖这个 bootstrap 起的服务 ——
 * 它在测试进程里直接 Co\run 起协程，见该文件顶部的说明。
 */

require __DIR__ . '/../../../vendor/autoload.php';
// 目录名是小写 hyperf，与命名空间大小写不一致，PSR-4 自动加载找不到，这里显式引入
require_once __DIR__ . '/App.php';

use AetherUpload\Tests\Integration\Hyperf\App;

App::ensure();
App::start();

register_shutdown_function(static function () {
    if ( getenv('AETHERA_E2E_KEEP') !== '1' ) {
        App::stop();
    }
});
