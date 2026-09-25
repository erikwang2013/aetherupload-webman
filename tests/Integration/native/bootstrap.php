<?php

/**
 * 原生 PHP 端到端测试的 bootstrap：准备好最小应用、起 php -S，跑完自动收工。
 *
 * 用法（仓库根目录）：vendor/bin/phpunit -c tests/Integration/native/phpunit.xml
 * 排错时用 AETHERA_E2E_KEEP=1 保留服务与临时应用。
 */

require __DIR__ . '/../../../vendor/autoload.php';
// 目录名是小写 native，与命名空间大小写不一致，PSR-4 自动加载找不到，这里显式引入
require_once __DIR__ . '/App.php';

use AetherUpload\Tests\Integration\Native\App;

App::ensure();
App::start();

register_shutdown_function(static function () {
    if ( getenv('AETHERA_E2E_KEEP') !== '1' ) {
        App::stop();
    }
});
