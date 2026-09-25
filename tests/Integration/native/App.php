<?php

namespace AetherUpload\Tests\Integration\Native;

use RuntimeException;

/**
 * 原生 PHP 端到端测试的应用准备器。
 *
 * 「宿主」就是 PHP 自己，所以没有骨架要 composer create-project：在临时目录里放一个最小的原生应用
 * （public/index.php + config/aetherupload.php + bin/aetherupload 三个文件），用 PHP 内置服务器
 * `php -S` 真起 HTTP 服务，再用 curl 打真请求。
 *
 * 这一层才证明得出「原生 PHP 下可用」：真 SAPI、真 $_POST/$_FILES、真 header()/echo，
 * 而不是在测试进程里伪造超全局。顺带把 README 的「通用两步」（aetherupload:groups / :publish）
 * 真跑一遍 —— 原生 PHP 的控制台入口是交付物之一，不能只写个类就算数。
 *
 * 幂等：每次调用都重写那三个应用文件（避免上一轮跑测留下的配置把结果带偏），服务已在跑则复用。
 *
 * 环境变量：
 *   AETHERA_E2E_APP           应用目录（默认 sys_get_temp_dir()/aetherupload-native-e2e）
 *   AETHERA_E2E_PORT          监听端口（默认 18788；与 webman 的 18787 刻意错开）
 *   AETHERA_E2E_REDIS         '0' 强制视为无 redis（第 8 条跳过）；默认自动探测
 *   AETHERA_E2E_REDIS_HOST/_PORT/_DB  redis 连接参数（默认 127.0.0.1:6379 db 10）
 *   AETHERA_E2E_KEEP          '1' 测试结束后不停止服务（排查用）
 */
final class App
{
    /** @var resource|null php -S 的进程句柄 */
    private static $process;

    private function __construct()
    {
    }

    public static function root(): string
    {
        $dir = getenv('AETHERA_E2E_APP');

        return $dir ? rtrim($dir, '/') : sys_get_temp_dir() . '/aetherupload-native-e2e';
    }

    /** 本仓库根目录（应用从这里 require vendor/autoload.php，即「当前工作区」而不是发布版） */
    public static function repoRoot(): string
    {
        return dirname(__DIR__, 3);
    }

    public static function port(): int
    {
        return (int)(getenv('AETHERA_E2E_PORT') ?: 18788);
    }

    public static function baseUrl(): string
    {
        return 'http://127.0.0.1:' . self::port();
    }

    /** 事件探针日志（记录型监听器往这里追加） */
    public static function probeLog(): string
    {
        return self::root() . '/aetherupload-probe.log';
    }

    public static function redisHost(): string
    {
        return getenv('AETHERA_E2E_REDIS_HOST') ?: '127.0.0.1';
    }

    public static function redisPort(): int
    {
        return (int)(getenv('AETHERA_E2E_REDIS_PORT') ?: 6379);
    }

    /** 专属 redis 库：七个 harness 各用一个，避免并行时互相清掉对方的秒传记录 */
    public static function redisDb(): int
    {
        return (int)(getenv('AETHERA_E2E_REDIS_DB') ?: 10);
    }

    public static function redisDisabled(): bool
    {
        return getenv('AETHERA_E2E_REDIS') === '0';
    }

    /**
     * redis 是否真的可用：判据不是「端口通」，而是内核依赖的那套返回值语义确实成立
     * （SETEX + GET 往返一次）。装了 phpredis 但服务没起、或 db 不可选，都算不可用。
     */
    public static function redisAvailable(): bool
    {
        $client = self::connectRedis();

        if ( $client === null ) {
            return false;
        }

        try {
            return $client->setex('aetherupload-e2e-probe', 10, 'ok') !== false
                && $client->get('aetherupload-e2e-probe') === 'ok';
        } catch ( \Throwable $e ) {
            return false;
        }
    }

    /**
     * phpredis 客户端（已 connect + select）。没装扩展或连不上时返回 null。
     *
     * @return \Redis|null
     */
    public static function connectRedis()
    {
        if ( self::redisDisabled() || ! class_exists('\Redis') ) {
            return null;
        }

        try {
            $client = new \Redis();
            $client->connect(self::redisHost(), self::redisPort(), 1.0);
            $client->select(self::redisDb());

            return $client;
        } catch ( \Throwable $e ) {
            return null;
        }
    }

