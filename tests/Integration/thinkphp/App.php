<?php

namespace AetherUpload\Tests\Integration\ThinkPhp;

use RuntimeException;

/**
 * ThinkPHP 端到端测试的应用准备器：装一个最小 ThinkPHP 骨架 + 进程内跑真请求。
 *
 * 与 webman 套件的区别只在「怎么发请求」：ThinkPHP 的入口就是
 * `(new think\App($root))->http->run($request)`（public/index.php 的全部内容），
 * 所以这里不需要起 HTTP 服务，测试进程里直接构造 think\Request 交给它，
 * 仍走完整的初始化 → 服务注册 → 路由 → 中间件 → 控制器链路。
 *
 * 幂等：已就绪的应用会直接复用（重复跑测试不重复 composer install）。
 * 可用环境变量（与 webman 套件同名，便于同一台机器上并存）：
 *   AETHERA_E2E_APP    应用目录（默认 sys_get_temp_dir()/aetherupload-thinkphp-e2e）
 *   AETHERA_E2E_REDIS  '0' 强制视为无 redis（第 8 条跳过）；默认自动探测
 *   AETHERA_E2E_REDIS_HOST / _PORT / _DB   redis 连接参数（默认 127.0.0.1:6379 db 5，见 redisDb()）
 */
final class App
{
    private function __construct()
    {
    }

    public static function root(): string
    {
        $dir = getenv('AETHERA_E2E_APP');

        return $dir ? rtrim($dir, '/') : sys_get_temp_dir() . '/aetherupload-thinkphp-e2e';
    }

