<?php

/**
 * 把 webman 骨架改写成可测状态。用法：cwd = 应用根目录，php app-setup.php
 * （由 App::ensure() 调用；幂等，可反复执行。本脚本必须在应用进程里跑，
 *   因为改写 config/*.php 需要 webman 的 base_path()/runtime_path() 等全局函数。）
 *
 * 插件本身不在这里装：webman 的 post-package-install（support\Plugin::install）就是真实用户的安装路径，
 * composer require 时已经装好了（Install::install() 会在未绑定时自绑 webman 适配器）。
 * 这里只做配置改写：端口、单 worker、时区、翻译回落、redis、事件探针、秒传开关。
 */

$root = getcwd();

require $root . '/vendor/autoload.php';
require_once __DIR__ . '/App.php';

use AetherUpload\Tests\Integration\Webman\App;

patchApp($root);

echo "app-setup done: {$root}\n";

// ------------------------------------------------------------------ 配置改写

function patchApp(string $root): void
{
    $redis = App::redisAvailable();

    // 端口只写 config/process.php：webman 2.x 真正对外服务的是那里面的 webman 进程
    // （handler = app\process\Http）。早先这里给 server.php 写 listen，等于**再加**一个
    // HTTP Worker —— 实测 18787 与 8787 同时在服务同一个应用，而 webman 进程还占着骨架
    // 默认的 8787，与同机别的 webman 撞端口（"Address already in use: 8787"）。
    rewrite($root . '/config/server.php', static function (array $config) use ($root) {
        // rewrite() 是 include + var_export：骨架里的 runtime_path()/base_path() 会被求值成
        // **当时**那个应用目录的绝对路径。应用目录被整份复制成隔离实例后，pid 文件就指回原目录
        // —— start 报 already running、stop 去停别人的服务。按当前根显式写死。
        $config['pid_file'] = $root . '/runtime/webman.pid';
        $config['status_file'] = $root . '/runtime/webman.status';
        $config['stdout_file'] = $root . '/runtime/logs/stdout.log';
        $config['log_file'] = $root . '/runtime/logs/workerman.log';
        // 撤掉早先版本写进去的 listen/count（rewrite 保留旧值，不显式 unset 会一直带着）
        unset($config['listen'], $config['count']);

        return $config;
    });

    // 端口与单 worker：断言需要确定性（count=1 才不会有多 worker 争用本地文件）
    patchProcessConfig($root . '/config/process.php', App::port());

    // 时区必须与测试进程一致，否则跨月边界时 groupSubDir 会漂
    rewrite($root . '/config/app.php', static function (array $config) {
        $config['default_timezone'] = date_default_timezone_get();

        return $config;
    });

    // 关掉 fallback：第 6 条要的「翻译未命中」必须真的未命中，
    // 否则 symfony 会回退到已加载的 en 语种，测不到「未命中返回 key」这条契约
    rewrite($root . '/config/translation.php', static function (array $config) {
        $config['locale'] = 'en';
        $config['fallback_locale'] = [];

        return $config;
    });

    if ( is_file($root . '/config/redis.php') ) {
        rewrite($root . '/config/redis.php', static function (array $config) {
            $config['default']['host'] = App::redisHost();
            $config['default']['port'] = App::redisPort();
            $config['default']['password'] = '';
            $config['default']['database'] = App::redisDb();

            return $config;
        });
    }

    // 事件探针：一个抛异常（必须不影响响应），一个记录（必须仍被调用）
    file_put_contents($root . '/config/event.php', <<<'PHP'
<?php

// 由 tests/Integration/webman 的 E2E 准备脚本写入，勿手工修改
return [
    'aetherupload.upload_complete' => [
        1 => static function () {
            throw new \RuntimeException('E2E probe: this listener exception must not bubble');
        },
        2 => static function ($resource) {
            @file_put_contents(
                base_path() . '/runtime/aetherupload-probe.log',
                'aetherupload.upload_complete ' . ($resource->name ?? '?') . "\n",
                FILE_APPEND
            );
        },
    ],
];
PHP);

    $pluginConfig = $root . '/config/plugin/erikwang2013/aetherupload-webman/app.php';

    if ( ! is_file($pluginConfig) ) {
        echo "plugin config not installed yet, skip group patch\n";

        return;
    }

    rewrite($pluginConfig, static function (array $config) use ($redis) {
        $config['instant_completion'] = $redis;
        $config['groups']['file']['event_upload_complete'] = true;
        // 存储驱动每次显式重写：s3 模式写全量 s3 参数，非 s3 模式写回 driver=local
        // （骨架是 local/s3 两套用例共用的，不写回就会带着上一次的 driver 跑出新结论）
        $config['storage'] = App::storageConfig();

        return $config;
    });
}

/**
 * config/process.php 的定点改写：这个文件不能走 rewrite() —— 它被 require 时就会求值
 * Log::channel('default')，var_export 会把刚造出来的 Monolog 实例 dump 成
 * \Monolog\Logger::__set_state(...)，写出去的文件 load 不回来（Monolog 上没这个方法，直接 fatal）。
 * 只替换 stock 骨架里的那两行；形态对不上就抛，骨架换版要人来看，不能静默不改。
 */
function patchProcessConfig(string $file, int $port): void
{
    if ( ! is_file($file) ) {
        return;
    }

    $source = (string)file_get_contents($file);
    // 替换串里必须用 ${1}/${2}：写成 $21 会被当成第 21 个反向引用（不存在 → 空），静默咬掉字符
    $patched = (string)preg_replace("/'listen'(\s*)=>(\s*)'[^']*'/", "'listen'\${1}=>\${2}'http://0.0.0.0:{$port}'", $source, 1, $listenHits);
    $patched = (string)preg_replace("/'count'(\s*)=>(\s*)[^,\n]+/", "'count'\${1}=>\${2}1", $patched, 1, $countHits);

    if ( $listenHits !== 1 || $countHits !== 1 ) {
        throw new RuntimeException(
            'config/process.php 里没找到预期的 listen/count 行（骨架换版了？），端口未改写：' . $file
        );
    }

    file_put_contents($file, $patched);
}

/** 用 include + var_export 改写一个返回数组的配置文件（丢注释，但确定性好、不依赖正则） */
function rewrite(string $file, callable $mutate): void
{
    if ( ! is_file($file) ) {
        return;
    }

    $config = require $file;

    if ( ! is_array($config) ) {
        return;
    }

    $config = $mutate($config);

    file_put_contents(
        $file,
        "<?php\n\n// 由 tests/Integration/webman 的 E2E 准备脚本改写（原文件见仓库/骨架安装包）\nreturn "
        . var_export($config, true) . ";\n"
    );
}