    /** 清掉本 harness 自己的秒传记录（其它 harness 的 db 不受影响） */
    public static function flushInstantCompletionKeys(): void
    {
        $client = self::connectRedis();

        if ( $client === null ) {
            return;
        }

        try {
            foreach ( (array)$client->keys('aetherupload*') as $key ) {
                $client->del($key);
            }
        } catch ( \Throwable $e ) {
            // 秒传相关的用例会自己按 redisAvailable() 跳过，这里不打断其它用例
        }
    }

    // ------------------------------------------------------------------ 应用准备

    /** 写出最小原生应用并跑完「通用两步」。可反复调用。 */
    public static function ensure(): void
    {
        $root = self::root();

        foreach ( ['', '/public', '/config', '/bin'] as $sub ) {
            if ( ! is_dir($root . $sub) && ! @mkdir($root . $sub, 0755, true) && ! is_dir($root . $sub) ) {
                throw new RuntimeException('无法创建应用目录：' . $root . $sub);
            }
        }

        $redis = self::redisAvailable();

        self::write($root . '/config/aetherupload.php', self::configFixture($redis));
        self::write($root . '/public/index.php', self::indexFixture($redis));
        self::write($root . '/bin/aetherupload', self::binFixture($redis));
        @chmod($root . '/bin/aetherupload', 0755);

        // README 的「通用两步」：不建目录 / 不发布语言文件，preprocess 与错误消息都会不对。
        // 顺带证明原生 PHP 的控制台入口真能用（而不只是有个类）
        self::assertConsole('aetherupload:groups --no-ansi', 'aetherupload:groups');
        self::assertConsole('aetherupload:publish --no-ansi', 'aetherupload:publish');
    }

    /** 起服务（已在跑则复用）；就绪判据是端口能连上，超时就把服务端日志贴出来 */
    public static function start(): void
    {
        if ( self::isRunning() ) {
            return;
        }

        $log = self::root() . '/server.log';
        $err = self::root() . '/server.err.log';
        @unlink($log);
        @unlink($err);

        $command = [
            PHP_BINARY,
            '-S', '127.0.0.1:' . self::port(),
            '-t', self::root() . '/public',
            self::root() . '/public/index.php',
        ];

        $process = proc_open($command, [
            0 => ['file', '/dev/null', 'r'],
            1 => ['file', $log, 'a'],
            2 => ['file', $err, 'a'],
        ], $pipes);

        if ( ! is_resource($process) ) {
            throw new RuntimeException('无法启动 php -S（proc_open 失败）');
        }

        self::$process = $process;

        $deadline = microtime(true) + 10;

        while ( microtime(true) < $deadline ) {
            $socket = @fsockopen('127.0.0.1', self::port(), $errno, $errstr, 0.2);

            if ( is_resource($socket) ) {
                fclose($socket);

                return;
            }

            usleep(100000);
        }

        throw new RuntimeException(
            'php -S 在 10 秒内没起来（端口 ' . self::port() . "）\n"
            . '--- server.log ---' . "\n" . @file_get_contents($log) . "\n"
            . '--- server.err.log ---' . "\n" . @file_get_contents($err)
        );
    }

    public static function stop(): void
    {
        if ( is_resource(self::$process) ) {
            proc_terminate(self::$process);
            proc_close(self::$process);
        }

        self::$process = null;

        // proc_terminate 之后端口可能还没释放，等它一会儿，避免下一次 start() 撞 "Address already in use"
        $deadline = microtime(true) + 5;

        while ( microtime(true) < $deadline ) {
            $socket = @fsockopen('127.0.0.1', self::port(), $errno, $errstr, 0.2);

            if ( ! is_resource($socket) ) {
                return;
            }

            fclose($socket);
            usleep(100000);
        }
    }

    public static function isRunning(): bool
    {
        $socket = @fsockopen('127.0.0.1', self::port(), $errno, $errstr, 0.2);

        if ( is_resource($socket) ) {
            fclose($socket);

            return true;
        }

        return false;
    }

