<?php

/**
 * Yii 端到端测试的 bootstrap。
 *
 * 用法（推荐）：bash tests/Integration/yii/ci.sh
 * 手动跑（工作目录已装配好时）：
 *     cd ${AETHERA_YII_WORK:-/tmp/aetherupload-yii-e2e}
 *     vendor/bin/phpunit -c <仓库>/tests/Integration/yii/phpunit.xml
 *
 * 这里只做一件事：接上宿主应用的自动加载器 —— 它内含 path 仓库装进来的本包
 * （AetherUpload\ → src/），以及 AetherUpload\Tests\* 与 AetherUpload\Tests\Integration\Yii\* 的映射。
 * 骨架与依赖装配都在 ci.sh 里（那是入口，本地与 CI 同一条路）。
 */

// 先显式引入：工作目录未装配时，assertPrepared() 才能给出「请先跑 ci.sh」的人话，
// 而不是一句 file not found。
require_once __DIR__ . '/Harness.php';

\AetherUpload\Tests\Integration\Yii\Harness::assertPrepared();

$work = \AetherUpload\Tests\Integration\Yii\Harness::workRoot();

require $work . '/vendor/autoload.php';

// Yii.php 里是全局的 class Yii（yii\BaseYii 的子类），不在 psr-4 映射里 ——
// 真实应用的入口脚本也是显式 require 它。不加载它，yii\base\Application 一构造就
// 「Class "Yii" not found」。
require $work . '/vendor/yiisoft/yii2/Yii.php';
