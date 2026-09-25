<?php

namespace AetherUpload\Adapter\ThinkPhp;

use AetherUpload\Adapter\ThinkPhp\Command\BuildRedisHashesCommand;
use AetherUpload\Adapter\ThinkPhp\Command\CleanUpDirectoryCommand;
use AetherUpload\Adapter\ThinkPhp\Command\ListGroupsCommand;
use AetherUpload\Adapter\ThinkPhp\Command\PublishCommand;
use AetherUpload\ConfigMapper;
use AetherUpload\ResourceController;
use AetherUpload\Runtime;
use AetherUpload\UploadController;
use think\facade\Route;
use think\Service;

/**
 * ThinkPHP 服务提供者（think\Service）。
 *
 * 宿主的接入点是一行：在 app/service.php 里返回本类。
 *     return [ \AetherUpload\Adapter\ThinkPhp\AetherUploadService::class ];
 *
 * 装包后跑两条命令即可用（没有 webman 那样的 Install 步骤，与 Laravel 侧一致）：
 *     php think aetherupload:groups   建 root_dir、_header 与各分组目录
 *     php think aetherupload:publish  把语言文件与前端 js 复制进应用
 * 分组目录必须真建出来：createGroupSubDir() 是非递归 mkdir，父目录缺失会直接失败。
 *
 * register() 里做四件事，顺序有依赖：先绑 Runtime（后面三步都要读配置）、
 * 再合并配置、再并命令、最后登记路由（服务里注册的路由由 RouteLoaded 事件触发，
 * 不依赖宿主在 route/ 目录下放路由文件）。
 *
 * 接入必读两条（README 的逐框架接入步骤会引用这里）：
 *
 * 1. groups 是**整体替换**，不是逐叶合并。宿主写 config/aetherupload.php 时必须把整个分组写全
 *    （从包内 config/aetherupload.php 抄一份再改），只写
 *    ['file' => ['event_upload_complete' => true]] 会把 group_dir、resource_maxsize、
 *    resource_extensions 等键一起抹掉——group_dir 没了，落盘路径就没了分组子目录。
 *    与 Laravel 适配器的 mergeConfigFrom 同语义；不逐叶合并是因为宿主写短的
 *    forbidden_extensions / resource_extensions 列表会与默认列表串味。
 *
 * 2. config/route.php 与 config/lang.php 是**必需文件**（内容可以为空数组）：
 *    think\Route 的构造函数对 Config::get('route') 做 array_merge、
 *    think\Lang::__make 对 Config::get('lang') 做 array_change_key_case，
 *    两者拿到 null 都会抛 TypeError，与是否用本插件无关，但缺了应用起不来。
 */
class AetherUploadService extends Service
{
    public function register(): void
    {
        Runtime::bind(new ThinkPhpAdapter($this->app));

        $this->registerDefaultConfig();

        $this->registerCommands();

        $this->registerRoutes(function () {
            Route::post(ConfigMapper::get('route_preprocess'), [UploadController::class, 'preprocess'])
                ->middleware(ConfigMapper::get('middleware_preprocess'));

            Route::post(ConfigMapper::get('route_uploading'), [UploadController::class, 'saveChunk'])
                ->middleware(ConfigMapper::get('middleware_uploading'));

            // ThinkPHP 的路由变量写作 :uri（{uri} 也认，但 :uri 才是它的惯用形式，且
            // 变量默认规则 [\w\.]+ 覆盖 savedPath 里的点号）。display/download 只收路由变量，
            // 请求对象由适配器自己从容器取。
            Route::get(ConfigMapper::get('route_display') . '/:uri', [ResourceController::class, 'display'])
                ->middleware(ConfigMapper::get('middleware_display'));

            Route::get(ConfigMapper::get('route_download') . '/:uri/:newName', [ResourceController::class, 'download'])
                ->middleware(ConfigMapper::get('middleware_download'));
        });
    }

    /**
     * 把包内 config/aetherupload.php 作为基线并进宿主配置，宿主的同名顶层键优先
     * （与 Laravel 适配器的 mergeConfigFrom 同语义：宿主不需要先 vendor:publish 才能跑起来）。
     *
     * groups 是整体替换而非逐叶合并：逐叶合并会让宿主写短的 forbidden_extensions /
     * resource_extensions 列表与默认列表串味。
     */
    private function registerDefaultConfig(): void
    {
        $defaults = (array)include dirname(__DIR__, 3) . '/config/aetherupload.php';
        $host     = (array)$this->app->config->get('aetherupload', []);

        // Config::set() 内部是 array_merge(旧值, 传入值)，传入值在后即胜出
        $this->app->config->set(array_replace($defaults, $host), 'aetherupload');
    }

    /**
     * 并入 console.commands —— think\Console::loadCommands() 正是从这里读。
     * register() 一定早于 Console 构造（Http/Console 都先 App::initialize()），所以不会漏注册。
     */
    private function registerCommands(): void
    {
        $commands = (array)$this->app->config->get('console.commands', []);

        $commands[] = BuildRedisHashesCommand::class;
        $commands[] = CleanUpDirectoryCommand::class;
        $commands[] = ListGroupsCommand::class;
        $commands[] = PublishCommand::class;

        $this->app->config->set(['commands' => $commands], 'console');
    }
}