    // -------------------------------------------------------------------- 发请求

    /**
     * 打一个真实 HTTP 请求（curl 命令行，与人工排查时敲的一模一样）。
     *
     * @param array<string,mixed>              $post    表单字段；数组值按 `name[]=v` 逐个发送（PHP 会解析成数组）
     * @param array<string,array{name:string,content:string}> $files   字段名 => [文件名, 原始字节]
     * @param string[]                         $headers 'X-Foo: bar'
     *
     * @return array{status:int,body:string,headers:string[]}
     */
    public static function request(string $method, string $path, array $post = [], array $files = [], array $headers = []): array
    {
        $bodyFile = tempnam(sys_get_temp_dir(), 'aetherupload-e2e-body-');
        $headerFile = tempnam(sys_get_temp_dir(), 'aetherupload-e2e-head-');
        $tempUploads = [];

        $args = ['curl', '-sS', '--max-time', '30', '-X', $method, '-o', $bodyFile, '-D', $headerFile, '-w', '%{http_code}'];

        foreach ( $headers as $header ) {
            $args[] = '-H';
            $args[] = $header;
        }

        foreach ( $post as $name => $value ) {
            foreach ( is_array($value) ? $value : [$value] as $item ) {
                // --form-string：值里的 @ 不当成文件（普通 -F 会）
                $args[] = '--form-string';
                $args[] = (is_array($value) ? $name . '[]' : $name) . '=' . (string)$item;
            }
        }

        foreach ( $files as $name => $file ) {
            $temp = tempnam(sys_get_temp_dir(), 'aetherupload-e2e-chunk-');
            file_put_contents($temp, $file['content']);
            $tempUploads[] = $temp;

            $args[] = '-F';
            $args[] = $name . '=@' . $temp . ';filename=' . $file['name'] . ';type=application/octet-stream';
        }

        $args[] = self::baseUrl() . $path;

        $output = [];
        $exit = 0;
        exec(implode(' ', array_map('escapeshellarg', $args)), $output, $exit);

        $status = (int)trim(implode('', $output));
        $body = (string)@file_get_contents($bodyFile);
        $lines = array_values(array_filter(array_map('rtrim', (array)@file($headerFile)), static function ($line) {
            return $line !== '';
        }));

        foreach ( [$bodyFile, $headerFile] as $temp ) {
            @unlink($temp);
        }

        foreach ( $tempUploads as $temp ) {
            @unlink($temp);
        }

        if ( $exit !== 0 && $status === 0 ) {
            throw new RuntimeException('curl 调用失败（exit ' . $exit . '）：' . $method . ' ' . $path);
        }

        // 第一行是 HTTP 状态行，不算响应头
        array_shift($lines);

        return ['status' => $status, 'body' => $body, 'headers' => $lines];
    }

    /** 跑一条控制台命令，返回 [exit, output] */
    public static function runConsole(string $arguments): array
    {
        $output = [];
        $exit = 0;
        exec(
            escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(self::root() . '/bin/aetherupload') . ' ' . $arguments . ' 2>&1',
            $output,
            $exit
        );

        return ['exit' => $exit, 'output' => implode("\n", $output)];
    }

    private static function assertConsole(string $arguments, string $what): void
    {
        $result = self::runConsole($arguments);

        if ( $result['exit'] !== 0 ) {
            throw new RuntimeException(
                $what . ' 失败（exit ' . $result['exit'] . "）：\n" . $result['output']
            );
        }
    }

    private static function write(string $path, string $content): void
    {
        if ( file_put_contents($path, $content) === false ) {
            throw new RuntimeException('无法写入 ' . $path);
        }
    }

    // ------------------------------------------------------------------ 应用内容

