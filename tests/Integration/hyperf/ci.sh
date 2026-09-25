#!/usr/bin/env bash
#
# Hyperf 适配器的自检脚本（自包含）：装 swoole/redis 扩展 → 装仓库依赖 → 跑内核单测基线
# → 真起 Hyperf + swoole HTTP 服务跑端到端（含协程交错那条）。退出码即结论。
#
# 用法：bash tests/Integration/hyperf/ci.sh
#   AETHERA_E2E_APP=/path/to/app    换骨架目录（默认 /tmp/aetherupload-hyperf-e2e）
#   AETHERA_E2E_PORT=19501          换端口
#   AETHERA_E2E_REDIS=0             强制无 redis（第 8 条秒传跳过）
#   AETHERA_E2E_REDIS_HOST/_PORT/_DB  redis 连接参数（默认 127.0.0.1:6379 db 7）
#   AETHERA_SKIP_EXT_INSTALL=1      不尝试装扩展（本机已有/装不了时）
#
# 与另外几家不同的地方只有两处：
#   1. 这一家需要 ext-swoole（框架的运行时本身），脚本会尽力装，装不上就明说并让协程用例跳过；
#   2. 秒传第 8 条既可以用外部 redis，也可以由本脚本用 docker 起一个（装了 docker 才起）。

set -euo pipefail

# 环境陷阱：php.ini 里若开了 xdebug 的 profiling（xdebug.mode=profile），Hyperf **冷启动**
# （runtime/container 还不存在、要扫全量注解）会在 ClassLoader::init() 里无输出地退 255 ——
# 没有 PHP 报错、error_get_last() 是 NULL，只有 exit code。带 `-d xdebug.mode=off` 或本环境变量
# 就正常。与本适配器无关，但不关掉，「从零构建骨架」这条路会静默失败（CI 机器同样可能踩到）。
export XDEBUG_MODE=off

# 私有临时目录：六家的 harness 会并行跑，`/tmp/pecl-swoole.log` 这种公共名会被别人同时写，
# 排查时会读到不属于自己的输出（踩过）。这里一律用带前缀的私有路径。
WORK="$(mktemp -d "${TMPDIR:-/tmp}/aetherupload-hyperf-ci.XXXXXX")"
trap 'rm -rf "$WORK"' EXIT

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO="$(cd "$HERE/../../.." && pwd)"
cd "$REPO"

if [ "$(id -u)" = "0" ]; then
    export COMPOSER_ALLOW_SUPERUSER=1
fi

SUDO=""
if [ "$(id -u)" != "0" ] && command -v sudo >/dev/null 2>&1; then
    SUDO="sudo"
fi

echo "== 环境 =="
php -v | head -1
composer --version 2>/dev/null | head -1

echo
echo "== 扩展：swoole（Hyperf 的运行时；缺了就只能跑内核单测） =="
install_extension() {
    local name="$1"
    local pecl_args="${2:-}"

    if [ -n "$SUDO" ] || [ "$(id -u)" = "0" ]; then
        if command -v pecl >/dev/null 2>&1; then
            echo "-- 尝试 pecl install ${name}"
            # swoole 的 pecl 会连续问几个 enable 选项，空回车取默认；装完可能问 php.ini 路径
            if printf '\n\n\n\n\n\n\n\n' | ${SUDO} pecl install ${name} ${pecl_args} >"${WORK}/pecl-${name}.log" 2>&1; then
                echo "   pecl 安装成功"
            else
                echo "   pecl 安装失败，尾部日志："
                tail -15 "${WORK}/pecl-${name}.log" || true
            fi
        fi

        # pecl 装完通常还要把 extension= 写进 ini；-e 判断避免重复写
        local scan_dir
        scan_dir="$(php -i | sed -n 's/^Scan this dir for additional .ini files => //p')"
        if [ -z "$scan_dir" ] || [ "$scan_dir" = "(none)" ] || [ ! -d "$scan_dir" ]; then
            scan_dir="$(php -r 'echo dirname(php_ini_loaded_file() ?: "/etc/php.ini");' 2>/dev/null || echo /etc)"
        fi
        if php -r "exit(extension_loaded('${name}') ? 0 : 1);" 2>/dev/null; then
            echo "   已加载 ${name}"
        elif [ -d "$scan_dir" ] && [ -w "$scan_dir" ] || { [ -n "$SUDO" ] && [ -d "$scan_dir" ]; }; then
            echo "extension=${name}.so" | ${SUDO} tee "${scan_dir}/aetherupload-${name}.ini" >/dev/null 2>&1 || true
        fi

        # 兜底：发行版自带的包（Ubuntu/Debian）
        if ! php -r "exit(extension_loaded('${name}') ? 0 : 1);" 2>/dev/null && command -v apt-get >/dev/null 2>&1; then
            local ver
            ver="$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')"
            echo "-- 尝试 apt-get install php${ver}-${name}"
            ${SUDO} apt-get install -y --no-install-recommends "php${ver}-${name}" >"${WORK}/apt-${name}.log" 2>&1 || tail -5 "${WORK}/apt-${name}.log" || true
        fi
    fi
}

if [ "${AETHERA_SKIP_EXT_INSTALL:-0}" != "1" ]; then
    php -r "exit(extension_loaded('swoole') ? 0 : 1);" || install_extension swoole
    php -r "exit(extension_loaded('redis') ? 0 : 1);" || install_extension redis
fi

SWOOLE_OK=0
php -r "exit(extension_loaded('swoole') ? 0 : 1);" && SWOOLE_OK=1

if [ "$SWOOLE_OK" = "1" ]; then
    echo "swoole 版本：$(php -r 'echo phpversion("swoole");')"
else
    echo "!! 本机没有可用的 ext-swoole：端到端套件里依赖协程的用例会 markTestSkipped（如实报告，不放宽断言）"
fi

echo
echo "== redis（第 8 条秒传用；没装 docker 就用本机已有的） =="
if ! (exec 3<>/dev/tcp/127.0.0.1/6379) 2>/dev/null; then
    if command -v docker >/dev/null 2>&1 && docker info >/dev/null 2>&1; then
        echo "-- 起一个 redis:7 容器（端口 6379）"
        docker run -d --rm -p 6379:6379 --name aetherupload-e2e-redis redis:7 >/dev/null 2>&1 || true
        for _ in $(seq 1 30); do
            (exec 3<>/dev/tcp/127.0.0.1/6379) 2>/dev/null && break
            sleep 1
        done
    fi
fi

if (exec 3<>/dev/tcp/127.0.0.1/6379) 2>/dev/null; then
    echo "127.0.0.1:6379 可用：第 8 条秒传会被真跑"
else
    echo "127.0.0.1:6379 不可用：第 8 条秒传会 markTestSkipped（如实报告，不放宽断言）"
fi

echo
echo "== 安装仓库依赖 =="
if [ ! -x vendor/bin/phpunit ]; then
    composer install --no-interaction --no-progress
fi

echo
echo "== 内核单测基线（Hyperf 适配器不得影响它） =="
vendor/bin/phpunit --no-coverage

echo
echo "== Hyperf 端到端（真骨架 + swoole HTTP + curl）=="
# --testdox：协程交错那条用例的名字与结果要能在 stdout 上一眼看出来
vendor/bin/phpunit --no-coverage --testdox -c "$HERE/phpunit.xml"

echo
echo "== 完成 =="
