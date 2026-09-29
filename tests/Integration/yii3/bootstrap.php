<?php

/**
 * Yii3 端到端测试的 bootstrap：真装 yiisoft 骨架（router + router-fastroute + yii-runner-http
 * + yii-runner-console + yii-console + httpsoft/http-message + predis + 本包），
 * 并生成真实的配置组、路由文件、入口脚本与 bin/yii。
 *
 * 用法（仓库根目录）：vendor/bin/phpunit -c tests/Integration/yii3/phpunit.xml --no-coverage
 */

require __DIR__ . '/../../../vendor/autoload.php';
// 目录名是小写 yii3，与命名空间大小写不一致，PSR-4 自动加载找不到，这里显式引入
require_once __DIR__ . '/App.php';

use AetherUpload\Tests\Integration\Yii3\App;

App::ensure();