    /** 本仓库根目录（path 仓库指向它，装进临时骨架的是当前工作区，不是发布版） */
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
        return getenv('AETHERA_E2E_REDIS_HOST') ?: '127.0.0.1';
    }

    public static function redisPort(): int
    {
        return (int)(getenv('AETHERA_E2E_REDIS_PORT') ?: 6379);
    }

    public static function redisDb(): int
    {
        // 六个框架的集成测试会并行跑，各自的 flush 不能落在同一个库上（否则会出现
        // 「别人的 flush 正好插在你第 8 条两次上传之间，秒传命中不了」这种随机假红）。
        // 分配：webman 7 · laravel 4 · thinkphp 5 · hyperf 3 · slim 6 · symfony 8 · yii 9
        return (int)(getenv('AETHERA_E2E_REDIS_DB') ?: 5);
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

    /** 幂等准备：骨架 → 依赖 → 本插件 → 配置 → aetherupload:groups + aetherupload:publish */
    public static function ensure(): void
    {
        $root = self::root();

        self::mkdir($root);

        if ( ! is_file($root . '/vendor/autoload.php') ) {
            // 只装框架本体，不装 topthink/think 骨架：不需要 public/index.php 与 think 可执行文件，
            // 请求在测试进程里造（见类注释）
            self::run('composer require topthink/framework:^8.0 --no-interaction --no-progress', $root);
        }

        // 骨架的自动加载器：think\* 从这里来。本仓库的自动加载器（bootstrap.php 里）只有
        // AetherUpload\* 与 PHPUnit，两者互不冲突。必须在 setup() 之前 require —— 它要 new think\App。
        require_once $root . '/vendor/autoload.php';

        if ( ! is_dir($root . '/vendor/erikwang2013/aetherupload-webman') ) {
            self::run('composer config repositories.aetherupload path ' . escapeshellarg(self::repoRoot()) . ' --no-interaction', $root);
            self::run('composer require erikwang2013/aetherupload-webman:@dev --no-interaction --no-progress', $root);
        }

        self::setup($root);
    }

    /**
     * 把骨架写成可测状态：配置文件、服务、事件、翻译与上传目录。
     *
     * 配置是纯 PHP 数组，不需要在应用进程里改写（webman 那份 app-setup.php 需要是因为
     * 它得用 webman 的全局函数），所以这里直接写。每次 ensure() 都重写：
     * 秒传开关依赖 redis 是否可用，上一轮的环境不代表这一轮。
     */
    private static function setup(string $root): void
    {
        self::mkdir($root . '/app');
        self::mkdir($root . '/config');
        self::mkdir($root . '/route');
        self::mkdir($root . '/runtime');
        self::mkdir($root . '/public');

        // 路由缓存与配置缓存会盖掉下面写的文件，每轮清掉（本项目从不生成它们，防的是人工排错时留下的残骸）
        @unlink($root . '/runtime/route.php');
        @unlink($root . '/runtime/config.php');

        $redis = self::redisAvailable();

        // 接入点：一行服务注册即完成 Runtime 绑定 + 路由 + 命令（见 AetherUploadService）
        self::write($root . '/app/service.php', "<?php\n\nreturn [\n    \\AetherUpload\\Adapter\\ThinkPhp\\AetherUploadService::class,\n];\n");

        // 事件探针：记录型 + 抛异常型，第 7 条据此断言「监听器异常不冒泡」。
        //
        // 顺序是「记录在前、抛异常在后」，与 webman 套件相反，原因是框架语义不同：
        // think\Event::trigger() 遍历监听器时不接异常，第一个抛异常的会中断本轮派发
        // （webman/event 则是 catch + continue）。适配器的契约是「异常绝不冒泡到上传响应」，
        // 由 ThinkPhpEvents::emit 在派发处 catch 兜住；把记录型放前面，两条断言
        // （仍 200 + 记录型被调用）才都成立，且抛异常的那个确实被执行到——它会以
        // 「aetherupload event listener failed」落到 runtime/log 里，可作为执行过的证据。
        self::write($root . '/app/event.php', <<<'PHP'
<?php

// 由 tests/Integration/thinkphp 的 E2E 准备脚本写入，勿手工修改
return [
    'listen' => [
        'aetherupload.upload_complete' => [
            static function ($resource) {
                @file_put_contents(
                    __DIR__ . '/../runtime/aetherupload-probe.log',
                    'aetherupload.upload_complete ' . ($resource->name ?? '?') . "\n",
                    FILE_APPEND
                );
            },
            static function () {
                throw new \RuntimeException('E2E probe: this listener exception must not bubble');
            },
        ],
    ],
];
PHP);

        // app.php：时区必须与测试进程一致，否则跨月边界时 groupSubDir 会漂。
        // app_debug 关掉：框架的调试异常页（tpl/think_exception.tpl:346）在 PHP 8.1+ 上会
        // htmlentities(null) → E_DEPRECATED → 被框架自己的错误处理器转成 ErrorException，
        // 把原始异常盖掉。真异常仍在 runtime/log/<Ym>/<d>.log 里，排错看那里。
        self::write($root . '/config/app.php', "<?php\n\nreturn " . var_export([
            'app_debug'        => false,
            'default_timezone' => date_default_timezone_get(),
            'with_route'       => true,
        ], true) . ";\n");

        // route.php：think\Route 的构造函数会 array_merge($this->config, Config::get('route'))，
        // 配置缺失时第二个参数是 null → array_merge 抛 TypeError，所以这个文件必须有
        self::write($root . '/config/route.php', "<?php\n\nreturn " . var_export([
            'url_html_suffix'      => 'html',
            'url_route_must'       => false,
            'route_complete_match' => false,
            'pathinfo_depr'        => '/',
            'route_check_cache'    => false,
        ], true) . ";\n");

        // lang.php：think\Lang::__make 会 array_change_key_case(Config::get('lang'))，
        // 配置缺失时是 null → TypeError。default_lang 用 en：内核 RequestContext 的默认语种。
        self::write($root . '/config/lang.php', "<?php\n\nreturn " . var_export([
            'default_lang'        => 'en',
            'allow_group'         => false,
            'extend_list'         => [],
            'auto_detect_browser' => false,
            'use_cookie'          => false,
        ], true) . ";\n");

        // 日志走文件通道；事件桥在监听器抛异常时要 Log::error，得有个能用的通道
        self::write($root . '/config/log.php', "<?php\n\nreturn " . var_export([
            'default' => 'file',
            'channels' => [
                'file' => ['type' => 'File', 'path' => '', 'level' => [], 'json' => false],
            ],
        ], true) . ";\n");

        // cache.php：秒传的 redis 客户端经 Cache::store('redis')->handler() 取得
        self::write($root . '/config/cache.php', "<?php\n\nreturn " . var_export([
            'default' => 'file',
            'stores'  => [
                'file'  => ['type' => 'File', 'path' => '', 'prefix' => '', 'expire' => 0],
                'redis' => [
                    'type'       => 'redis',
                    'host'       => self::redisHost(),
                    'port'       => self::redisPort(),
                    'password'   => '',
                    'select'     => self::redisDb(),
                    'timeout'    => 2,
                    'expire'     => 0,
                    'persistent' => false,
                    'prefix'     => '',
                ],
            ],
        ], true) . ";\n");

        // aetherupload.php：宿主的覆盖值。秒传按 redis 可用性开关；第 7 条要求分组开了 upload_complete 事件。
        //
        // groups 必须是「完整的一整个分组」：适配器的合并语义与 Laravel 的 mergeConfigFrom 一致，
        // groups 是整体替换而非逐叶合并（避免宿主写短的 forbidden_extensions 与默认列表串味），
        // 只写 ['file' => ['event_upload_complete' => true]] 会把 group_dir/group_subdir_rule
        // 等键一起抹掉，落到「分组目录为空」的存储路径上。所以这里从包内默认配置取出 file 分组再改。
        $defaults = (array)require self::repoRoot() . '/config/aetherupload.php';
        $fileGroup = (array)$defaults['groups']['file'];
        $fileGroup['event_upload_complete'] = true;

        self::write($root . '/config/aetherupload.php', "<?php\n\nreturn " . var_export([
            'instant_completion' => $redis,
            'groups'             => ['file' => $fileGroup],
        ], true) . ";\n");

        self::install($root);
    }

    /**
     * 与使用者的接入步骤完全一致：装包 → aetherupload:groups 建目录 → aetherupload:publish 分发文件。
     *
     * 刻意不调 AetherUpload\Install::install()：那是 webman 专属安装器（pathRelation 里是
     * config/plugin/... 与 app/command 这类 webman 形状的路径），在 ThinkPHP 骨架里跑虽然无害，
     * 但语义错误、还会落下冗余目录。Laravel 侧同样没有 Install 步骤，靠 artisan 命令建分组目录。
     *
     * 语言文件必须真发布一份：内核按 translationsPath()/aetherupload/<locale>/messages.php 查找
     * （app/lang/aetherupload/，见 ThinkPhpPaths），FlowAssertions 第 5 条就断言译文原文。
     *
     * 必须在应用初始化之后调用：两条命令都从 Runtime::config() 读 root_dir 与 groups，
     * 而那要等 App::initialize() 把 config/*.php 读进来、并跑过我们的 Service（里面 bind Runtime）。
     * $app->console 的构造里就会做这件事（Console::__construct → App::initialize()）。
     */
    private static function install(string $root): void
    {
        $app = new \think\App($root);
        $console = $app->console;

        self::call($console, 'aetherupload:groups', 'Group-Directory List:');
        self::call($console, 'aetherupload:publish', 'Done.');
    }

    /**
     * 跑一条命令并要求输出里出现 $expect。
     *
     * think\Console::call() 丢掉退出码、只返回 Output，所以只能按输出断言；
     * 命令失败时 Runner 会把 'Error: ...' 打进输出，这里带着原文抛出去，不让它变成后续测试的谜题。
     */
    private static function call(\think\Console $console, string $name, string $expect): void
    {
        $output = (string)$console->call($name)->fetch();

        if ( strpos($output, $expect) === false ) {
            throw new RuntimeException($name . " 未按预期完成，输出：\n" . $output);
        }
    }

    // ------------------------------------------------------------------ 进程

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

    private static function write(string $file, string $content): void
    {
        if ( file_put_contents($file, $content) === false ) {
            throw new RuntimeException('无法写入：' . $file);
        }
    }

    private static function mkdir(string $dir): void
    {
        if ( ! is_dir($dir) && ! mkdir($dir, 0755, true) && ! is_dir($dir) ) {
            throw new RuntimeException('无法创建目录：' . $dir);
        }
    }
}
