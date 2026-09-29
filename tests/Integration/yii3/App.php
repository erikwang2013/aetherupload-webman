<?php

namespace AetherUpload\Tests\Integration\Yii3;

use HttpSoft\Message\ServerRequestFactory;
use HttpSoft\Message\StreamFactory;
use HttpSoft\Message\UploadedFileFactory;
use RuntimeException;
use Yiisoft\Yii\Runner\Http\HttpApplicationRunner;

/**
 * Yii3 端到端测试的应用准备器：真装一个最小 Yii3 应用（yiisoft/router + router-fastroute +
 * yii-runner-http + yii-runner-console + yii-console + httpsoft/http-message + predis + 本包），
 * 生成真实的配置组、路由文件、入口脚本与 `bin/yii`，然后用**真 HttpApplicationRunner**
 * 在进程内跑完整链路（配置组装 → DI 容器 → bootstrap → 路由 → 中间件 → 动作 → 内核）。
 *
 * 为什么进程内而不是 `php -S`：HttpApplicationRunner 自带 `runAndGetResponse($request)`（它的
 * 用途就是测试），一次请求跑的就是 FPM/常驻运行器里那条完整路径，且响应对象直接可断言。
 * 真 HTTP 那条路由 `ci.sh` 用 `php -S` + curl 单独走一遍，证明入口脚本与发响应那段也是通的。
 *
 * 幂等：已就绪的骨架直接复用（composer.json 没变就不重跑 composer）；配置文件每次重写，
 * 免得 redis 可用性变化后配置与进程内绑定对不上。
 *
 * 环境变量：
 *   AETHERA_YII3_APP           骨架目录（默认 ${TMPDIR}/aetherupload-yii3-e2e）
 *   AETHERA_YII3_PORT          php -S 冒烟用的端口（默认 18789；原生 PHP 用 18788，刻意错开）
 *   AETHERA_E2E_REDIS          '0' 强制视为无 redis（第 8 条跳过并如实报告）
 *   AETHERA_E2E_REDIS_HOST/_PORT/_DB  默认 127.0.0.1:6379 db 11
 *   AETHERA_YII3_REFRESH       '1' 强制重装骨架依赖
 */
final class App
{
    /** 骨架的 composer 约束。PHP 8.1+ 才有 Yii3；本机 CI 用 8.2 */
    const PACKAGES = [
        'yiisoft/router'              => '^4.0',
        'yiisoft/router-fastroute'    => '^4.0',
        'yiisoft/yii-http'            => '^1.1',
        'yiisoft/yii-runner-http'     => '^3.2',
        'yiisoft/yii-runner-console'  => '^2.2',
        'yiisoft/yii-console'         => '^2.4',
        'httpsoft/http-message'       => '^1.1',
        'psr/log'                     => '^3.0',
        'predis/predis'               => '^2.0',
        'erikwang2013/aetherupload-webman' => '@dev',
    ];

    /** @var bool 适配器只绑一次（与真实入口脚本同语义：绑定点在 runner 之前） */
    private static $bound = false;

    private function __construct()
    {
    }

    public static function root(): string
    {
        $dir = getenv('AETHERA_YII3_APP');

        return $dir ? rtrim($dir, '/') : sys_get_temp_dir() . '/aetherupload-yii3-e2e';
    }

    /** 本仓库根目录（path 仓库指向它：装进骨架的是当前工作区，不是发布版） */
    public static function repoRoot(): string
    {
        return dirname(__DIR__, 3);
    }

