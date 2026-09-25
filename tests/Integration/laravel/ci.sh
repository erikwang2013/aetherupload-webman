#!/usr/bin/env bash
#
# Laravel 适配器的端到端测试入口（本地与 CI 共用同一条路）。
#
#   bash tests/Integration/laravel/ci.sh
#   bash tests/Integration/laravel/ci.sh --filter testInstantCompletion   # 多余的参数透传给 phpunit
#
# 三步：
#   1) 在临时工作目录装真 Laravel（laravel/framework + orchestra/testbench + predis），
#      本仓库以 path 仓库 symlink 的方式装进去 —— 测的是当前工作区，不是发布版。
#   2) 把 testbench 自带的最小 Laravel 骨架复制成宿主应用根。<work>/app 就是被测应用，
#      vendor:publish 的产物与上传落盘都在它下面，不写进 vendor/，也不污染仓库。
#   3) 跑 tests/Integration/laravel/LaravelFlowTest.php。
#
# 环境变量：
#   AETHERA_LARAVEL_WORK           工作目录（默认 ${TMPDIR:-/tmp}/aetherupload-laravel-e2e）
#   AETHERA_E2E_REDIS=0            强制视为无 redis（第 8 条秒传跳过）
#   AETHERA_E2E_REDIS_HOST/_PORT/_DB   redis 连接参数（默认 127.0.0.1:6379 db 4，Laravel 专属库）
#   AETHERA_LARAVEL_REFRESH=1      强制重装依赖（默认已装配就直接复用）
#
# 要求 PHP >= 8.2（Laravel 11 的硬要求；内核本身仍兼容 8.0）。
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
WORK="${AETHERA_LARAVEL_WORK:-${TMPDIR:-/tmp}/aetherupload-laravel-e2e}"
APP="$WORK/app"

echo "==> 仓库：$REPO_ROOT"
echo "==> 工作目录：$WORK"

php -r 'exit(PHP_VERSION_ID >= 80200 ? 0 : 1);' || {
    echo "错误：Laravel 11 要求 PHP >= 8.2，当前是 $(php -r 'echo PHP_VERSION;')" >&2
    exit 1
}

mkdir -p "$WORK"

# ---------------------------------------------------------------- 1. 宿主应用清单
#
# 每次都用 heredoc 生成 composer.json：仓库根是绝对路径，只能在这里插值。
#
# 本包根 composer.json 刻意没有 extra.laravel.providers（六框架依赖互相冲突，不能写死某一个框架的
# 自动发现），所以宿主必须显式注册 ServiceProvider —— 见
# src/Adapter/Laravel/AetherUploadServiceProvider.php 的类注释。
cat > "$WORK/composer.json" <<JSON
{
    "name": "aetherupload/laravel-e2e",
    "description": "Laravel 端到端测试的宿主应用（由 tests/Integration/laravel/ci.sh 生成，请勿手工修改）",
    "type": "project",
    "license": "MIT",
    "require": {
        "php": "^8.2",
        "erikwang2013/aetherupload-webman": "@dev",
        "laravel/framework": "^11.0",
        "orchestra/testbench": "^9.0",
        "predis/predis": "^2.2"
    },
    "require-dev": {
        "phpunit/phpunit": "^10.5"
    },
    "repositories": [
        { "type": "path", "url": "$REPO_ROOT", "options": { "symlink": true } }
    ],
    "autoload-dev": {
        "psr-4": {
            "AetherUpload\\\\Tests\\\\Integration\\\\Laravel\\\\": "$REPO_ROOT/tests/Integration/laravel/",
            "AetherUpload\\\\Tests\\\\": "$REPO_ROOT/tests/"
        }
    },
    "minimum-stability": "dev",
    "prefer-stable": true,
    "config": {
        "allow-plugins": { "*": true }
    }
}
JSON

if [ "${AETHERA_LARAVEL_REFRESH:-0}" = "1" ]; then
    rm -rf "$WORK/vendor" "$WORK/composer.lock" "$APP"
fi

if [ ! -d "$WORK/vendor" ]; then
    echo "==> 装宿主依赖（laravel/framework + orchestra/testbench + predis）"
    if [ -f "$WORK/composer.lock" ]; then
        ( cd "$WORK" && composer install --no-interaction --no-progress )
    else
        ( cd "$WORK" && composer update --no-interaction --no-progress )
    fi
else
    echo "==> 复用已装的宿主依赖（AETHERA_LARAVEL_REFRESH=1 可强制重装）"
fi

# ------------------------------------------------------------------ 2. 宿主应用骨架
if [ ! -f "$APP/bootstrap/app.php" ]; then
    echo "==> 复制 testbench 骨架为宿主应用根：$APP"
    rm -rf "$APP"
    cp -a "$WORK/vendor/orchestra/testbench-core/laravel" "$APP"
fi

# ------------------------------------------------------------------------ 3. 跑
echo "==> 跑 Laravel 端到端测试"
cd "$WORK"
exec "$WORK/vendor/bin/phpunit" -c "$REPO_ROOT/tests/Integration/laravel/phpunit.xml" --no-coverage "$@"
