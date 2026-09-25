<?php

namespace AetherUpload\Tests\Integration\Slim;

use RuntimeException;

/**
 * Slim 端到端测试的应用准备器：真装一个 Slim 骨架（slim/slim + slim/psr7 + predis + symfony/console
 * + 本包）并生成真实入口，然后由测试类用 `$app->handle($request)` 在进程内跑完整中间件栈。
 *
 * 与 webman 的 E2E 不同，这里不需要起 HTTP 服务：Slim 的 `handle()` 就是它的全部运行时。
 *
 * 幂等：已就绪的骨架直接复用（composer.json 没变就不重跑 composer）。
 * 可用环境变量：
 *   AETHERA_SLIM_APP    应用目录（默认 sys_get_temp_dir()/aetherupload-slim-e2e）
 *   AETHERA_SLIM_REDIS  '0' 强制视为无 redis（第 8 条跳过）；默认自动探测
 *   AETHERA_SLIM_REDIS_HOST / _PORT / _DB   默认 127.0.0.1:6379，db 6（webman 的 E2E 用 7，互不干扰）
 */
final class App
{
    /** 骨架的 composer 约束；PHP 8.0 下 symfony/console 会解析到 6.x，8.2+ 解析到 7.x */
    const PACKAGES = [
        'slim/slim'                              => '^4.0',
        'slim/psr7'                              => '^1.6',
        'predis/predis'                          => '^2.0',
        'symfony/console'                        => '^6.0 || ^7.0',
        'erikwang2013/aetherupload-webman'       => '@dev',
    ];

    /** @var \Slim\App|null 进程内唯一的应用实例（handle() 可重复调用） */
    private static $app;

    private function __construct()
    {
    }

    public static function root(): string
    {
        $dir = getenv('AETHERA_SLIM_APP');

        return $dir ? rtrim($dir, '/') : sys_get_temp_dir() . '/aetherupload-slim-e2e';
    }

    /** 本仓库根目录（path 仓库指向它：装进骨架的是当前工作区，不是发布版） */
    public static function repoRoot(): string
    {
        return dirname(__DIR__, 3);
    }

    public static function probeLog(): string
    {
        return self::root() . '/runtime/aetherupload-probe.log';
    }

    public static function redisHost(): string
    {
        return getenv('AETHERA_SLIM_REDIS_HOST') ?: '127.0.0.1';
    }

    public static function redisPort(): int
    {
        return (int)(getenv('AETHERA_SLIM_REDIS_PORT') ?: 6379);
    }

    /** E2E 专用库，避免与 webman 的 E2E（db 7）互相污染 */
    public static function redisDb(): int
    {
        return (int)(getenv('AETHERA_SLIM_REDIS_DB') ?: 6);
    }

