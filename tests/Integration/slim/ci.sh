#!/usr/bin/env bash
#
# Slim 适配器的端到端测试入口（本地与 CI 共用同一条路）。
#
#   bash tests/Integration/slim/ci.sh
#   bash tests/Integration/slim/ci.sh --filter testFullUploadFlow   # 多余参数透传给 phpunit
#
# 与 Laravel/ThinkPHP 的 ci.sh 不同，Slim 这边没有「宿主骨架」可复制：Slim 就是库，
# 应用即 `AppFactory::create()` 那几行。所以临时骨架的生成与依赖安装放在
# tests/Integration/slim/App.php::ensure()（PHP 里），本脚本负责把环境检查清楚再跑。
#
#   1) 装本仓库 dev 依赖（phpunit）——六框架的 E2E 不在根 composer.json 里加依赖
#   2) 跑 tests/Integration/slim/*Test.php：真装 slim/slim + slim/psr7，真跑
#      $app->handle($request) 走完整 Dispatcher + 中间件栈 + 真文件系统
#   3) 贴控制台证据：骨架里的 bin/aetherupload 跑 aetherupload:groups（控制台交付物）
#   4) 重跑 ConfigParityTest：适配器改了配置读取就必须仍然满足配置一致性契约
#
# 环境变量：
#   AETHERA_SLIM_APP           骨架目录（默认 ${TMPDIR:-/tmp}/aetherupload-slim-e2e）
#   AETHERA_SLIM_REDIS=0       强制视为无 redis（第 8 条秒传跳过并如实报告）
#   AETHERA_SLIM_REDIS_HOST/_PORT/_DB  redis 连接参数（默认 127.0.0.1:6379 db 6）
#   AETHERA_SLIM_REFRESH=1     强制重建骨架（删掉骨架重新 composer update）
#   AETHERA_SLIM_SKIP_ROOT=1   跳过第 4 步（只想单独验证 Slim E2E 时）
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
WORK="${AETHERA_SLIM_APP:-${TMPDIR:-/tmp}/aetherupload-slim-e2e}"

echo "==> 仓库：$REPO_ROOT"
echo "==> 骨架目录：$WORK"

command -v php >/dev/null || { echo "错误：找不到 php" >&2; exit 1; }
command -v composer >/dev/null || { echo "错误：找不到 composer（骨架依赖需要它）" >&2; exit 1; }

php -r 'exit(PHP_VERSION_ID >= 80000 ? 0 : 1);' || {
    echo "错误：本包要求 PHP >= 8.0，当前是 $(php -r 'echo PHP_VERSION;')" >&2
    exit 1
}

echo "==> PHP $(php -r 'echo PHP_VERSION;') / composer $(composer --version --no-ansi | awk '{print $3}')"

# ---------------------------------------------------------------- 1. 仓库 dev 依赖
if [ ! -x "$REPO_ROOT/vendor/bin/phpunit" ]; then
    echo "==> 装仓库 dev 依赖（phpunit，不含任何框架）"
    ( cd "$REPO_ROOT" && composer install --no-interaction --no-progress )
else
    echo "==> 复用已装的仓库 dev 依赖"
fi

if [ "${AETHERA_SLIM_REFRESH:-0}" = "1" ]; then
    echo "==> 强制重建骨架：删 $WORK"
    rm -rf "$WORK"
fi

# ------------------------------------------------------------------------ 2. 跑
echo "==> 跑 Slim 端到端测试（真 Dispatcher + 真中间件栈）"
( cd "$REPO_ROOT" && "$REPO_ROOT/vendor/bin/phpunit" -c tests/Integration/slim/phpunit.xml --no-coverage "$@" )

# ---------------------------------------------------------------- 3. 控制台证据
# 命令实现是 kernel 的 src/Console/*Runner（run(callable $write): int），
# Adapter\Slim\Console\Application 只是把它们挂成 symfony 命令。
echo
echo "==> 控制台命令（骨架里的 bin/aetherupload）"
for command in "list" "aetherupload:groups" "aetherupload:publish" "aetherupload:clean 30" "aetherupload:build"; do
    echo "--- bin/aetherupload $command ---"
    # shellcheck disable=SC2086
    ( cd "$WORK" && php bin/aetherupload $command --no-ansi )
done

# ------------------------------------------------- 4. 配置一致性（改配置读取就得过）
if [ "${AETHERA_SLIM_SKIP_ROOT:-0}" != "1" ]; then
    echo
    echo "==> 配置一致性契约（tests/ConfigParityTest.php）"
    ( cd "$REPO_ROOT" && "$REPO_ROOT/vendor/bin/phpunit" --no-coverage --filter ConfigParityTest )
fi

echo
echo "==> 完成"
