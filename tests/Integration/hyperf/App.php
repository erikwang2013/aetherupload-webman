<?php

namespace AetherUpload\Tests\Integration\Hyperf;

use RuntimeException;

/**
 * Hyperf 端到端测试的应用准备器：真装一个 Hyperf 骨架 + 真起 swoole HTTP 服务 + curl 打真请求。
 *
 * 与 webman 那份的分工相同（见 tests/Integration/webman/App.php），差别只在启动方式：
 * Hyperf 的 CLI 入口是 bin/hyperf.php，没有 `start -d` 那种后台开关，所以这里用 nohup 起进程、
 * 用 swoole 写出的 runtime/hyperf.pid 收工。
 *
 * 幂等：已就绪的应用直接复用（重复跑测试不重复 composer update）。
 * 可用环境变量：
 *   AETHERA_E2E_APP    应用目录（默认 sys_get_temp_dir()/aetherupload-hyperf-e2e）
 *   AETHERA_E2E_PORT   监听端口（默认 19501）
 *   AETHERA_E2E_REDIS  '0' 强制视为无 redis（第 8 条跳过）；默认自动探测
 *   AETHERA_E2E_REDIS_HOST / _PORT / _DB   redis 连接参数（默认 127.0.0.1:6379 db 3）
 *   AETHERA_E2E_KEEP   '1' 测试结束后不停止服务（排查用）
 */
final class App
{
    /** 就绪探测路由，由 skeleton/config/routes.php 注册（顺带证明宿主自己的路由与插件路由共存） */
    const READINESS_PATH = '/aetherupload-e2e-app-route';

    /** 测试用的第二个分组名（协程交错用例要两份不同的分组配置；第一个分组是 FlowAssertions 的 'file'） */
    const PROBE_GROUP = 'video';

    private function __construct()
    {
    }

    public static function root(): string
    {
        $dir = getenv('AETHERA_E2E_APP');

        return $dir ? rtrim($dir, '/') : sys_get_temp_dir() . '/aetherupload-hyperf-e2e';
    }

    /** 本仓库根目录（path 仓库指向它，装进临时骨架的是当前工作区，不是发布版） */
    public static function repoRoot(): string
    {
        return dirname(__DIR__, 3);
    }

    public static function port(): int
    {
        return (int)(getenv('AETHERA_E2E_PORT') ?: 19501);
    }

    public static function baseUrl(): string
    {
        return 'http://127.0.0.1:' . self::port();
    }

    public static function probeLog(): string
    {
        return self::root() . '/runtime/aetherupload-probe.log';
    }

    public static function pidFile(): string
    {
        return self::root() . '/runtime/hyperf.pid';
    }

    public static function startLog(): string
    {
        return self::root() . '/runtime/hyperf-start.log';
    }

    public static function redisHost(): string
    {
        return getenv('AETHERA_E2E_REDIS_HOST') ?: '127.0.0.1';
    }

    public static function redisPort(): int
    {
        return (int)(getenv('AETHERA_E2E_REDIS_PORT') ?: 6379);
    }

