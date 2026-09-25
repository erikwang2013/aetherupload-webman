<?php

namespace AetherUpload\Tests\Integration\Webman;

use RuntimeException;

/**
 * webman 端到端测试的应用准备器：真装一个 webman 骨架 + 真起 HTTP 服务 + 用 curl 打真请求。
 *
 * 幂等：已就绪的应用会直接复用（重复跑测试不重复 composer install）。
 * 可用环境变量：
 *   AETHERA_E2E_APP    应用目录（默认 sys_get_temp_dir()/aetherupload-webman-e2e）
 *   AETHERA_E2E_PORT   监听端口（默认 8787）
 *   AETHERA_E2E_REDIS  '0' 强制视为无 redis（第 8 条跳过）；默认自动探测
 *   AETHERA_E2E_REDIS_HOST / _PORT / _DB   redis 连接参数（默认 127.0.0.1:6379，db 编号见 redisDb()）
 *   AETHERA_E2E_KEEP   '1' 测试结束后不停止服务（排查用）
 */
final class App
{
    private function __construct()
    {
    }

    public static function root(): string
    {
        $dir = getenv('AETHERA_E2E_APP');

        return $dir ? rtrim($dir, '/') : sys_get_temp_dir() . '/aetherupload-webman-e2e';
    }

    /** 本仓库根目录（path 仓库指向它，装进临时骨架的是当前工作区，不是发布版） */
    public static function repoRoot(): string
    {
        return dirname(__DIR__, 3);
    }

    public static function port(): int
    {
        // 刻意避开 webman 默认的 8787：同机上常有别的 webman 在跑（本仓库的其他 E2E 脚本也会用它）
        return (int)(getenv('AETHERA_E2E_PORT') ?: 18787);
    }

    public static function baseUrl(): string
    {
        return 'http://127.0.0.1:' . self::port();
    }

    public static function probeLog(): string
    {
        return self::root() . '/runtime/aetherupload-probe.log';
    }

    public static function redisHost(): string
    {
        return getenv('AETHERA_E2E_REDIS_HOST') ?: '127.0.0.1';
    }

    public static function redisPort(): int
    {
        return (int)(getenv('AETHERA_E2E_REDIS_PORT') ?: 6379);
    }

    /**
     * 秒传用的 redis 库。**每个框架一个专属库，默认值必须显式写死**，不要靠「碰巧和邻居一样」：
     * 各 harness 都会清 `aetherupload:*` 键，共用同一个库时并行跑测会互相删掉对方正在用的记录
     * （第 8 条秒传因此偶发失败）。已分配：webman 7 · laravel 4 · thinkphp 5 · hyperf 3 · slim 6
     * · symfony 8 · yii 9。
     */
    public static function redisDb(): int
    {
        return (int)(getenv('AETHERA_E2E_REDIS_DB') ?: 7);
    }

    /** redis 是否可用（第 8 条秒传的前提）；AETHERA_E2E_REDIS=0 可强制关掉 */
    public static function redisAvailable(): bool
    {
        if ( getenv('AETHERA_E2E_REDIS') === '0' ) {
            return false;
        }

        $socket = @fsockopen(self::redisHost(), self::redisPort(), $errno, $errstr, 2);

        if ( $socket === false ) {
            return false;
        }

        fwrite($socket, "PING\r\n");
        $reply = (string)fgets($socket);
        fclose($socket);

        return strpos($reply, 'PONG') !== false;
    }

    /**
     * 清掉本 E2E 可能踩到的秒传记录（aetherupload:*），让每条用例都从「查无记录」开始。
     *
     * 测试内容本身已经每条用例独占（见 FlowAssertions::gifBytes()），这里再上一道确定性保险：
     * 秒传短路发生在事件派发之前，任何残留记录都会让第 7 条探针假红、让第 1 条看不到 .part。
     * 按前缀精确删而不是 FLUSHDB —— 同机 laravel/thinkphp/hyperf 的 harness 默认也用 db 7，
     * FLUSHDB 会把别人的数据一起清掉。走裸 socket 发 RESP，免得测试进程还要装 redis 扩展
     * （骨架里的 webman/redis 只装在应用侧）。没 redis 时直接返回：第 8 条会 markTestSkipped。
     */
    public static function flushInstantCompletionKeys(): void
    {
        // 尊重 AETHERA_E2E_REDIS=0：该模式下本套件不碰 redis，也就不该动别人的库
        if ( ! self::redisAvailable() ) {
            return;
        }

        $socket = @fsockopen(self::redisHost(), self::redisPort(), $errno, $errstr, 2);

        if ( $socket === false ) {
            return;
        }

        stream_set_timeout($socket, 2);

        // 用 RESP 数组发命令，免得跟 redis 的行内命令引号规则打交道；回复都是一行（+OK / :N）
        $command = static function (array $args): string {
            $out = '*' . count($args) . "\r\n";

            foreach ( $args as $arg ) {
                $out .= '$' . strlen($arg) . "\r\n" . $arg . "\r\n";
            }

            return $out;
        };

        // 单行 Lua：先 KEYS 再 DEL，回复是 :N 一行，省掉 RESP 数组解析；#k 判空避免 unpack({}) 报错
        $lua = "local k=redis.call('keys',ARGV[1]) if #k>0 then return redis.call('del',unpack(k)) end return 0";

        fwrite($socket, $command(['SELECT', (string)self::redisDb()]));
        fgets($socket);
        fwrite($socket, $command(['EVAL', $lua, '0', 'aetherupload:*']));
        fgets($socket);
        fclose($socket);
    }

