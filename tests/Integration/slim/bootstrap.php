<?php

/**
 * Slim 端到端测试的 bootstrap：真装骨架（slim/slim + slim/psr7 + predis + symfony/console + 本包），
 * 并生成与 public/index.php 同一份的应用构建代码。
 *
 * 用法（仓库根目录）：vendor/bin/phpunit -c tests/Integration/slim/phpunit.xml --no-coverage
 */

require __DIR__ . '/../../../vendor/autoload.php';
// 目录名是小写 slim，与命名空间大小写不一致，PSR-4 自动加载找不到，这里显式引入
require_once __DIR__ . '/App.php';

use AetherUpload\Tests\Integration\Slim\App;

App::ensure();