    /** redis 是否可用（第 8 条秒传的前提）；AETHERA_SLIM_REDIS=0 可强制关掉 */
    public static function redisAvailable(): bool
    {
        if ( getenv('AETHERA_SLIM_REDIS') === '0' ) {
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
     * 建好可测应用并返回它（进程内只建一次）。
     *
     * 构建代码就是骨架里的 app.php —— 与 public/index.php 用的是同一份，测试与实际入口不会漂移。
     */
    public static function app(): \Slim\App
    {
        if ( self::$app === null ) {
            require self::root() . '/vendor/autoload.php';

            /** @var \Slim\App $app */
            $app = require self::root() . '/app.php';

            self::$app = $app;
        }

        return self::$app;
    }

    /** 幂等准备：骨架 → 依赖 → 本包 → 应用文件 → 翻译 → 清 redis */
    public static function ensure(): void
    {
        $root = self::root();

        self::mkdir($root);
        self::mkdir($root . '/runtime');

        $composerJson = self::composerJson();
        $marker = $root . '/.provisioned';
        $fingerprint = md5($composerJson);

        if ( ! is_file($root . '/vendor/autoload.php') || @file_get_contents($marker) !== $fingerprint ) {
            file_put_contents($root . '/composer.json', $composerJson);

            // 不带 --no-scripts/--no-plugins：本包是 type=library，composer.json 里既没有 scripts
            // 也没有 extra，跑不跑脚本对结果没影响 —— 那就按使用者的真实路径装（执行脚本），
            // 免得 harness 悄悄比用户路径更宽松
            self::run('composer update --no-interaction --no-progress', $root);
            file_put_contents($marker, $fingerprint);
        }

        self::writeFiles($root);
        self::installTranslations($root);
        self::createGroupDirectories($root);

        // 骨架装配完立刻建应用：一是让 Slim\Psr7\* 这些类在整个进程里可见（它们在骨架的 vendor 里，
        // 仓库自己的 autoloader 找不到），二是把 Runtime 绑定好 —— 用例可以直接用 Runtime::adapter()
        // 与各端口，不必先发一个请求。应用实例本身仍只建一次。
        self::app();

        // 必须在 app() 之后：predis 也在骨架的 vendor 里，autoloader 没装进来之前连类都找不到
        self::flushRedis();
    }

    // ------------------------------------------------------------------ 生成应用文件

    private static function composerJson(): string
    {
        return json_encode([
            'name'         => 'aetherupload/slim-e2e',
            'description'  => 'tests/Integration/slim 的临时骨架（由 App.php 生成，勿手工维护）',
            'type'         => 'project',
            'license'      => 'MIT',
            'require'      => self::PACKAGES,
            // 本包未发布 @dev 版本，走 path 仓库直接指向工作区（composer 会做符号链接，
            // 所以改仓库源码不需要重装）
            'repositories' => [
                ['type' => 'path', 'url' => self::repoRoot(), 'options' => ['symlink' => true]],
            ],
            'minimum-stability' => 'dev',
            'prefer-stable'     => true,
            'config'            => ['allow-plugins' => false],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    }

    private static function writeFiles(string $root): void
    {
        self::mkdir($root . '/config');
        self::mkdir($root . '/public');
        self::mkdir($root . '/bin');

        // 配置：以包内默认值为基准，只改测试需要开关的两项
        file_put_contents($root . '/config/aetherupload.php', strtr(<<<'PHP'
<?php

// 由 tests/Integration/slim/App.php 生成：包内默认值 + E2E 需要的两个开关
$config = require __DIR__ . '/../vendor/erikwang2013/aetherupload-webman/config/aetherupload.php';

// 第 7 条事件探针要求本分组的 upload_complete 事件打开；
// before_upload_complete 也打开：内核只有这两个派发点，两个都要有「监听器抛异常不冒泡」的实测
$config['groups']['file']['event_upload_complete'] = true;
$config['groups']['file']['event_before_upload_complete'] = true;

// 第 8 条秒传：只在真有 redis 时打开（开着却没有客户端会直接抛异常）
$config['instant_completion'] = __REDIS__;

// symfony 式翻译器才需要 fallback 配置，Slim 适配器刻意不做 fallback（第 6 条依赖这一点）

return $config;
PHP, ['__REDIS__' => self::redisAvailable() ? 'true' : 'false']));

        file_put_contents($root . '/app.php', self::appPhp());
        file_put_contents($root . '/public/index.php', <<<'PHP'
<?php

// 真实入口：与测试共用同一份构建代码（app.php），保证「测试里跑通的」就是「用户拿到的」
$app = require __DIR__ . '/../app.php';

$app->run();
PHP);

        file_put_contents($root . '/bin/aetherupload', strtr(<<<'PHP'
#!/usr/bin/env php
<?php

// 控制台入口：先绑适配器（Runner 要拿 base_path 与配置），再跑 Application
require __DIR__ . '/../vendor/autoload.php';

\AetherUpload\Adapter\Slim\Bootstrap::bind(
    require __DIR__ . '/../config/aetherupload.php',
    dirname(__DIR__),
    ['redis' => __REDIS_EXPR__]   // aetherupload:build 要用
);

exit((new \AetherUpload\Adapter\Slim\Console\Application())->run());
PHP, ['__REDIS_EXPR__' => self::redisExpression()]));
        @chmod($root . '/bin/aetherupload', 0755);
    }

    /** 与 public/index.php 共用的应用构建代码（E2E 测试也走这里） */
    private static function appPhp(): string
    {
        return strtr(<<<'PHP'
<?php

// 由 tests/Integration/slim/App.php 生成。public/index.php 与 E2E 测试都从这里建应用。
require __DIR__ . '/vendor/autoload.php';

return \AetherUpload\Adapter\Slim\Bootstrap::create([
    'config'    => require __DIR__ . '/config/aetherupload.php',
    // base_path 必须显式给：root_dir、_header、翻译目录都相对它解析
    'base_path' => __DIR__,
    'redis'     => __REDIS_EXPR__,
    // 第 7 条事件探针：一个抛异常（必须不影响响应），一个记录（必须仍被调用）。
    // 内核两个派发点都挂上抛异常的监听器 —— 桥的 catch 必须覆盖全部派发点，不能只挡住 upload_complete
    'listeners' => [
        'aetherupload.before_upload_complete' => [
            static function (): void {
                throw new \RuntimeException('E2E probe (before): this listener exception must not bubble');
            },
        ],
        'aetherupload.upload_complete' => [
            static function (): void {
                throw new \RuntimeException('E2E probe: this listener exception must not bubble');
            },
            static function (\AetherUpload\Adapter\Slim\Event $event): void {
                @file_put_contents(
                    __DIR__ . '/runtime/aetherupload-probe.log',
                    $event->name . ' ' . (isset($event->payload->name) ? $event->payload->name : '?') . "\n",
                    FILE_APPEND
                );
            },
        ],
    ],
]);
PHP, ['__REDIS_EXPR__' => self::redisExpression()]);
    }

    /** redis 客户端表达式（无 redis 时给 null ⇒ 适配器用 NullRedis）；app.php 与控制台入口共用 */
    private static function redisExpression(): string
    {
        if ( ! self::redisAvailable() ) {
            return 'null';
        }

        return "new \\Predis\\Client(['scheme' => 'tcp', 'host' => " . var_export(self::redisHost(), true)
            . ", 'port' => " . self::redisPort() . ", 'database' => " . self::redisDb() . "])";
    }

    /** 语言文件落点与 webman 的 Install 关系一致：{base_path}/resource/translations/aetherupload */
    private static function installTranslations(string $root): void
    {
        $target = $root . '/resource/translations/aetherupload';

        (new \AetherUpload\Kernel\Filesystem())->copyDir(self::repoRoot() . '/translations', $target);
    }

    /**
     * 建 root_dir / _header / 各分组目录。
     *
     * 内核的 createGroupSubDir() 是非递归 mkdir，缺目录时每次 preprocess 都会失败（create_subfolder_fail）。
     * webman 靠 composer require 时的 Install::install() 建；其余框架的统一入口就是控制台命令
     * `aetherupload:groups`（src/Console/ListGroupsRunner.php 顺手补齐目录）—— 这里就用它，
     * 于是控制台交付物也一并被这条 E2E 路径覆盖（退出码非 0 会直接抛错）。
     */
    private static function createGroupDirectories(string $root): void
    {
        self::run('php bin/aetherupload aetherupload:groups --no-ansi', $root);
    }

    /**
     * 清掉 E2E 专用库里的秒传索引。
     *
     * 必须清：上一次跑测留下的 aetherupload:resource:* 会让第 1 条的 preprocess 直接走秒传短路，
     * 于是 .part / _header 不会出现，断言看到的就不是「首次上传」了。
     */
    private static function flushRedis(): void
    {
        if ( ! self::redisAvailable() ) {
            return;
        }

        try {
            $client = new \Predis\Client([
                'scheme'   => 'tcp',
                'host'     => self::redisHost(),
                'port'     => self::redisPort(),
                'database' => self::redisDb(),
            ]);

            $client->flushdb();
        } catch ( \Throwable $e ) {
            // redis 半死不活时不做致命处理：测试会用第 8 条跳过如实报告
            fwrite(STDERR, '[slim-e2e] flushdb 失败：' . $e->getMessage() . "\n");
        }
    }

    // ------------------------------------------------------------------ 进程与文件

    public static function mkdir(string $dir): void
    {
        if ( ! is_dir($dir) && ! mkdir($dir, 0755, true) && ! is_dir($dir) ) {
            throw new RuntimeException('无法创建目录：' . $dir);
        }
    }

    private static function run(string $command, string $cwd): void
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
    }
}
