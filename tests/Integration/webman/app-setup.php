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

    // 端口与单 worker：断言需要确定性
    rewrite($root . '/config/server.php', static function (array $config) {
        $config['listen'] = 'http://0.0.0.0:' . App::port();
        $config['count'] = 1;

        return $config;
    });

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

        return $config;
    });
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