    public static function port(): int
    {
        return (int)(getenv('AETHERA_YII3_PORT') ?: 18789);
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

    /** E2E 专用库，避免与其余 harness 互相污染（webman 7 · laravel 4 · thinkphp 5 · hyperf 3 · slim 6 · symfony 8 · yii9 · native 10 · yii3 11） */
    public static function redisDb(): int
    {
        return (int)(getenv('AETHERA_E2E_REDIS_DB') ?: 11);
    }

    public static function redisDisabled(): bool
    {
        return getenv('AETHERA_E2E_REDIS') === '0';
    }

    /** redis 是否可用（第 8 条秒传的前提） */
    public static function redisAvailable(): bool
    {
        if ( self::redisDisabled() ) {
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

    public static function connectRedis(): \Predis\Client
    {
        return new \Predis\Client([
            'scheme'   => 'tcp',
            'host'     => self::redisHost(),
            'port'     => self::redisPort(),
            'database' => self::redisDb(),
        ]);
    }

    /**
     * 清掉本库里的秒传记录。
     *
     * 必须清：上一次跑测留下的 aetherupload:* 会让第 1 条的 preprocess 直接走秒传短路，
     * 于是 .part / _header 不会出现，断言看到的就不是「首次上传」了。
     */
    public static function flushInstantCompletionKeys(): void
    {
        if ( ! self::redisAvailable() ) {
            return;
        }

        try {
            $client = self::connectRedis();

            foreach ( $client->keys('aetherupload*') as $key ) {
                $client->del($key);
            }
        } catch ( \Throwable $e ) {
            fwrite(STDERR, '[yii3-e2e] 清 redis 失败：' . $e->getMessage() . "\n");
        }
    }

    // ------------------------------------------------------------------ 应用准备

    /** 幂等准备：骨架 → 依赖 → 本包 → 应用文件 → 翻译与目录 */
    public static function ensure(): void
    {
        $root = self::root();

        foreach ( ['', '/public', '/bin', '/config', '/config/common', '/config/common/di', '/config/web', '/config/web/di', '/runtime'] as $sub ) {
            self::mkdir($root . $sub);
        }

        $composerJson = self::composerJson();
        $marker = $root . '/.provisioned';

        // 指纹带上 PHP 小版本：yiisoft/* 的解析结果随平台走（8.5 下会挑到要求 >= 8.4 的版本），
        // 换了 PHP 却复用同一个 vendor 的话，autoload 会直接抛
        // 「Your Composer dependencies require a PHP version >= 8.4.1」——看着像代码坏了，其实是骨架过期。
        // 带上它，换版本自动重装
        $fingerprint = md5($composerJson . '|php' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION);

        // 配置文件必须在 composer 之前写好：yiisoft/config 是 composer 插件，它在 dump-autoload 时
        // 就要读 config/configuration.php 才能生成合并计划（config/.merge-plan.php）——
        // 顺序反了的话 `composer update` 直接失败，骨架根本装不完
        self::writeFiles($root);

        if ( getenv('AETHERA_YII3_REFRESH') === '1' ) {
            self::run('rm -rf vendor composer.lock', $root);
        }

        if ( ! is_file($root . '/vendor/autoload.php') || @file_get_contents($marker) !== $fingerprint ) {
            file_put_contents($root . '/composer.json', $composerJson);

            self::run('composer update --no-interaction --no-progress', $root);
            file_put_contents($marker, $fingerprint);
        }

        self::boot();

        // 控制台交付物：建目录（内核的 createGroupSubDir 是非递归 mkdir，缺目录时 preprocess 必失败）
        // 顺带证明 bin/yii 真能跑起来
        self::assertConsole('aetherupload/groups', 'aetherupload/groups');
        // 语言文件必须真发布一份：内核按 translationsPath()/aetherupload/{locale}/messages.php 查找
        self::assertConsole('aetherupload/publish', 'aetherupload/publish');

        self::flushInstantCompletionKeys();
    }

    /**
     * 绑定适配器（真实入口脚本里那一行），只绑一次。
     *
     * 进程内跑用例时入口脚本不执行，这一步就是它的等价物：绑定点必须早于任何请求，
     * 且 redis 的可用性按当下探测结果决定（不是配置里写死的旧值）。
     */
    public static function boot(): void
    {
        if ( self::$bound ) {
            return;
        }

        require_once self::root() . '/vendor/autoload.php';

        \AetherUpload\Adapter\Yii3\Bootstrap::bind(
            require self::root() . '/config/aetherupload.php',
            self::root(),
            [
                'redis' => self::redisAvailable() ? static function () { return self::connectRedis(); } : null,

                // 第 7 条事件探针：一个抛异常（必须不影响响应），一个记录（必须仍被调用）。
                // 内核两个派发点都挂上抛异常的监听器 —— 桥的 catch 必须覆盖全部派发点
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
                        static function (\AetherUpload\Adapter\Yii3\Event $event): void {
                            // 副本数必须在**请求进行中**采样：适配器登记的上传临时副本会在请求结束时删除，
                            // 出了请求再数永远数不到（那样「不产生拷贝」这条断言就是个假测试）
                            $copies = glob(sys_get_temp_dir() . '/aetherupload-chunk-*');

                            @file_put_contents(
                                self::probeLog(),
                                $event->name . ' ' . (isset($event->payload->name) ? $event->payload->name : '?')
                                . "\n" . 'copy-probe ' . count($copies === false ? [] : $copies) . "\n",
                                FILE_APPEND
                            );
                        },
                    ],
                ],
            ]
        );

        self::$bound = true;
    }

    // -------------------------------------------------------------------- 发请求

    /**
     * 打一个请求：构造真 PSR-7 请求 → 真 HttpApplicationRunner → 完整链路 → 响应对象。
     *
     * 走的是 runner 为测试准备的 `runAndGetResponse()`：与 `run()` 同一条路径（配置组装、DI 容器、
     * bootstrap、路由中间件、动作），只是不把响应写出去而是返回它，于是状态码/响应头/响应体
     * 都能逐字节断言。
     *
     * @param array<string,mixed>                              $post  表单字段（数组值原样传，内核的类型守卫要看原始形态）
     * @param array<string,array{name:string,content:string}>  $files 字段名 => [文件名, 原始字节]
     * @param array<string,string>                             $headers
     *
     * @return array{status:int,body:string,headers:string[]}
     */
    public static function request(string $method, string $path, array $post = [], array $files = [], array $headers = []): array
    {
        self::boot();

        $request = (new ServerRequestFactory())->createServerRequest($method, self::baseUrl() . $path);

        if ( $post !== [] ) {
            $request = $request->withParsedBody($post);
        }

        $tempUploads = [];

        if ( $files !== [] ) {
            $streamFactory = new StreamFactory();
            $uploadedFileFactory = new UploadedFileFactory();
            $uploaded = [];

            foreach ( $files as $field => $file ) {
                // 真机上 $_FILES.tmp_name 就是一个本地临时文件，这里同形构造：
                // 适配器于是走「流自带本地 uri」那条路，不产生拷贝
                $temp = tempnam(sys_get_temp_dir(), 'aetherupload-e2e-');

                if ( $temp === false ) {
                    throw new RuntimeException('无法创建临时上传文件');
                }

                file_put_contents($temp, $file['content']);
                $tempUploads[] = $temp;

                $uploaded[$field] = $uploadedFileFactory->createUploadedFile(
                    $streamFactory->createStreamFromFile($temp),
                    strlen($file['content']),
                    UPLOAD_ERR_OK,
                    (string)$file['name'],
                    'application/octet-stream'
                );
            }

            $request = $request->withUploadedFiles($uploaded);
        }

        foreach ( $headers as $name => $value ) {
            $request = $request->withHeader($name, $value);
        }

        try {
            $response = (new HttpApplicationRunner(self::root(), false, false))->runAndGetResponse($request);

            $lines = [];

            foreach ( $response->getHeaders() as $name => $values ) {
                foreach ( $values as $value ) {
                    $lines[] = $name . ': ' . $value;
                }
            }

            return [
                'status'  => $response->getStatusCode(),
                'body'    => (string)$response->getBody(),
                'headers' => $lines,
            ];
        } finally {
            foreach ( $tempUploads as $temp ) {
                @unlink($temp);
            }
        }
    }

    /** 跑一条控制台命令（真起一个进程跑 bin/yii），返回 [exit, output] */
    public static function runConsole(string $arguments): array
    {
        $output = [];
        $exit = 0;
        exec(
            escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(self::root() . '/bin/yii') . ' ' . $arguments . ' 2>&1',
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

    // ---------------------------------------------------------------- 生成应用文件

    private static function composerJson(): string
    {
        return json_encode([
            'name'        => 'aetherupload/yii3-e2e',
            'description' => 'tests/Integration/yii3 的临时骨架（由 App.php 生成，勿手工维护）',
            'type'        => 'project',
            'license'     => 'MIT',
            'require'     => self::PACKAGES,
            'repositories' => [
                // 本包未发布 @dev 版本，走 path 仓库直接指向工作区（composer 会做符号链接，
                // 所以改仓库源码不需要重装）
                ['type' => 'path', 'url' => self::repoRoot(), 'options' => ['symlink' => true]],
            ],
            'extra'             => ['config-plugin-file' => 'config/configuration.php'],
            'minimum-stability' => 'dev',
            'prefer-stable'     => true,
            'config'            => [
                // yiisoft/config 是 composer 插件：不显式允许，骨架装不上（它负责生成合并计划）
                'allow-plugins' => ['yiisoft/config' => true],

                // 依赖解析按**跑测试的那个 PHP** 来，而不是按 composer 自己的 PHP：
                // 本机同时装了 8.3 与 8.5 时，`composer` 往往跑在 8.5 上，
                // 于是给 8.3 的测试装出一份要求 >= 8.4 的 vendor，autoload 直接拒绝加载。
                // 钉住 platform 后，换哪个 PHP 跑测试都解析到该版本可用的版本组合
                'platform' => ['php' => PHP_VERSION],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    }

    private static function writeFiles(string $root): void
    {
        file_put_contents($root . '/config/configuration.php', self::configurationFixture());
        file_put_contents($root . '/config/aetherupload.php', self::configFixture(self::redisAvailable()));
        file_put_contents($root . '/config/common/routes.php', self::routesFixture());
        file_put_contents($root . '/config/common/params.php', self::paramsFixture());
        file_put_contents($root . '/config/common/di/psr17.php', self::psr17Fixture());
        file_put_contents($root . '/config/common/di/router.php', self::routerFixture());
        file_put_contents($root . '/config/common/di/logger.php', self::loggerFixture());
        file_put_contents($root . '/config/web/di/application.php', self::applicationFixture());
        file_put_contents($root . '/public/index.php', self::indexFixture());
        file_put_contents($root . '/bin/yii', self::yiiFixture());
        @chmod($root . '/bin/yii', 0755);
    }

    /**
     * Yii3 的配置组声明：两个 runner（web / console）的默认组名都要覆盖上。
     *
     * 组名不能改：`HttpApplicationRunner` 默认取 di-web / params-web / bootstrap-web …，
     * `ConsoleApplicationRunner` 取 di-console / params-console / …，这里是它们的并集。
     */
    private static function configurationFixture(): string
    {
        return <<<'PHP'
<?php

// 由 tests/Integration/yii3/App.php 生成。Yii3 的配置组：vendor 包的 config-plugin 贡献默认值，
// 这里的文件按组拼装（$di / $params / $events 是组引用）。
return [
    'config-plugin' => [
        'params'                 => 'common/params.php',
        'params-web'             => ['$params'],
        'params-console'         => ['$params'],

        'di'                     => 'common/di/*.php',
        'di-web'                 => ['$di', 'web/di/*.php'],
        'di-console'             => ['$di'],

        'di-providers'           => [],
        'di-providers-web'       => ['$di-providers'],
        'di-providers-console'   => ['$di-providers'],

        'di-delegates'           => [],
        'di-delegates-web'       => ['$di-delegates'],
        'di-delegates-console'   => ['$di-delegates'],

        'di-tags'                => [],
        'di-tags-web'            => ['$di-tags'],
        'di-tags-console'        => ['$di-tags'],

        'bootstrap'              => [],
        'bootstrap-web'          => ['$bootstrap'],
        'bootstrap-console'      => ['$bootstrap'],

        'events'                 => [],
        'events-web'             => ['$events'],
        'events-console'         => ['$events'],

        // 宿主路由表：本包的四条路由由 Bootstrap::routes() 生成后展开进来
        'routes'                 => 'common/routes.php',
    ],

    // 配置根目录与 composer.json 同级下的 config/：组文件的路径、合并计划（config/.merge-plan.php）
    // 都相对它解析。缺了这条，composer 插件会把合并计划写到项目根，而 runner 去 config/ 里找 —— 起不来
    'config-plugin-options' => [
        'source-directory' => 'config',
    ],
];
PHP;
    }

    /**
     * 插件配置：以包内默认值为基准，只改测试需要开关的几项。
     *
     * event_* 打开是为了第 7 条（事件探针）：内核只有两个派发点，两个都要有「监听器抛异常不冒泡」的实测。
     * instant_completion 只在真有 redis 时打开（开着却没有客户端会直接抛异常，而不是静默失效）。
     */
    private static function configFixture(bool $redis): string
    {
        return strtr(<<<'PHP'
<?php

// 由 tests/Integration/yii3/App.php 生成：包内默认值 + E2E 需要的开关
$config = require __DIR__ . '/../vendor/erikwang2013/aetherupload-webman/config/aetherupload.php';

$config['groups']['file']['event_upload_complete'] = true;
$config['groups']['file']['event_before_upload_complete'] = true;

$config['instant_completion'] = __REDIS__;

return $config;
PHP, ['__REDIS__' => $redis ? 'true' : 'false']);
    }

    /**
     * 宿主路由：一条自有路由（证明与插件路由共存、且容器注入对 action 同样生效）
     * + 本包四条业务路由。
     */
    private static function routesFixture(): string
    {
        return <<<'PHP'
<?php

use Yiisoft\Router\Route;

return [
    Route::get('/health')
        ->name('health')
        ->action(static function (Psr\Http\Message\ResponseFactoryInterface $responseFactory): Psr\Http\Message\ResponseInterface {
            $response = $responseFactory->createResponse(200);
            $response->getBody()->write('ok');

            return $response;
        }),

    // 插件路由：配置显式传入（路由文件在容器构建期被 require，那时 Runtime 还没绑定）
    ...\AetherUpload\Adapter\Yii3\Bootstrap::routes(require __DIR__ . '/../aetherupload.php'),
];
PHP;
    }

    private static function paramsFixture(): string
    {
        return <<<'PHP'
<?php

// 控制台命令：yiisoft/yii-console 的注册口（bin/yii 里先绑适配器，再起 runner）
return [
    'yiisoft/yii-console' => [
        'commands' => \AetherUpload\Adapter\Yii3\Bootstrap::consoleCommands(),
    ],
];
PHP;
    }

    /** PSR-17 工厂：httpsoft/http-message */
    private static function psr17Fixture(): string
    {
        return <<<'PHP'
<?php

use HttpSoft\Message\RequestFactory;
use HttpSoft\Message\ResponseFactory;
use HttpSoft\Message\ServerRequestFactory;
use HttpSoft\Message\StreamFactory;
use HttpSoft\Message\UploadedFileFactory;
use HttpSoft\Message\UriFactory;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\UploadedFileFactoryInterface;
use Psr\Http\Message\UriFactoryInterface;

return [
    RequestFactoryInterface::class       => RequestFactory::class,
    ServerRequestFactoryInterface::class => ServerRequestFactory::class,
    ResponseFactoryInterface::class      => ResponseFactory::class,
    StreamFactoryInterface::class        => StreamFactory::class,
    UriFactoryInterface::class           => UriFactory::class,
    UploadedFileFactoryInterface::class  => UploadedFileFactory::class,
];
PHP;
    }

    /**
     * 路由表 → RouteCollection。与 Yii3 应用模板的 config/common/di/router.php 同形：
     * `routes` 组里的 Route 对象在这里交给 RouteCollector，UrlMatcher 由 router-fastroute 提供。
     */
    private static function routerFixture(): string
    {
        return <<<'PHP'
<?php

use Yiisoft\Config\Config;
use Yiisoft\Definitions\DynamicReference;
use Yiisoft\Router\RouteCollection;
use Yiisoft\Router\RouteCollectionInterface;
use Yiisoft\Router\RouteCollector;

/** @var Config $config */
return [
    RouteCollectionInterface::class => [
        'class'        => RouteCollection::class,
        '__construct()' => [
            'collector' => DynamicReference::to(
                static fn(): RouteCollector => (new RouteCollector())->addRoute(...$config->get('routes')),
            ),
        ],
    ],
];
PHP;
    }

    /** 日志：NullLogger（骨架不需要日志输出；error-handler 要一个 PSR-3 实例才装配得起来） */
    private static function loggerFixture(): string
    {
        return <<<'PHP'
<?php

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

return [
    LoggerInterface::class => NullLogger::class,
];
PHP;
    }

    /**
     * Web 应用：中间件栈（路由中间件即可，其余是宿主自己的事）+ 未命中时的 404 回退。
     * 与 Yii3 应用模板的 config/web/di/application.php 同构，只是回退处理器就地写成了匿名类。
     */
    private static function applicationFixture(): string
    {
        return <<<'PHP'
<?php

use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Yiisoft\Definitions\DynamicReference;
use Yiisoft\Middleware\Dispatcher\MiddlewareDispatcher;
use Yiisoft\Router\Middleware\Router;
use Yiisoft\Yii\Http\Application;

return [
    Application::class => [
        '__construct()' => [
            'dispatcher' => DynamicReference::to([
                'class'              => MiddlewareDispatcher::class,
                'withMiddlewares()'  => [
                    [
                        Router::class,
                    ],
                ],
            ]),

            // 未命中任何路由：404（与其它宿主的语义一致）
            'fallbackHandler' => DynamicReference::to(static function (ContainerInterface $container): RequestHandlerInterface {
                return new class ($container->get(ResponseFactoryInterface::class)) implements RequestHandlerInterface {
                    private $responseFactory;

                    public function __construct(ResponseFactoryInterface $responseFactory)
                    {
                        $this->responseFactory = $responseFactory;
                    }

                    public function handle(ServerRequestInterface $request): ResponseInterface
                    {
                        return $this->responseFactory->createResponse(404);
                    }
                };
            }),
        ],
    ],
];
PHP;
    }

    /**
     * 真实入口脚本：先绑适配器（runner 之前），再起 runner。
     *
     * 与测试共用同一份绑定（App::boot() 是它的等价物），保证「测试里跑通的」就是「用户拿到的」。
     */
    private static function indexFixture(): string
    {
        return strtr(<<<'PHP'
<?php

require __DIR__ . '/../vendor/autoload.php';

use AetherUpload\Adapter\Yii3\Bootstrap;
use Yiisoft\Yii\Runner\Http\HttpApplicationRunner;

$root = dirname(__DIR__);

// 绑定必须早于 runner：runner 会构建容器、跑 bootstrap，路由与配置都要求适配器已就位
Bootstrap::bind(require __DIR__ . '/../config/aetherupload.php', $root, ['redis' => __REDIS_EXPR__]);

// php -S 的内置服务器：已发布的静态文件（/vendor/aetherupload/js/...）交给它自己发
if ( PHP_SAPI === 'cli-server' ) {
    $path = (string)parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

    if ( $path !== '/' && is_file(__DIR__ . $path) ) {
        return false;
    }
}

(new HttpApplicationRunner($root))->run();
PHP, ['__REDIS_EXPR__' => self::redisExpression()]);
    }

    /** 控制台入口：同样先绑适配器，再起 yii-console 的 runner（命令里要读配置与路径） */
    private static function yiiFixture(): string
    {
        return strtr(<<<'PHP'
#!/usr/bin/env php
<?php

require __DIR__ . '/../vendor/autoload.php';

use AetherUpload\Adapter\Yii3\Bootstrap;
use Yiisoft\Yii\Runner\Console\ConsoleApplicationRunner;

$root = dirname(__DIR__);

Bootstrap::bind(require __DIR__ . '/../config/aetherupload.php', $root, ['redis' => __REDIS_EXPR__]);

(new ConsoleApplicationRunner($root))->run();
PHP, ['__REDIS_EXPR__' => self::redisExpression()]);
    }

    /** redis 客户端表达式（无 redis 时给 null ⇒ 适配器用 NullRedis）；入口脚本与控制台入口共用 */
    private static function redisExpression(): string
    {
        if ( ! self::redisAvailable() ) {
            return 'null';
        }

        return "static function () { return new \\Predis\\Client(['scheme' => 'tcp', 'host' => "
            . var_export(self::redisHost(), true) . ", 'port' => " . self::redisPort()
            . ", 'database' => " . self::redisDb() . "]); }";
    }

    // ------------------------------------------------------------------ 进程与文件

    public static function mkdir(string $dir): void
    {
        if ( ! is_dir($dir) && ! @mkdir($dir, 0755, true) && ! is_dir($dir) ) {
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
