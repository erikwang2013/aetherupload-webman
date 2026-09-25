<?php

namespace AetherUpload\Tests\Integration\Symfony;

use RuntimeException;

/**
 * Symfony 端到端测试的应用准备器：把 app/ 骨架复制到临时目录，装上一个真的 Symfony 应用
 * （framework-bundle + console + browser-kit），再用真实应用进程跑 aetherupload:groups / :publish。
 *
 * 与 webman 那套的区别：这里不起 HTTP 服务，请求由真 HttpKernel 在测试进程内处理
 * （KernelBrowser 把 Request 交给真 Router/Controller/Response 链，返回的也是真 Response 对象）。
 *
 * 幂等：已就绪的应用直接复用（重复跑测试不会重复 composer install）。
 * 可用环境变量：
 *   AETHERA_E2E_APP        应用目录（默认 sys_get_temp_dir()/aetherupload-symfony-e2e）
 *   AETHERA_E2E_REDIS      '0' 强制视为无 redis（第 8 条跳过）；默认自动探测
 *   AETHERA_E2E_REDIS_HOST / _PORT / _DB   redis 连接参数（默认 127.0.0.1:6379 db 8）
 */
final class App
{
    /** 端到端覆盖配置的文件名，排在 aetherupload.yaml（publish 生成）之后被装载，用于叠加测试所需的值 */
    const OVERRIDE_FILE = 'zz_aetherupload_e2e.yaml';

    /** redis 客户端服务定义（仅在有可用客户端时写盘） */
    const REDIS_FILE = 'redis.yaml';

    private function __construct()
    {
    }

    public static function root(): string
    {
        $dir = getenv('AETHERA_E2E_APP');

        return $dir ? rtrim($dir, '/') : sys_get_temp_dir() . '/aetherupload-symfony-e2e';
    }

    /** 本仓库根目录（path 仓库指向它，装进临时应用的是当前工作区，不是发布版） */
    public static function repoRoot(): string
    {
        return \dirname(__DIR__, 3);
    }