    /**
     * 应用配置：整棵数组读包内默认配置再覆写。
     *
     * 覆写而不是只塞几个键：适配器是**顶层浅合并**（给了 groups 就整块替换），
     * 只写部分键会把默认分组丢掉。
     */
    private static function configFixture(bool $redis): string
    {
        $repo = self::repoRoot();
        $instant = $redis ? 'true' : 'false';

        return <<<PHP
<?php

\$config = require '$repo/config/aetherupload.php';

// 第 8 条秒传：只有探测到可用的 redis 才开（开了却没有 redis，内核会直接报错而不是静默失效）
\$config['instant_completion'] = $instant;

// 第 7 条事件探针：两个事件都要打开，内核才会派发
\$config['groups']['file']['event_before_upload_complete'] = true;
\$config['groups']['file']['event_upload_complete'] = true;

// 原生 PHP 的中间件契约：无参可调用对象，**返回响应对象即短路**。
// 这里用一个「带特殊请求头就拒绝」的下载中间件，把 README 里「自定义中间件做权限控制」的说法变成可测的事实。
\$config['middleware_download'] = [ static function () {
    if ( (isset(\$_SERVER['HTTP_X_E2E_FORBID']) ? \$_SERVER['HTTP_X_E2E_FORBID'] : '') === '1' ) {
        return \\AetherUpload\\Runtime::response()->text('forbidden by middleware', 403);
    }

    return null;   // 返回非对象 = 不短路，继续走控制器
} ];

return \$config;

PHP;
    }

    /**
     * 前端控制器：**这一份就是 README 里给原生 PHP 用户的接入范例**，测试跑的就是它。
     */
    private static function indexFixture(bool $redis): string
    {
        $repo = self::repoRoot();
        $probeLog = self::probeLog();

        $redisOption = $redis
            ? "'redis'     => static function () { \$redis = new \\Redis(); \$redis->connect('" . self::redisHost() . "', " . self::redisPort() . "); \$redis->select(" . self::redisDb() . "); return \$redis; },"
            : "'redis'     => null,   // 没有 phpredis / 没有 redis 服务：秒传不可用，其余功能照常";

        return <<<PHP
<?php

require '$repo/vendor/autoload.php';

\$path = parse_url(\$_SERVER['REQUEST_URI'], PHP_URL_PATH);

// 已存在的静态文件交给内置服务器自己发（发布出来的前端 js 走这条）
if (is_string(\$path) && \$path !== '/' && is_file(__DIR__ . \$path)) {
    return false;
}

exit(\\AetherUpload\\Adapter\\Native\\Bootstrap::handle([
    'base_path' => dirname(__DIR__),
    'config'    => require __DIR__ . '/../config/aetherupload.php',
    $redisOption
    'listeners' => [
        // 第 7 条：这个监听器必须抛异常，且不得影响上传响应
        'aetherupload.before_upload_complete' => static function (): void {
            throw new \\RuntimeException('E2E 探针：监听器异常不得冒泡');
        },
        // 第 7 条：它必须仍被调用
        'aetherupload.upload_complete' => static function (\\AetherUpload\\Adapter\\Native\\NativeEvent \$event): void {
            @file_put_contents('$probeLog', \$event->name . ' ' . (is_object(\$event->payload) ? get_class(\$event->payload) : gettype(\$event->payload)) . "\\n", FILE_APPEND);
        },
    ],
]));

PHP;
    }

    /**
     * 控制台入口：三行，与 README 里给原生 PHP 用户的写法一致。
     *
     * **redis 选项必须和前端控制器给的是同一个** —— 配置里 instant_completion=true 时，
     * aetherupload:build 需要真的能连上 Redis；控制台这条链上漏了它，命令会以
     * 「未为此宿主配置 Redis」失败（这正是 CI 上抓到的那个缺口：HTTP 侧通了、控制台侧没通）。
     */
    private static function binFixture(bool $redis): string
    {
        $repo = self::repoRoot();

        $redisOption = $redis
            ? "'redis' => static function () { \$redis = new \\Redis(); \$redis->connect('" . self::redisHost() . "', " . self::redisPort() . "); \$redis->select(" . self::redisDb() . "); return \$redis; },"
            : "'redis' => null,";

        return <<<PHP
#!/usr/bin/env php
<?php

require '$repo/vendor/autoload.php';

\\AetherUpload\\Adapter\\Native\\Bootstrap::bind(
    require __DIR__ . '/../config/aetherupload.php',
    dirname(__DIR__),
    [
        $redisOption
    ]
);

exit((new \\AetherUpload\\Console\\Application())->run());

PHP;
    }
}
