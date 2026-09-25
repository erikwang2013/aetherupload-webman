<?php

namespace AetherUpload\Tests\Integration\Laravel;

use AetherUpload\Adapter\Laravel\AetherUploadServiceProvider;
use AetherUpload\ResourceController;
use AetherUpload\Runtime;
use AetherUpload\Tests\Integration\FlowAssertions;
use AetherUpload\UploadController;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Redis;
use Orchestra\Testbench\TestCase;
use RuntimeException;
use Throwable;

/**
 * Laravel 真实端到端：真 laravel/framework（orchestra/testbench 起的最小宿主应用）+ 真路由 + 真 HTTP
 * 内核 + 真文件系统 + 真 Redis。
 *
 * 这一层才是「Laravel 可用」的证据；tests/ 下的 227 个用例用的是替身，只覆盖内核逻辑。
 * 应用准备与装配见同目录 ci.sh / bootstrap.php / Harness.php，断言清单见 FlowAssertions。
 */
class LaravelFlowTest extends TestCase
{
    /**
     * 说明：早先 FlowAssertions 的响应解析助手也叫 json()，与 Orchestra\Testbench\TestCase 继承来的
     * json()（Laravel 的 HTTP 测试助手）签名不兼容，类加载即致命错误 —— 当时靠 alias + 转发绕过。
     * 该助手已统一改名为 parseJsonResponse()，所以这里不再需要任何别名或覆盖。
     */
    use FlowAssertions;

    /** 注册本包的 ServiceProvider —— 宿主正常也要在 bootstrap/providers.php 里加这一行 */
    protected function getPackageProviders($app)
    {
        return [AetherUploadServiceProvider::class];
    }

    /**
     * 宿主应用配置。
     *
     * 这里模拟「已发布 config/aetherupload.php 并改过」的宿主：整棵数组读插件默认配置再覆写，
     * 而不是只塞一个键 —— Laravel 的 mergeConfigFrom 是**浅合并**，宿主只要出现 groups，
     * 插件默认的 groups 就会被整体替换掉（这个坑写在 ServiceProvider 的类注释里）。
     */
    protected function defineEnvironment($app)
    {
        $config = require Harness::repoRoot() . '/config/aetherupload.php';

        $config['instant_completion'] = true;                                    // 第 8 条秒传
        $config['groups']['file']['event_before_upload_complete'] = true;        // 第 7 条事件探针
        $config['groups']['file']['event_upload_complete'] = true;

        $app['config']->set('aetherupload', $config);

        // 第 6 条要的「翻译未命中」必须真的未命中。Laravel 默认 fallback_locale = en（本插件已加载），
        // 那样探针语种会拿到英文译文而不是 key，测不到「未命中返回 key」这条契约。
        // webman 的 E2E 用同样的手法（把 config/translation.php 的 fallback_locale 清空）。
        $app['config']->set('app.fallback_locale', self::PROBE_LOCALE);

        $app['config']->set('database.redis.default.host', Harness::redisHost());
        $app['config']->set('database.redis.default.port', Harness::redisPort());
        $app['config']->set('database.redis.default.database', Harness::redisDb());
        $app['config']->set('database.redis.options.prefix', '');
    }

    protected function setUp(): void
    {
        parent::setUp();

        // 真装一次语言文件：走宿主自己的 vendor:publish，顺带验证发布标签可用
        Artisan::call('vendor:publish', [
            '--tag'   => AetherUploadServiceProvider::PUBLISH_TAG_PREFIX . 'translations',
            '--force' => true,
        ]);

        // 装完要建的目录（<root>/_header 与各分组目录）由本插件的 artisan 命令创建，
        // 这也是 Laravel 宿主 `composer require` 之后要做的那一步；顺带验证命令壳可用。
        Artisan::call('aetherupload:groups');

        // 每个用例都从干净的秒传索引开始。秒传命中会让 preprocess 直接回 savedPath，
        // 跳过 .part 建立与**事件派发**，上一个用例留下的记录会把第 1、7 条打成假红。
        try {
            foreach ( (array)Redis::keys('aetherupload*') as $key ) {
                Redis::del($key);
            }
        } catch ( Throwable $e ) {
            // redis 不可用时第 8 条会跳过，这里无需处理
        }

        // 事件探针：一个抛异常（必须不影响响应），一个记录（必须仍被调用）。
        // 前者挂 before_upload_complete、后者挂 upload_complete，天然是「先抛后记」：
        // 异常只要冒泡，上传响应就会被打断，第 7 条第一句断言直接红。
        Event::listen('aetherupload.before_upload_complete', static function (): void {
            throw new RuntimeException('E2E probe: this listener exception must not bubble');
        });

        Event::listen('aetherupload.upload_complete', static function ($resource): void {
            @file_put_contents(
                Harness::probeLog(),
                'aetherupload.upload_complete ' . (is_object($resource) ? get_class($resource) : gettype($resource)) . "\n",
                FILE_APPEND
            );
        });
    }

    // ------------------------------------------------------- FlowAssertions 钩子

    protected function appBasePath(): string
    {
        return Harness::appRoot();
    }

    protected function probeLogPath(): ?string
    {
        return Harness::probeLog();
    }

    protected function supportsInstantCompletion(): bool
    {
        if ( Harness::redisDisabled() ) {
            return false;
        }

        try {
            // 真正的判据不是「端口通」，而是内核依赖的那套返回值语义确实成立
            Redis::setex('aetherupload-e2e-probe', 10, 'ok');

            return Redis::get('aetherupload-e2e-probe') === 'ok';
        } catch ( Throwable $e ) {
            return false;
        }
    }

