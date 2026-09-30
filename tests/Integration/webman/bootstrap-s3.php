<?php

/**
 * S3 端到端（webman + 真 S3 兼容服务端）的 bootstrap。
 *
 * 用法（仓库根目录）：vendor/bin/phpunit -c tests/Integration/webman/phpunit-s3.xml
 * 顺序：确保 S3 服务端（有就复用，没有再按 env → 缓存二进制 → docker 镜像解析）→ 真装骨架
 * （app-setup.php 会显式写入 s3 存储配置）→ 真起 HTTP 服务。
 *
 * 收尾：只停本次起的服务端（pid 记在缓存目录；外部端点/别人起的服务端不动），
 * AETHERA_E2E_KEEP=1 则全部保留供排查。
 */

require __DIR__ . '/../../../vendor/autoload.php';
// 目录名是小写 webman，与命名空间大小写不一致，PSR-4 自动加载找不到，这里显式引入
require_once __DIR__ . '/App.php';
require_once __DIR__ . '/WebmanHttp.php';

use AetherUpload\Tests\Integration\Webman\App;

// 服务端先就位再起应用没坏处；一个都找不到时不在这里抛，留给用例 markTestSkipped（如实报告）
App::ensureS3Server();

App::ensure();
App::start();

register_shutdown_function(static function () {
    if ( getenv('AETHERA_E2E_KEEP') !== '1' ) {
        // 各自兜住异常：停应用失败不能连带把服务端的收尾也跳过（会漏一个常驻进程）
        try {
            App::stop();
        } catch ( \Throwable $e ) {
            fwrite(STDERR, '[E2E] stop 失败：' . $e->getMessage() . "\n");
        }

        try {
            App::stopS3Server();
        } catch ( \Throwable $e ) {
            fwrite(STDERR, '[E2E] stopS3Server 失败：' . $e->getMessage() . "\n");
        }
    }
});
