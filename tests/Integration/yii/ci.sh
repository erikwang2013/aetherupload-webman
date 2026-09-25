#!/usr/bin/env bash
#
# Yii2 适配器的端到端测试入口（本地与 CI 共用同一条路）。
#
#   bash tests/Integration/yii/ci.sh
#   bash tests/Integration/yii/ci.sh --filter testInstantCompletion   # 多余的参数透传给 phpunit
#
# 三步：
#   1) 在临时工作目录装真 yiisoft/yii2 + yii2-redis。本仓库以 path 仓库 symlink 的方式装进去 ——
#      测的是当前工作区，不是发布版。yii2 声明了 bower-asset/* 依赖，因此必须加 asset-packagist
#      仓库，否则 composer 直接解析失败。
#   2) 造一个最小宿主应用骨架（<work>/app）：一个真入口脚本（Yii 靠它的路径推导 @webroot/@web）
#      与 runtime/。上传落盘、aetherupload:publish 的产物都在它下面，不写进 vendor/，也不污染仓库。
#   3) 跑 tests/Integration/yii/YiiFlowTest.php。
#
# 环境变量：
#   AETHERA_YII_WORK                  工作目录（默认 ${TMPDIR:-/tmp}/aetherupload-yii-e2e）
#   AETHERA_E2E_REDIS=0               强制视为无 redis（第 8 条秒传跳过）
#   AETHERA_E2E_REDIS_HOST/_PORT/_DB  redis 连接参数（默认 127.0.0.1:6379 db 9 —— 六框架并行跑测试时分库，
#                                     避免各自的 flush 互相破坏：webman 7 · laravel 4 · thinkphp 5 · hyperf 3 · slim 6 · symfony 8 · yii 9）
#   AETHERA_YII_REFRESH=1             强制重装依赖（默认已装配就直接复用）
#
# 要求：PHP >= 8.0（适配器代码 PHP 8.0 语法 + yii2 2.0.55 都能跑）、composer；redis 可选。
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
WORK="${AETHERA_YII_WORK:-${TMPDIR:-/tmp}/aetherupload-yii-e2e}"
APP="$WORK/app"

echo "==> 仓库：$REPO_ROOT"
echo "==> 工作目录：$WORK"

php -r 'exit(PHP_VERSION_ID >= 80000 ? 0 : 1);' || {
    echo "错误：本适配器要求 PHP >= 8.0，当前是 $(php -r 'echo PHP_VERSION;')" >&2
    exit 1
}

mkdir -p "$WORK"

# ---------------------------------------------------------------- 1. 宿主应用清单
#
# 每次都用 heredoc 生成 composer.json：仓库根是绝对路径，只能在这里插值。
cat > "$WORK/composer.json" <<JSON
{
    "name": "aetherupload/yii-e2e",
    "description": "Yii2 端到端测试的宿主应用（由 tests/Integration/yii/ci.sh 生成，请勿手工修改）",
    "type": "project",
    "license": "MIT",
    "require": {
        "php": ">=8.0",
        "erikwang2013/aetherupload-webman": "@dev",
        "yiisoft/yii2": "^2.0.49",
        "yiisoft/yii2-redis": "^2.0"
    },
    "require-dev": {
        "phpunit/phpunit": "^10.5"
    },
    "repositories": [
        { "type": "path", "url": "$REPO_ROOT", "options": { "symlink": true } },
        { "type": "composer", "url": "https://asset-packagist.org" }
    ],
    "autoload-dev": {
        "psr-4": {
            "AetherUpload\\\\Tests\\\\Integration\\\\Yii\\\\": "$REPO_ROOT/tests/Integration/yii/",
            "AetherUpload\\\\Tests\\\\": "$REPO_ROOT/tests/"
        }
    },
    "minimum-stability": "dev",
    "prefer-stable": true,
    "config": {
        "allow-plugins": { "yiisoft/yii2-composer": true }
    }
}
JSON

if [ "${AETHERA_YII_REFRESH:-0}" = "1" ]; then
    rm -rf "$WORK/vendor" "$WORK/composer.lock" "$APP" "$WORK/probe.log"
fi

if [ ! -d "$WORK/vendor" ]; then
    echo "==> 装宿主依赖（yiisoft/yii2 + yii2-redis；asset-packagist 提供 bower-asset/*）"
    if [ -f "$WORK/composer.lock" ]; then
        ( cd "$WORK" && composer install --no-interaction --no-progress )
    else
        ( cd "$WORK" && composer update --no-interaction --no-progress )
    fi