    /** 幂等准备应用：骨架 → 依赖 → 本插件 → app-setup.php（装各插件 + 改写配置） */
    public static function ensure(): void
    {
        $root = self::root();

        if ( ! is_file($root . '/vendor/autoload.php') ) {
            self::mkdir(dirname($root));
            self::run(
                'composer create-project workerman/webman ' . escapeshellarg($root) . ' --no-interaction --prefer-dist --no-progress',
                dirname($root)
            );
        }

        self::requirePackages($root);

        if ( ! is_dir($root . '/vendor/erikwang2013/aetherupload-webman') ) {
            self::run('composer config repositories.aetherupload path ' . escapeshellarg(self::repoRoot()) . ' --no-interaction', $root);

            // 不关脚本：webman 的 post-package-install（support\Plugin::install）就是真实用户的安装路径，
            // 插件必须能在 composer 进程里自己完成安装（Install::install() 会在未绑定时自绑 webman 适配器，
            // 读不到宿主 groups 时回落插件自带配置）。E2E 要跑的正是这条路。
            self::run('composer require erikwang2013/aetherupload-webman:@dev --no-interaction --no-progress', $root);
        }

        // 只跑一遍就够：Install::install() 在宿主 config() 读不到 groups/root_dir 时会回落到
        // 插件自带的 config/app.php，因此即便是全新应用（插件配置是本次才复制进去的），
        // storage/app/aetherupload/<group_dir> 也能建出来 —— PartialResource::createGroupSubDir()
        // 用的是非递归 mkdir，父目录必须已存在，否则之后每次 preprocess 都只回笼统的 upload_error。
        self::setup($root);
    }

    public static function start(): void
    {
        if ( self::isUp() ) {
            return;
        }

        // -d 让 master 进程后台常驻；注意它即使绑定端口失败也可能 exit 0（子进程里才报错），
        // 所以必须自己探活，并把 start 的输出一起带进错误信息
        $output = self::run('php start.php start -d', self::root(), true);

        $deadline = microtime(true) + 30;

        while ( microtime(true) < $deadline ) {
            if ( self::isUp() ) {
                return;
            }
            usleep(200000);
        }

        throw new RuntimeException(
            'webman 未在 30s 内就绪，端口 ' . self::port() . "\n--- start.php 输出 ---\n" . $output
            . self::tail(self::root() . '/runtime/logs/stdout.log')
            . self::tail(self::root() . '/runtime/logs/webman.log')
        );
    }

    public static function stop(): void
    {
        if ( ! is_file(self::root() . '/start.php') ) {
            return;
        }

        // 服务不是本次进程起的（复用了上一次的）也要收干净，否则下一次 start 会因端口占用失败
        self::run('php start.php stop', self::root(), true);
    }

    public static function isUp(): bool
    {
        $socket = @fsockopen('127.0.0.1', self::port(), $errno, $errstr, 1);

        if ( $socket === false ) {
            return false;
        }

        fclose($socket);

        // 端口通还不够：worker 必须已经加载完路由（否则第一个请求会 404/超时）
        $response = self::http('GET', '/aetherupload-e2e-readiness-probe');

        return $response['status'] === 404;
    }

    /** 起一个最简 HTTP 请求（只给就绪探测与错误诊断用，业务请求走测试类里的 curl） */
    private static function http(string $method, string $path): array
    {
        $ch = curl_init(self::baseUrl() . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_TIMEOUT        => 5,
        ]);
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        return ['status' => $status, 'body' => (string)$body];
    }

    // ------------------------------------------------------------------ 准备

    /** 装 E2E 应用自己的依赖（不进主包 composer.json）；缺什么装什么 */
    private static function requirePackages(string $root): void
    {
        $installed = (string)@file_get_contents($root . '/composer.json');

        // webman/console：app/command 下的命令壳继承 Symfony Command，php webman 也要它
        // webman/redis：插件适配器走 support\Redis，秒传（第 8 条）必须有它
        foreach ( ['webman/console', 'webman/redis'] as $package ) {
            if ( strpos($installed, '"' . $package . '"') !== false ) {
                continue;
            }

            // 同样走真实安装路径（脚本里的 support\Plugin::install 负责把插件配置复制进 config/plugin/）
            self::run('composer require ' . $package . ' --no-interaction --no-progress', $root);
            self::setup($root);
        }
    }

    /** 在应用进程里执行 app-setup.php 改写配置（要用 webman 的全局函数，必须在应用里跑） */
    private static function setup(string $root): void
    {
        self::run('php ' . escapeshellarg(__DIR__ . '/app-setup.php'), $root);
    }

    // ------------------------------------------------------------------ 进程

    private static function run(string $command, string $cwd, bool $ignoreFailure = false): string
    {
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open($command, $descriptors, $pipes, $cwd, null);

        if ( ! is_resource($process) ) {
            throw new RuntimeException('无法执行：' . $command);
        }

        $stdout = (string)stream_get_contents($pipes[1]);
        $stderr = (string)stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);

        if ( $code !== 0 && ! $ignoreFailure ) {
            throw new RuntimeException(
                "命令失败（exit {$code}）：{$command}\n" . substr($stdout . $stderr, -4000)
            );
        }

        return $stdout . $stderr;
    }

    private static function mkdir(string $dir): void
    {
        if ( ! is_dir($dir) && ! mkdir($dir, 0755, true) && ! is_dir($dir) ) {
            throw new RuntimeException('无法创建目录：' . $dir);
        }
    }

    private static function tail(string $file, int $lines = 20): string
    {
        if ( ! is_file($file) ) {
            return '';
        }

        $content = (string)file_get_contents($file);
        $all = explode("\n", $content);

        return "\n--- " . $file . " ---\n" . implode("\n", array_slice($all, -$lines)) . "\n";
    }
}
