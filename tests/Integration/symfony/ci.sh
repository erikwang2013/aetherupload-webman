#!/usr/bin/env bash
#
# Symfony 端到端套件的自包含入口：装依赖 → 跑单元基线 → 跑 Symfony E2E。
#
# 用法：bash tests/Integration/symfony/ci.sh
#   AETHERA_E2E_APP=/tmp/app        指定固定应用目录（默认 sys_get_temp_dir()/aetherupload-symfony-e2e）
#   AETHERA_E2E_REDIS=0             强制关掉秒传（第 8 条会如实跳过）
#   AETHERA_E2E_DEBUG=1             应用以 debug 模式启动（排错用）
#
# Symfony 本体由 tests/Integration/symfony/App.php 装进临时骨架，不写进根 composer.json。

set -euo pipefail

cd "$(dirname "$0")/../../.."
repo_root=$(pwd)

echo "== 环境 =="
php -v | head -1
composer --version | head -1
php -r 'printf("phpredis: %s / fileinfo: %s\n", extension_loaded("redis") ? "yes" : "no", extension_loaded("fileinfo") ? "yes" : "no");'

echo
echo "== 依赖 =="
if [ ! -f vendor/autoload.php ]; then
    composer install --no-interaction --no-progress
fi

echo
echo "== 单元基线（内核逻辑，替身宿主）=="
vendor/bin/phpunit --no-coverage

echo
echo "== Symfony 端到端（真应用 + 真 HttpKernel）=="
vendor/bin/phpunit -c tests/Integration/symfony/phpunit.xml --no-coverage

echo
echo "== 完毕：仓库根 $repo_root =="