    protected function instantCompletionSkipReason(): string
    {
        if ( Harness::redisDisabled() ) {
            return 'AETHERA_E2E_REDIS=0 强制关掉了 redis；第 8 条秒传未验证，其余 7 条不受影响';
        }

        return '本机 ' . Harness::redisHost() . ':' . Harness::redisPort() . ' 上没有可用的 redis'
            . '（装了 redis 但返回值不对也会走到这里）；第 8 条秒传未验证，其余 7 条不受影响';
    }

    // ------------------------------------------------------------------ 发请求

    /**
     * 进程内发一个真实请求：经 Laravel 的 HTTP 内核（全局中间件 → 路由 → 控制器），
     * 与 testbench/浏览器测试同一条路径，只是不经过网络。
     *
     * 返回的是**原始** status/body/header 行，因为 FlowAssertions 要按字节比对响应体。
     */
    protected function send(string $method, string $path, array $post = [], array $files = [], array $headers = []): array
    {
        $tempFiles = [];
        $uploads = [];

        foreach ( $files as $field => $file ) {
            $temp = tempnam(sys_get_temp_dir(), 'aetherupload-e2e-');
            file_put_contents($temp, $file['content']);
            $tempFiles[] = $temp;

            // 第五参 test 模式：进程内没有真实上传，让 isValid() 跳过 is_uploaded_file() 检查
            $uploads[$field] = new UploadedFile($temp, $file['name'], null, null, true);
        }

        // 真 HTTP 的 multipart 解析结果里所有标量字段都是字符串（'42' 而非 42）。
        // 进程内数组传参会把 int 带进控制器，与其余框架的 E2E（真 curl）语义不一致，这里对齐。
        array_walk_recursive($post, static function (&$value): void {
            if ( is_scalar($value) ) {
                $value = (string)$value;
            }
        });

        $request = HttpRequest::create($path, $method, $post, [], $uploads, $this->serverVars($headers));

        $response = $this->app->make(Kernel::class)->handle($request);

        // BinaryFileResponse::getContent() 恒为 false（它走流式发送），必须真发一次才能拿到字节；
        // 这样 Range/offset 的处理也与真实响应一致。
        ob_start();
        $response->sendContent();
        $body = (string)ob_get_clean();

        $lines = [];

        foreach ( $response->headers->all() as $name => $values ) {
            foreach ( $values as $value ) {
                $lines[] = $name . ': ' . $value;
            }
        }

        foreach ( $tempFiles as $temp ) {
            @unlink($temp);
        }

        return [
            'status'  => $response->getStatusCode(),
            'body'    => $body,
            'headers' => $lines,
        ];
    }

    /** 把 ['X-Foo: bar'] 或 ['X-Foo' => 'bar'] 转成 Symfony 的 server 变量 */
    private function serverVars(array $headers): array
    {
        $server = [];

        foreach ( $headers as $name => $value ) {
            if ( is_int($name) ) {
                $parts = explode(':', (string)$value, 2);
                $name = $parts[0];
                $value = $parts[1] ?? '';
            }

            $server['HTTP_' . strtoupper(str_replace('-', '_', trim((string)$name)))] = trim((string)$value);
        }

        return $server;
    }

    // ------------------------------------------------- 本框架特有的补充断言

    /**
     * 四条业务路由注册到了正确的控制器方法（适配器的交付项之一，FlowAssertions 不覆盖）。
     */
    public function testBusinessRoutesAreRegistered(): void
    {
        $registered = [];

        foreach ( $this->app['router']->getRoutes() as $route ) {
            $registered[$route->uri()] = $route->getActionName();
        }

        $this->assertSame(UploadController::class . '@preprocess', $registered['aetherupload/preprocess'] ?? null);
        $this->assertSame(UploadController::class . '@saveChunk', $registered['aetherupload/uploading'] ?? null);
        $this->assertSame(ResourceController::class . '@display', $registered['aetherupload/display/{uri}'] ?? null);
        $this->assertSame(ResourceController::class . '@download', $registered['aetherupload/download/{uri}/{newName}'] ?? null);
    }

    /**
     * 配置合并与翻译桥（Laravel 特有的两处坑）。
     */
    public function testConfigMergeAndTranslationBridge(): void
    {
        // 插件默认配置确实经 mergeConfigFrom 合并进来了（下列键都没被 defineEnvironment 覆写）
        $this->assertSame(1000000, config('aetherupload.chunk_size'), 'mergeConfigFrom 必须带进插件默认配置');
        $this->assertSame('/aetherupload/display', config('aetherupload.route_display'), '路由配置来自插件默认值');
        $this->assertSame('storage/app/aetherupload', config('aetherupload.root_dir'), '上传根目录来自插件默认值');

        $translator = Runtime::translator();
        $translator->loadMessages(Runtime::paths()->translationsPath() . '/aetherupload', 'en');

        $translator->setLocale('en');
        $this->assertSame(self::UPLOAD_ERROR_MESSAGE, $translator->trans('upload_error'), '已加载语种必须给译文');

        $translator->setLocale(self::PROBE_LOCALE);
        $this->assertSame('upload_error', $translator->trans('upload_error'), 'Laravel 未命中返回的是完整 id，桥必须回退成逻辑 key');

        // 佐证第 6 条的前提：把回落语种还原成 Laravel 默认的 en 之后，探针语种会拿到英文译文。
        // 那是 Laravel 正常的回落行为、不是桥的缺陷 —— 所以 harness 必须关掉回落才测得到「未命中」。
        // 注意只能改 translator 上的回落值：它是在容器解析 translator 时从 config 里抄走的，
        // 之后再改 config('app.fallback_locale') 不会生效（defineEnvironment 必须在解析前设好）。
        app('translator')->setFallback('en');
        $this->assertSame(self::UPLOAD_ERROR_MESSAGE, $translator->trans('upload_error'), '回落打开时探针语种会拿到 en 译文');
    }
}
