#!/usr/bin/env bash
#
# 原生 PHP 适配器的端到端测试入口（本地与 CI 共用同一条路）。
#
#   bash tests/Integration/native/ci.sh
#   bash tests/Integration/native/ci.sh --filter testFullUploadFlow   # 多余参数透传给 phpunit
#
# 与另外六个框架最大的不同：**没有框架要装**。原生 PHP 的宿主就是 PHP 自己，
# 所以临时应用只有三个文件（public/index.php、config/aetherupload.php、bin/aetherupload），
# 全部由 tests/Integration/native/App.php::ensure() 生成。
#
#   1) 装本仓库 dev 依赖（phpunit）——原生 PHP 的 E2E 不需要任何框架依赖
#   2) 跑 tests/Integration/native/*Test.php：真起 `php -S`，curl 打真 HTTP，
#      真 $_POST/$_FILES、真 header()/echo，走完「预处理 → 分块 → 完成 → 展示 / 下载」
#   3) 贴控制台证据：临时应用里的 bin/aetherupload 跑四条命令（控制台交付物）
#   4) 重跑 ConfigParityTest：适配器改了配置读取就必须仍然满足配置一致性契约
#
# 环境变量：
#   AETHERA_E2E_APP           临时应用目录（默认 ${TMPDIR:-/tmp}/aetherupload-native-e2e）
#   AETHERA_E2E_PORT          监听端口（默认 18788）
#   AETHERA_E2E_REDIS=0       强制视为无 redis（第 8 条秒传跳过并如实报告）
#   AETHERA_E2E_REDIS_HOST/_PORT/_DB  redis 连接参数（默认 127.0.0.1:6379 db 10）
#   AETHERA_E2E_KEEP=1        跑完保留 php -S 与临时应用（排查用）
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
WORK="${AETHERA_E2E_APP:-${TMPDIR:-/tmp}/aetherupload-native-e2e}"

echo "==> 仓库：$REPO_ROOT"
echo "==> 临时应用：$WORK"

command -v php >/dev/null || { echo "错误：找不到 php" >&2; exit 1; }
command -v curl >/dev/null || { echo "错误：找不到 curl（E2E 靠它打真请求）" >&2; exit 1; }

php -r 'exit(PHP_VERSION_ID >= 80000 ? 0 : 1);' || {
    echo "错误：本包要求 PHP >= 8.0，当前是 $(php -r 'echo PHP_VERSION;')" >&2
    exit 1
}

echo "==> PHP $(php -r 'echo PHP_VERSION;')"

# ---------------------------------------------------------------- 1. 仓库 dev 依赖
if [ ! -x "$REPO_ROOT/vendor/bin/phpunit" ]; then
    echo "==> 装仓库 dev 依赖（phpunit，不含任何框架）"
    ( cd "$REPO_ROOT" && composer install --no-interaction --no-progress )
else
    echo "==> 复用已装的仓库 dev 依赖"
fi

# ------------------------------------------------------------------------ 2. 跑
# 应用准备与起服务在 bootstrap.php 里（App::ensure() + App::start()），跑完自动收工
echo "==> 跑原生 PHP 端到端测试（真 php -S + 真 curl）"
( cd "$REPO_ROOT" && "$REPO_ROOT/vendor/bin/phpunit" -c tests/Integration/native/phpunit.xml --no-coverage "$@" )

# ---------------------------------------------------------------- 3. 控制台证据
echo
echo "==> 控制台命令（临时应用里的 bin/aetherupload）"
for command in "list" "aetherupload:groups" "aetherupload:publish" "aetherupload:clean 30" "aetherupload:build"; do
    echo "--- bin/aetherupload $command ---"
    # shellcheck disable=SC2086
    ( cd "$WORK" && php bin/aetherupload $command --no-ansi )
done

# ------------------------------------------------- 4. 配置一致性（改配置读取就得过）
echo
echo "==> 配置一致性契约（tests/ConfigParityTest.php）"
( cd "$REPO_ROOT" && "$REPO_ROOT/vendor/bin/phpunit" --no-coverage --filter ConfigParityTest )

echo
echo "==> 完成"
