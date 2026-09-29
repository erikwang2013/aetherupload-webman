#!/usr/bin/env bash
#
# Yii3（yiisoft/*）适配器的端到端测试入口（本地与 CI 共用同一条路）。
#
#   bash tests/Integration/yii3/ci.sh
#   bash tests/Integration/yii3/ci.sh --filter testFullUploadFlow   # 多余参数透传给 phpunit
#
# 四步：
#   1) 装本仓库 dev 依赖（phpunit）—— 八个框架的 E2E 都不在根 composer.json 里加依赖
#   2) 真装一个最小 Yii3 应用骨架（composer 装 yiisoft/router + router-fastroute +
#      yii-runner-http + yii-runner-console + yii-console + httpsoft/http-message + predis + 本包，
#      yiisoft/config 这个 composer 插件负责生成配置合并计划），再跑 tests/Integration/yii3/*Test.php：
#      真 HttpApplicationRunner + 真配置组 + 真 DI 容器 + 真路由 + 真文件系统 + 真 redis
#   3) 贴真 HTTP 的证据：起 php -S 走 public/index.php，用 curl 打四条路由与控制台四条命令
#   4) 重跑 ConfigParityTest：适配器改了配置读取就必须仍然满足配置一致性契约
#
# 环境变量：
#   AETHERA_YII3_APP          骨架目录（默认 ${TMPDIR:-/tmp}/aetherupload-yii3-e2e）
#   AETHERA_YII3_PORT         php -S 冒烟端口（默认 18789；原生 PHP 用 18788）
#   AETHERA_E2E_REDIS=0       强制视为无 redis（第 8 条秒传跳过并如实报告）
#   AETHERA_E2E_REDIS_HOST/_PORT/_DB  redis 连接参数（默认 127.0.0.1:6379 db 11）
#   AETHERA_YII3_REFRESH=1    强制重建骨架（删掉 vendor 重新 composer update）
#   AETHERA_YII3_SKIP_ROOT=1  跳过第 4 步（只想单独验证 Yii3 E2E 时）
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
WORK="${AETHERA_YII3_APP:-${TMPDIR:-/tmp}/aetherupload-yii3-e2e}"
PORT="${AETHERA_YII3_PORT:-18789}"

echo "==> 仓库：$REPO_ROOT"
echo "==> 骨架目录：$WORK"

command -v php >/dev/null || { echo "错误：找不到 php" >&2; exit 1; }
command -v composer >/dev/null || { echo "错误：找不到 composer（骨架依赖需要它）" >&2; exit 1; }

# Yii3 的当前版本要求 PHP 8.1+（应用模板是 8.2+），比本包自身的 ^8.0 更严
php -r 'exit(PHP_VERSION_ID >= 80100 ? 0 : 1);' || {
    echo "错误：Yii3 要求 PHP >= 8.1，当前是 $(php -r 'echo PHP_VERSION;')" >&2
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

# ------------------------------------------------------------------------ 2. 跑
echo "==> 跑 Yii3 端到端测试（真 runner + 真 DI 容器 + 真路由）"
( cd "$REPO_ROOT" && "$REPO_ROOT/vendor/bin/phpunit" -c tests/Integration/yii3/phpunit.xml --no-coverage "$@" )

# ------------------------------------------------- 3. 真 HTTP + 控制台命令的证据
echo
echo "==> 真 HTTP 冒烟（php -S + curl，走 public/index.php 的完整入口）"
php -S "127.0.0.1:$PORT" -t "$WORK/public" "$WORK/public/index.php" >"$WORK/server.log" 2>&1 &
SERVER_PID=$!
trap 'kill "$SERVER_PID" 2>/dev/null || true' EXIT

for _ in $(seq 1 50); do
    if php -r 'exit(@fsockopen("127.0.0.1", '"$PORT"', $e, $s, 0.2) ? 0 : 1);'; then
        break
    fi
    sleep 0.2
done

echo "--- GET /health（宿主自有路由） ---"
curl -sS -o /dev/null -w 'HTTP %{http_code}\n' "http://127.0.0.1:$PORT/health"

echo "--- GET /definitely-not-a-route（未命中） ---"
curl -sS -o /dev/null -w 'HTTP %{http_code}\n' "http://127.0.0.1:$PORT/definitely-not-a-route"

echo "--- GET /aetherupload/preprocess（方法不对） ---"
curl -sS -o /dev/null -D - -w '\n' "http://127.0.0.1:$PORT/aetherupload/preprocess" | grep -iE '^HTTP|^Allow' || true

echo "--- POST /aetherupload/preprocess（真上传预处理） ---"
curl -sS -X POST --form-string 'resource_name=smoke.gif' --form-string 'resource_size=43' \
    --form-string 'group=file' --form-string 'resource_hash=00000000000000000000000000000000' \
    --form-string 'locale=en' "http://127.0.0.1:$PORT/aetherupload/preprocess"
echo

echo "==> 控制台命令（骨架里的 bin/yii）"
for command in "list" "aetherupload/groups" "aetherupload/clean 30" "aetherupload/publish"; do
    echo "--- bin/yii $command ---"
    # shellcheck disable=SC2086
    ( cd "$WORK" && php bin/yii $command --no-ansi )
done

# ------------------------------------------------- 4. 配置一致性（改配置读取就得过）
if [ "${AETHERA_YII3_SKIP_ROOT:-0}" != "1" ]; then
    echo
    echo "==> 配置一致性契约（tests/ConfigParityTest.php）"
    ( cd "$REPO_ROOT" && "$REPO_ROOT/vendor/bin/phpunit" --no-coverage --filter ConfigParityTest )
fi

echo
echo "==> 完成"
