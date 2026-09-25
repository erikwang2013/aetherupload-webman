#!/usr/bin/env bash
#
# ThinkPHP 适配器的自检脚本：装依赖 → 跑内核单测基线 → 跑真 ThinkPHP 端到端。
# 退出码即结论，关键输出（用例数/断言数/失败详情）直接打在 stdout。
#
# 用法：bash tests/Integration/thinkphp/ci.sh
#   AETHERA_E2E_APP=/path/to/app   换骨架目录（默认 /tmp/aetherupload-thinkphp-e2e）
#   AETHERA_E2E_REDIS=0            强制无 redis（第 8 条秒传跳过）
#   AETHERA_E2E_REDIS_HOST/_PORT/_DB   redis 连接参数（默认 127.0.0.1:6379 db 5）

set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO="$(cd "$HERE/../../.." && pwd)"
cd "$REPO"

echo "== 环境 =="
php -v | head -1
composer --version 2>/dev/null | head -1

if [ ! -x vendor/bin/phpunit ]; then
    echo "== 安装仓库依赖（含 PHPUnit）=="
    composer install --no-interaction --no-progress
fi

echo
echo "== 内核单测基线（ThinkPHP 适配器不得影响它）=="
vendor/bin/phpunit --no-coverage

echo
echo "== ThinkPHP 端到端（真框架，进程内真请求）=="
vendor/bin/phpunit --no-coverage -c "$HERE/phpunit.xml"