else
    echo "==> 复用已装的宿主依赖（AETHERA_YII_REFRESH=1 可强制重装）"
fi

# ------------------------------------------------------------------ 2. 宿主应用骨架
#
# 骨架刻意只有入口脚本与 runtime/：端到端测试在进程内用数组配置构造 yii\web\Application
# （这与「每请求一个应用实例」的真实生命周期一致，也便于按用例切换 instant_completion）。
# 入口脚本本身不被执行，但必须真实存在 —— 应用 bootstrap 时会用 SCRIPT_FILENAME 推导
# @webroot/@web，路径不存在就会抛 InvalidConfigException。
if [ ! -f "$APP/web/index.php" ]; then
    echo "==> 造宿主应用骨架：$APP"
    mkdir -p "$APP/web" "$APP/runtime" "$APP/config"

    # 宿主入口脚本 + 应用配置。端到端测试不执行它们（改为在进程内构造应用），
    # 但 index.php 必须真实存在 —— Yii 用它的路径推导 @webroot/@web。
    # 顺带让骨架是**真能跑起来**的：`php -S 127.0.0.1:8080 web/router.php` 即可用真 HTTP 打四条路由
    # （config/aetherupload.php 由 aetherupload/publish 生成，见 setUpBeforeClass）。
    cat > "$APP/web/index.php" <<'PHP'
<?php

defined('YII_DEBUG') or define('YII_DEBUG', true);

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require dirname(__DIR__, 2) . '/vendor/yiisoft/yii2/Yii.php';

$config = require __DIR__ . '/../config/web.php';

(new yii\web\Application($config))->run();
PHP

    # 只想用 php -S 手工打一遍时用它，做两件 nginx 会做的事：
    #   1) 静态文件直接发 —— 示例页引用 /vendor/aetherupload/js/aetherupload-all.js 这种绝对路径，
    #      对应磁盘上的 <文档根>/vendor/...（aetherupload/publish 的落点）。返回 false 就是交还给内置服务器。
    #   2) 其余请求还原成前端控制器形态：内置服务器会把 SCRIPT_NAME 设成**请求路径**
    #      （'/aetherupload/preprocess'），Yii 据此推出的 baseUrl 也成了 '/aetherupload'、pathInfo 只剩
    #      'preprocess'，四条路由会全部 404。
    cat > "$APP/web/router.php" <<'PHP'
<?php

$path = (string)parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$file = realpath(__DIR__ . $path);

// realpath 前缀校验：路径里的 ../ 不能把文档根以外的文件（例如 config/aetherupload.php）发出去
if ( $file !== false && is_file($file) && strncmp($file, __DIR__, strlen(__DIR__)) === 0 ) {
    return false;
}

$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['SCRIPT_FILENAME'] = __DIR__ . '/index.php';
$_SERVER['PHP_SELF'] = '/index.php';

require __DIR__ . '/index.php';
PHP

    cat > "$APP/config/web.php" <<'PHP'
<?php

// 宿主应用配置：需要写的就 'bootstrap' 那一行（适配器自己绑 Runtime、注册路由与命令）。
return [
    'id'       => 'aetherupload-yii-e2e',
    'basePath' => dirname(__DIR__),
    'params'   => [
        // aetherupload/publish 生成的样例；未列出的键沿用包内默认值
        'aetherupload' => require __DIR__ . '/aetherupload.php',
    ],
    'bootstrap'  => ['aetherupload' => \AetherUpload\Adapter\Yii\Bootstrap::class],
    'components' => [
        // <uri> 这类路径参数只在 prettyUrl 下解析得出来
        'urlManager' => [
            'enablePrettyUrl'     => true,
            'showScriptName'      => false,
            'enableStrictParsing' => true,
        ],
        'redis' => [
            'class'    => yii\redis\Connection::class,
            'hostname' => '127.0.0.1',
            'port'     => 6379,
            'database' => 9, // 六框架并行跑测试时的分库：webman 7 · laravel 4 · thinkphp 5 · hyperf 3 · slim 6 · symfony 8 · yii 9
        ],
    ],
];
PHP

    touch "$APP/runtime/.gitkeep"
fi

# ------------------------------------------------------------------------ 3. 跑
echo "==> 跑 Yii 端到端测试"
cd "$WORK"
exec "$WORK/vendor/bin/phpunit" -c "$REPO_ROOT/tests/Integration/yii/phpunit.xml" --no-coverage "$@"