    public static function probeLog(): string
    {
        return self::root() . '/var/aetherupload-probe.log';
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
     * 默认 db 8：六套框架的 E2E 会并行跑，各家用不同的库，
     * 免得别家的 flush 落在自己第 8 条两次上传之间，把秒传打成随机假红。
     */
    public static function redisDb(): int
    {
        return (int)(getenv('AETHERA_E2E_REDIS_DB') ?: 8);
    }

    /** redis 服务是否可用；AETHERA_E2E_REDIS=0 可强制关掉 */
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
     * 秒传（第 8 条）是否可用：既要有 redis 服务，也要有能装的客户端。
     *
     * Symfony 框架本身不带 redis，客户端得由应用提供；这里用 phpredis（ext-redis），
     * 没有扩展时适配器会退回 NullRedis（一调用就抛），此时秒传不可用。
     */
    public static function redisUsable(): bool
    {
        return self::redisAvailable() && class_exists(\Redis::class);
    }

    /** 幂等准备应用：骨架 → 依赖 → 本插件 → 命令（建目录 / 分发资源与配置）→ 测试用配置覆盖 */
    public static function ensure(): void
    {
        $root = self::root();

        self::assertTargetIsOurs($root);
        self::copySkeleton($root);
        self::mkdir($root . '/var');
        self::bootstrapComposerJson($root);

        if ( ! is_file($root . '/vendor/autoload.php') ) {
            self::run('composer install --no-interaction --no-progress', $root);
        }

        if ( ! is_dir($root . '/vendor/erikwang2013/aetherupload-webman') ) {
            self::run('composer config repositories.aetherupload path ' . escapeshellarg(self::repoRoot()) . ' --no-interaction', $root);
            self::run('composer require erikwang2013/aetherupload-webman:@dev --no-interaction --no-progress', $root);
        }

        // 分组目录：PartialResource::createGroupSubDir() 只做非递归 mkdir，父目录必须已存在，
        // 否则每次 preprocess 都只会回一个笼统的 upload_error
        self::console($root, 'aetherupload:groups');

        // 语言文件 / 前端资源 / 配置样例（已存在的文件不覆盖）
        self::console($root, 'aetherupload:publish');

        // publish 之后再用发布出来的配置启动一次：样例配置若有问题（改坏的 YAML、未声明的键）在这里就会炸，
        // 而不是等到测试里表现成莫名其妙的 500
        self::console($root, 'aetherupload:groups');

        // clean 不依赖外部服务，顺手做一次真实清理
        self::console($root, 'aetherupload:clean 30');

        // 覆盖配置 + redis 客户端服务定义（末尾会清掉编译缓存）
        self::configure($root);

        // build 必须排在 configure() 之后：它要真读写 Redis，而「公开的 Redis 客户端服务」
        // 是 configure() 刚写进 config/packages 的 —— 全新目录上先跑 build，容器里没有该服务，
        // 适配器退回 NullRedis（一调用就抛），命令会如实报错退出 1
        if ( self::redisUsable() ) {
            self::console($root, 'aetherupload:build');
        }
    }

    /** 覆盖配置：把测试需要的值叠加到 publish 生成的样例之上（同名段落后者胜） */
    private static function configure(string $root): void
    {
        $redis = self::redisUsable();
        $packages = $root . '/config/packages';
        $yaml = "aetherupload:\n"
            . "    instant_completion: " . ($redis ? 'true' : 'false') . "\n"
            . "    groups:\n"
            . "        file:\n"
            . "            event_upload_complete: true\n";

        // 内联注释说明这两行是测试加的，避免读者以为插件默认如此
        file_put_contents(
            $packages . '/' . self::OVERRIDE_FILE,
            "# 由 tests/Integration/symfony/App.php 写入：端到端测试的配置覆盖\n"
            . "# instant_completion 随本机 redis 可用性开关；event_upload_complete 打开事件探针\n"
            . $yaml
        );

        // 客户端服务必须**公开**：适配器从容器外部只能 get() 到公开服务
        // （私有服务会 get() 失败，适配器于是退回 NullRedis，秒传静默失效）
        $redisFile = $packages . '/' . self::REDIS_FILE;

        if ( $redis ) {
            file_put_contents($redisFile, sprintf(
                "# 由 tests/Integration/symfony/App.php 写入：秒传（第 8 条）用的 phpredis 客户端\n"
                . "services:\n"
                . "    Redis:\n"
                . "        class: Redis\n"
                . "        public: true\n"
                . "        calls:\n"
                . "            - connect: ['%s', %d, 2]\n"
                . "            - select: [%d]\n",
                self::redisHost(),
                self::redisPort(),
                self::redisDb()
            ));
        } elseif ( is_file($redisFile) ) {
            unlink($redisFile); // 上一轮有 redis、这一轮没有：别让过期的服务定义把测试带偏
        }

        // 上面这几个文件是刚写进去的，而容器在 publish/groups 那两次控制台调用里就已经编译进 var/cache 了。
        // Symfony 在非 debug 下**不会**因为配置变了就重建容器（prod 语义：改配置要清缓存），
        // 不删缓存的话这一轮写的 instant_completion / event_upload_complete / Redis 服务全部不会被读到
        self::removeDir($root . '/var/cache');
    }

    /**
     * 目标目录必须是本套件建的 Symfony 骨架 —— 动手之前先校验。
     *
     * 骨架是**覆盖式**复制进去的：AETHERA_E2E_APP 一不小心指到别的应用（例如 webman 那套的
     * E2E 目录），就会把人家的应用改花。这里认 composer.json 里的 framework-bundle：
     * 目录是空的（没 composer.json）照常准备，已经有不属于本套件的 composer.json 则直接拒绝。
     */
    private static function assertTargetIsOurs(string $root): void
    {
        $file = $root . '/composer.json';

        if ( ! is_file($file) ) {
            return;
        }

        if ( strpos((string)file_get_contents($file), 'symfony/framework-bundle') === false ) {
            throw new RuntimeException(
                'AETHERA_E2E_APP 指向的不是本套件建的 Symfony 应用：' . $file . ' 里没有 symfony/framework-bundle。'
                . '换一个空目录，或删掉该目录后重跑'
            );
        }
    }

    /** app/ 骨架里有而临时应用里没有（或被改坏）的文件，逐份覆盖 */
    private static function copySkeleton(string $root): void
    {
        self::copyDir(__DIR__ . '/app', $root);
    }

    /**
     * 应用根 composer.json 只在「缺失」或「还没要求本插件」时才用模板重新生成：
     * composer config/require 会往它里面写 path 仓库与依赖，直接覆盖会把那些改动抹掉。
     * 模板特意不叫 composer.json，免得被 copySkeleton() 当成骨架文件覆盖回去。
     */
    private static function bootstrapComposerJson(string $root): void
    {
        $file = $root . '/composer.json';
        $current = is_file($file) ? (string)file_get_contents($file) : '';

        if ( strpos($current, 'erikwang2013/aetherupload-webman') === false ) {
            copy(__DIR__ . '/app/composer.template.json', $file);
        }
    }

    private static function copyDir(string $from, string $to): void
    {
        self::mkdir($to);

        foreach ( scandir($from) ?: [] as $entry ) {
            if ( $entry === '.' || $entry === '..' ) {
                continue;
            }

            $source = $from . '/' . $entry;
            $target = $to . '/' . $entry;

            if ( is_dir($source) ) {
                self::copyDir($source, $target);
            } else {
                copy($source, $target);
            }
        }
    }

    // ------------------------------------------------------------------ 进程

    /**
     * 在应用进程里执行一条控制台命令（应用必须真的能用，因此这里走 bin/console 而不是进程内直接调类）。
     * 返回命令的 stdout+stderr，供用例断言命令输出（如 publish 的幂等行为）。
     */
    public static function console(string $root, string $command): string
    {
        return self::run('php bin/console ' . $command, $root);
    }

    private static function run(string $command, string $cwd): string
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

        if ( $code !== 0 ) {
            throw new RuntimeException(
                "命令失败（exit {$code}）：{$command}\n" . substr($stdout . $stderr, -4000)
            );
        }

        return $stdout . $stderr;
    }

    private static function removeDir(string $dir): void
    {
        if ( ! is_dir($dir) ) {
            return;
        }

        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir . '/' . $entry;

            if (is_dir($path)) {
                self::removeDir($path);
            } else {
                @unlink($path);
            }
        }

        @rmdir($dir);
    }

    private static function mkdir(string $dir): void
    {
        if ( ! is_dir($dir) && ! mkdir($dir, 0755, true) && ! is_dir($dir) ) {
            throw new RuntimeException('无法创建目录：' . $dir);
        }
    }
}
