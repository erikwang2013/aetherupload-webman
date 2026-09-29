<?php

namespace AetherUpload\Tests\Integration\Yii3;

use AetherUpload\Tests\Integration\FlowAssertions;
use PHPUnit\Framework\TestCase;

/**
 * Yii3 真实端到端：真装 yiisoft/* 骨架（router + router-fastroute + yii-runner-http + yii-console
 * + DI 容器 + 配置组）+ 真跑 HttpApplicationRunner 的完整链路（配置组装 → 容器 → 路由 → 中间件 →
 * 动作 → 内核）+ 真文件系统 + 真 redis。
 *
 * 这一层才是「Yii3 可用」的证据；tests/ 下的单元用例用的是替身，只覆盖内核逻辑。
 * 断言清单见 FlowAssertions（8 条），本类只实现 Yii3 特有的收发方式与路由语义。
 */
class Yii3FlowTest extends TestCase
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
            . ' 上没有可用的 redis（装了 predis 但服务没起、或返回值不对也会走到这里）；'
            . '第 8 条秒传未验证，其余 7 条不受影响';
    }

    // ------------------------------------------------------- Yii3 特有的补充断言

    /**
     * 路由语义：未命中 404、路径命中但方法不对 405 + Allow，且宿主自己的路由没被插件顶掉。
     *
     * 这三种结果由 yiisoft/router 的 Router 中间件给出（`MatchingResult::isMethodFailure()` → 405，
     * 未命中 → 交给 Application 的 fallbackHandler → 404），与其它宿主的语义必须一致 ——
     * 所以要有断言钉住，而不是「能跑通就算对」。
     */
    public function testRouterSemantics(): void
    {
        $notFound = App::request('GET', '/definitely-not-a-route');

        $this->assertSame(404, $notFound['status'], '未命中的路径必须 404（不能落到 500）');

        $wrongMethod = App::request('GET', $this->path('preprocess'));

        $this->assertSame(405, $wrongMethod['status'], '路径命中但方法不对必须 405');
        $this->assertSame('POST', $this->header($wrongMethod['headers'], 'Allow'), '405 必须给出 Allow');

        // 展示路由的 uri 只吃一段（savedPath 用下划线编码三段，本身不含 /），多一段即未命中
        $tooManySegments = App::request('GET', $this->path('display') . '/a/b');

        $this->assertSame(404, $tooManySegments['status'], '展示路由的 uri 必须恰好一段，多一段就是未命中');

        // 宿主自己的路由仍然可用：插件是「加四条路由」，不是「接管路由表」
        $host = App::request('GET', '/health');

        $this->assertSame(200, $host['status'], '宿主自有路由必须仍然可路由');
        $this->assertSame('ok', $host['body'], '宿主自有路由的响应体必须原样输出（证明容器注入对 action 同样生效）');
    }

    /**
     * 控制台交付物：四条命令都真跑一遍（起真进程、走真 runner）。
     *
     * groups / publish 在 ensure() 里已经跑过（它们是应用能用起来的前提），这里补上
     * build / clean 两条，并断言 build 在没有秒传记录时也不会假装成功。
     */
    public function testConsoleCommands(): void
    {
        $groups = App::runConsole('aetherupload/groups --no-ansi');
        $this->assertSame(0, $groups['exit'], 'aetherupload/groups 必须成功：' . $groups['output']);
        $this->assertStringContainsString('Group-Directory List:', $groups['output'], '命令必须真的跑到底（不是只解析了参数）');
        $this->assertStringContainsString('file', $groups['output'], '配置里的 file 分组要列出来');

        $clean = App::runConsole('aetherupload/clean 30 --no-ansi');
        $this->assertSame(0, $clean['exit'], 'aetherupload/clean 必须成功：' . $clean['output']);

        $build = App::runConsole('aetherupload/build --no-ansi');

        if ( App::redisAvailable() ) {
            $this->assertSame(0, $build['exit'], 'aetherupload/build 必须成功：' . $build['output']);
            $this->assertStringContainsString('Done.', $build['output'], '命令必须真的跑到底');
        } else {
            // 反向断言：没有 redis 客户端时 build 必须**如实报错**，不能假装成功
            // （它要写秒传索引，没有客户端就无从写起）
            $this->assertNotSame(0, $build['exit'], '没有 redis 客户端时 build 不得报成功');
            $this->assertStringContainsString('Redis', $build['output'], '错误信息要说清缺什么');
        }

        $publish = App::runConsole('aetherupload/publish --no-ansi');
        $this->assertSame(0, $publish['exit'], 'aetherupload/publish 必须成功：' . $publish['output']);
        $this->assertStringContainsString('已存在，跳过', $publish['output'], '重复 publish 不得覆盖已发布的语言文件');

        // 发布落点：内核按 translationsPath()/aetherupload/{locale}/messages.php 查找
        $this->assertFileExists(
            App::root() . '/resource/translations/aetherupload/en/messages.php',
            '语言文件必须发布到内核查找的路径上'
        );
        $this->assertFileExists(
            App::root() . '/public/vendor/aetherupload/js/aetherupload-all.js',
            '前端资源必须发布到文档根下的 vendor/aetherupload/js'
        );
    }

    /**
     * 上传对象走「流自带本地 uri」那条路 —— 真机上传时 `$_FILES.tmp_name` 就在那儿，
     * 不该产生任何拷贝（大文件每块都拷一份到临时目录是实打实的浪费，也是丢数据的隐患）。
     *
     * 采样点必须在**请求进行中**：适配器登记的上传临时副本会在请求结束时被删掉，
     * 出了请求再数临时目录永远数不到副本 —— 那样的断言无论实现对错都会绿（假测试）。
     * 所以副本数由事件监听器在 upload_complete 时记录到探针日志里，这里只解析它。
     * 反向验证：把 Psr7UploadedFile::getRealPath() 的「本地 uri 原样使用」分支短路掉，
     * 副本数立刻变成 1，本用例转红。
     */
    public function testUploadUsesLocalFileStreamInPlace(): void
    {
        $log = App::probeLog();

        if ( is_file($log) && ! unlink($log) ) {
            $this->fail('无法清空事件探针日志：' . $log);
        }

        $this->uploadGif();

        $content = (string)@file_get_contents($log);

        $this->assertMatchesRegularExpression(
            '/^copy-probe \d+$/m',
            $content,
            '事件探针必须在请求进行中记录临时副本数（否则本用例又变成了数不到副本的假测试）'
        );

        preg_match('/^copy-probe (\d+)$/m', $content, $matches);

        $this->assertSame(
            '0',
            $matches[1],
            '本地文件流必须原样使用（不复制落盘）：请求进行中临时目录里的 aetherupload-chunk-* 副本必须是 0 个'
        );
    }
}
