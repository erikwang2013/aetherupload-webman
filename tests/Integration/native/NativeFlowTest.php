<?php

namespace AetherUpload\Tests\Integration\Native;

use AetherUpload\Tests\Integration\FlowAssertions;
use PHPUnit\Framework\TestCase;

/**
 * 原生 PHP 真实端到端：真 SAPI（php -S）+ 真 curl + 真文件系统 + 真 redis。
 *
 * 这一层才是「原生 PHP 可用」的证据；tests/ 下的单元用例用的是替身，只覆盖内核逻辑。
 * 应用准备与装配见同目录 App.php，断言清单与其余七个宿主共用 FlowAssertions。
 */
class NativeFlowTest extends TestCase
{
    use FlowAssertions;

    protected function setUp(): void
    {
        parent::setUp();

        // 每个用例都从干净的秒传索引开始：命中秒传会让 preprocess 直接回 savedPath、跳过
        // .part 建立与事件派发，上一个用例留下的记录会把第 1、7 条打成假红
        App::flushInstantCompletionKeys();
    }

    // ------------------------------------------------------- FlowAssertions 钩子

    protected function send(string $method, string $path, array $post = [], array $files = [], array $headers = []): array
    {
        return App::request($method, $path, $post, $files, $headers);
    }

    protected function appBasePath(): string
    {
        return App::root();
    }

    protected function probeLogPath(): ?string
    {
        return App::probeLog();
    }

    protected function supportsInstantCompletion(): bool
    {
        return App::redisAvailable();
    }

    protected function instantCompletionSkipReason(): string
    {
        if ( App::redisDisabled() ) {
            return 'AETHERA_E2E_REDIS=0 强制关掉了 redis；第 8 条秒传未验证，其余 7 条不受影响';
        }

        return '本机 ' . App::redisHost() . ':' . App::redisPort() . ' db ' . App::redisDb()
            . ' 上没有可用的 redis（装了 phpredis 但服务没起、或返回值不对也会走到这里）；'
            . '第 8 条秒传未验证，其余 7 条不受影响';
    }

    // ------------------------------------------------- 原生 PHP 特有的补充断言

    /**
     * 内置路由的行为：未命中 404、路径命中但方法不对 405 + Allow。
     *
     * 其余七个宿主由各自的路由器给出同样的两种结果，这里必须自己实现 —— 所以要有断言钉住。
     */
    public function testRouterMethodAndNotFound(): void
    {
        $notFound = App::request('GET', '/definitely-not-a-route');

        $this->assertSame(404, $notFound['status'], '未命中的路径必须 404（不能落到 500）');

        $wrongMethod = App::request('GET', $this->path('preprocess'));

        $this->assertSame(405, $wrongMethod['status'], '路径命中但方法不对必须 405');
        $this->assertSame('POST', $this->header($wrongMethod['headers'], 'Allow'), '405 必须给出 Allow');

        // 展示路由要求 uri 恰好一段：多一段不是「展示」而是未命中
        $tooManySegments = App::request('GET', $this->path('display') . '/a/b');

        $this->assertSame(404, $tooManySegments['status'], 'display 的 uri 只有一段，多出的一段必须 404');
    }

    /**
     * 中间件契约：无参可调用对象，返回响应对象即短路。
     *
     * 这条是 README「自定义中间件可做权限控制」在原生 PHP 下的落地方式 —— 不测就只能靠读代码相信。
     */
    public function testMiddlewareShortCircuit(): void
    {
        $blocked = App::request('GET', $this->path('download') . '/file_202601_' . str_repeat('a', 32) . '.gif/new.gif', [], [], ['X-E2E-Forbid: 1']);

        $this->assertSame(403, $blocked['status'], '中间件返回响应必须短路');
        $this->assertSame('forbidden by middleware', $blocked['body'], '短路响应必须是中间件给的那一个');

        // 不带那个头时中间件放行（404 是因为资源不存在，说明请求确实走进了控制器）
        $passed = App::request('GET', $this->path('download') . '/file_202601_' . str_repeat('a', 32) . '.gif/new.gif');

        $this->assertSame(404, $passed['status'], '中间件放行后应走到控制器（资源不存在 → 404）');
    }

    /**
     * 发布出来的前端资源由内置服务器自己发（前端控制器里的 `return false` 那条）。
     *
     * 它同时证明 aetherupload:publish 这个交付物在原生 PHP 下落到了对的位置。
     */
    public function testPublishedAssetsAreServed(): void
    {
        $asset = App::request('GET', '/vendor/aetherupload/js/aetherupload-all.js');

        $this->assertSame(200, $asset['status'], '发布出来的前端 js 必须可访问（见 App::ensure() 的 publish 步骤）');
        $this->assertStringContainsString('aetherupload', $asset['body'], '返回的必须是前端脚本本体');
    }

    /**
     * 发布落点：语言文件真的躺在 translationsPath/aetherupload/en 下。
     *
     * 第 5 条已经端到端证明了译文会被用上；这里钉的是**发布这一步的落点**本身 ——
     * 内核按 `{translationsPath}/aetherupload/{locale}/messages.php` 查找，路径错了就只有到
     * 报错时才看得出来（错误消息会退化成 key）。
     */
    public function testTranslationIsPublished(): void
    {
        $file = App::root() . '/resource/translations/aetherupload/en/messages.php';

        $this->assertFileExists($file, 'aetherupload:publish 必须把语言文件放到 translationsPath 下');

        $messages = require $file;

        $this->assertIsArray($messages, '语言文件必须返回数组');
        $this->assertSame(self::UPLOAD_ERROR_MESSAGE, isset($messages['upload_error']) ? $messages['upload_error'] : null, '英文 upload_error 的译文');
    }
}