    public static function redisDb(): int
    {
        // 六家的集成测试并行跑，各占一个 db，免得互相 flush 把秒传用例打红
        // （webman 7 · laravel 4 · thinkphp 5 · hyperf 3 · slim 6 · symfony 8 · yii 9）
        return (int)(getenv('AETHERA_E2E_REDIS_DB') ?: 3);
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

    // ------------------------------------------------------------------ 准备

    /** 幂等准备应用：拷骨架 → 装依赖（含 path 仓库里的本包）→ 发布配置 → 改写配置 */
    public static function ensure(): void
    {
        $root = self::root();

        // 骨架的配置里有 BASE_PATH（swoole 的 pid 文件路径）；bin/hyperf.php 里由入口定义，
        // 测试进程里得自己来 —— 必须先于任何 require 配置的动作
        if ( ! defined('BASE_PATH') ) {
            define('BASE_PATH', $root);
        }

        if ( ! is_file($root . '/vendor/autoload.php') ) {
            self::mkdir(dirname($root));
            self::copyDir(__DIR__ . '/skeleton', $root);
            self::mkdir($root . '/runtime');

            // 装进来的是当前工作区（改了 src/ 立刻生效，不必先发版）
            self::run('composer config repositories.aetherupload path ' . escapeshellarg(self::repoRoot()) . ' --no-interaction', $root);
            self::run('composer update --no-interaction --no-progress', $root);
        }

        // 由插件自己的命令发布配置/语言文件/前端资源，走的就是宿主会走的那条路
        if ( ! is_file($root . '/config/autoload/aetherupload.php') ) {
            self::run(self::hyperf('aetherupload:publish'), $root);
        }

        self::patchConfigs($root);

        // 按（已改写的）分组配置补齐 storage/app/aetherupload/<group_dir>：
        // PartialResource::createGroupSubDir() 是非递归 mkdir，父目录不存在就直接 upload_error。
        // 命令幂等，每次 ensure 跑一遍。
        self::run(self::hyperf('aetherupload:groups'), $root);
    }

    /**
     * 拼一条跑 Hyperf 控制台的 php 命令。
     *
     * 必须带上 XDEBUG_MODE=off：容器缓存（runtime/container）冷启动时 Hyperf 要扫全量注解，
     * 而本机 php.ini 是 xdebug.mode=profile —— 扫描进程会在 ClassLoader::init() 里**无输出地**
     * 退 255（连 error_get_last() 都是 NULL），只有 xdebug 关掉才正常。这与本适配器无关，
     * 但不关掉就只能拿现成的热缓存跑，「从零构建」这条路会被悄悄跳过。
     */
    private static function hyperf(string $arguments): string
    {
        return 'XDEBUG_MODE=off ' . escapeshellarg(PHP_BINARY) . ' bin/hyperf.php ' . $arguments;
    }

    /**
     * 在**独立进程**里跑一个仓库内的 php 脚本，返回 [code, stdout, stderr]。
     *
     * 协程探针必须这么跑：在 phpunit 进程里直接 Co\run 会让 swoole 的协程运行时与 PHPUnit
     * 共处一室 —— 进程在退出时段错误（exit 139，结果都打完了才崩），而且之后同一个进程里的
     * curl 会开始「连接被拒」。分开跑，两边都干净。
     */
    public static function php(string $script, string $arguments = ''): array
    {
        $command = 'XDEBUG_MODE=off ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script)
            . ($arguments === '' ? '' : ' ' . $arguments);

        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open($command, $descriptors, $pipes, self::root(), null);

        if ( ! is_resource($process) ) {
            throw new RuntimeException('无法执行：' . $command);
        }

        $stdout = (string)stream_get_contents($pipes[1]);
        $stderr = (string)stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return ['code' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
    }

    /**
     * 在**当前进程**里把 E2E 应用的配置与容器装起来（只给独立进程跑的协程探针用）。
     *
     * 刻意复用 root() 那一份应用：分组配置（含 video）与 HTTP 服务读到的是同一份，
     * 协程用例测的才是真实配置而不是另造的一份。
     */
    public static function bootKernel(): void
    {
        static $booted = false;

        if ( $booted ) {
            return;
        }

        self::ensure();

        require_once self::root() . '/vendor/autoload.php';

        if ( ! defined('BASE_PATH') ) {
            define('BASE_PATH', self::root());
        }
        if ( ! defined('SWOOLE_HOOK_FLAGS') ) {
            define('SWOOLE_HOOK_FLAGS', \Hyperf\Engine\DefaultOption::hookFlags());
        }

        \Hyperf\Di\ClassLoader::init();

        // config/container.php 里 BASE_PATH 已经定好，构造出的容器与 bin/hyperf.php 起服务时的那份同源
        require self::root() . '/config/container.php';

        // 服务端在 BootApplication 上做这件事（见 Adapter/Hyperf/Listener/BootApplicationListener）；
        // 测试进程没有那个事件，这里显式绑一次，绑的仍是同一个适配器
        \AetherUpload\Runtime::bind(new \AetherUpload\Adapter\Hyperf\HyperfAdapter());

        $booted = true;
    }

    /** 起服务（幂等：已在跑就直接返回） */
    public static function start(): void
    {
        if ( self::isUp() ) {
            return;
        }

        $root = self::root();
        self::mkdir($root . '/runtime');

        // 时区必须与测试进程一致，否则跨月边界时 groupSubDir 会漂（断言按月生成子目录）；
        // XDEBUG_MODE=off 的原因见 hyperf()
        $command = 'XDEBUG_MODE=off nohup ' . escapeshellarg(PHP_BINARY)
            . ' -d date.timezone=' . escapeshellarg(date_default_timezone_get())
            . ' ' . escapeshellarg($root . '/bin/hyperf.php') . ' start'
            . ' > ' . escapeshellarg(self::startLog()) . ' 2>&1 & echo $!';

        self::run($command, $root, true);

        $deadline = microtime(true) + 30;

        while ( microtime(true) < $deadline ) {
            if ( self::isUp() ) {
                return;
            }
            usleep(200000);
        }

        throw new RuntimeException(
            'Hyperf 未在 30s 内就绪，端口 ' . self::port() . self::tail(self::startLog())
        );
    }

    /** 停服务：swoole 的 pid 文件是唯一可靠来源（进程不是本进程的子进程，收不了尸） */
    public static function stop(): void
    {
        $pid = (int)self::quiet(static function () {
            return is_file(self::pidFile()) ? file_get_contents(self::pidFile()) : '';
        });

        if ( $pid > 0 && function_exists('posix_kill') && posix_kill($pid, 0) ) {
            posix_kill($pid, defined('SIGTERM') ? SIGTERM : 15);

            $deadline = microtime(true) + 10;
            while ( microtime(true) < $deadline && posix_kill($pid, 0) ) {
                usleep(200000);
            }

            if ( posix_kill($pid, 0) ) {
                // 兜底：SWOOLE_BASE 下 master 卡住时 SIGTERM 可能不生效，测试进程不能因此挂住
                posix_kill($pid, defined('SIGKILL') ? SIGKILL : 9);
                usleep(500000);
            }
        }

        // 服务不是本次进程起的（复用了上一次的）也要确保端口放开，否则下一次 start 绑不上
        $deadline = microtime(true) + 5;
        while ( microtime(true) < $deadline && self::portOpen() ) {
            usleep(200000);
        }
    }

    /**
     * 端口通 + 路由已就绪。
     *
     * 只看端口是不够的：swoole 先监听、后加载路由，抢在中间发请求会拿到 404，
     * 于是「就绪」的定义是宿主自己的那条路由能正常应答。
     */
    public static function isUp(): bool
    {
        if ( ! self::portOpen() ) {
            return false;
        }

        $response = self::http('GET', self::READINESS_PATH);

        return $response['status'] === 200 && strpos($response['body'], 'app-route-ok') === 0;
    }

    /** 诊断用：服务端此刻的日期与日期（校验时区确实与测试进程一致，见 start()） */
    public static function serverClock(): string
    {
        return (string)self::http('GET', self::READINESS_PATH)['body'];
    }

    private static function portOpen(): bool
    {
        // 探测失败会发 E_WARNING，而 PHPUnit 的错误处理器把「非测试上下文里的 warning」
        // 直接变成异常（register_shutdown_function 里没有 TestCase 可关联）——
        // 本方法的失败是预期路径（服务还没起/已经停），必须自己吞掉
        $socket = self::quiet(static function () {
            return fsockopen('127.0.0.1', self::port(), $errno, $errstr, 1);
        });

        if ( ! is_resource($socket) ) {
            return false;
        }

        fclose($socket);

        return true;
    }

    /**
     * 把「失败是预期结果」的探测关进自己的错误处理器里跑。
     *
     * @return mixed
     */
    private static function quiet(callable $fn)
    {
        set_error_handler(static function () {
            return true; // 吞掉：不让 PHP 内部处理器与 PHPUnit 的处理器接手
        });

        try {
            return $fn();
        } finally {
            restore_error_handler();
        }
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

    // ------------------------------------------------------------------ 配置改写

    /**
     * 把骨架改成可测状态。全部落在 config/ 里，不改 src/：
     *   aetherupload.php  秒传开关（按 redis 可用性）、file 分组开事件、补一个 video 分组（协程用例要两份配置）
     *   redis.php         连接参数
     *   server.php        端口
     */
    private static function patchConfigs(string $root): void
    {
        // 改写 server.php 要用 require 求值，而里面用了 Hyperf\Server\Server 这类类名常量，
        // 得先把骨架自己的 autoloader 挂上（幂等，CoroutineIsolationTest 也要它）
        require_once $root . '/vendor/autoload.php';

        $plugin = $root . '/config/autoload/aetherupload.php';

        if ( ! is_file($plugin) ) {
            throw new RuntimeException('插件配置未发布，aetherupload:publish 没有生效：' . $plugin);
        }

        self::rewrite($plugin, static function (array $config) {
            $config['instant_completion'] = App::redisAvailable();
            $config['groups']['file']['event_upload_complete'] = true;

            // 第二个分组：group_dir / resource_maxsize 都与 file 不同，
            // 协程交错用例靠这两处差异判断「读到的到底是谁的配置」
            $config['groups'][App::PROBE_GROUP] = [
                'group_dir'                    => App::PROBE_GROUP,
                'resource_maxsize'             => 20971520,
                'resource_extensions'          => ['gif', 'mp4'],
                'event_before_upload_complete' => false,
                'event_upload_complete'        => false,
            ];

            return $config;
        });

        self::rewrite($root . '/config/autoload/redis.php', static function (array $config) {
            $config['default']['host'] = App::redisHost();
            $config['default']['port'] = App::redisPort();
            $config['default']['db'] = App::redisDb();

            return $config;
        });

        self::rewrite($root . '/config/autoload/server.php', static function (array $config) {
            $config['servers'][0]['port'] = App::port();

            return $config;
        });
    }

    /** 用 include + var_export 改写一个返回数组的配置文件（丢注释，但确定性好、不依赖正则） */
    private static function rewrite(string $file, callable $mutate): void
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
            "<?php\n\n// 由 tests/Integration/hyperf 的 E2E 准备脚本改写（原文件见仓库/config 与 skeleton/）\nreturn "
            . var_export($config, true) . ";\n"
        );
    }

    // ------------------------------------------------------------------ 文件与进程

    private static function copyDir(string $source, string $target): void
    {
        self::mkdir($target);

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ( $iterator as $item ) {
            /** @var \SplFileInfo $item */
            $destination = $target . '/' . $iterator->getSubPathName();

            if ( $item->isDir() ) {
                self::mkdir($destination);
            } else {
                copy($item->getPathname(), $destination);
            }
        }
    }

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

    private static function tail(string $file, int $lines = 40): string
    {
        if ( ! is_file($file) ) {
            return '';
        }

        $all = explode("\n", (string)file_get_contents($file));

        return "\n--- " . $file . " ---\n" . implode("\n", array_slice($all, -$lines)) . "\n";
    }
}
