<?php

namespace AetherUpload\Tests\Integration\ThinkPhp;

use AetherUpload\Tests\Integration\FlowAssertions;
use PHPUnit\Framework\TestCase;
use think\App as ThinkApp;
use think\file\UploadedFile;

/**
 * ThinkPHP 真实端到端：真框架（topthink/framework 8.x）+ 真请求 + 真文件系统。
 *
 * 不引入 topthink/think 骨架、不起 HTTP 服务：ThinkPHP 的入口就是
 * `(new think\App($root))->http->run($request)`，测试进程里构造 think\Request 直接交给它，
 * 走的是与 PHP-FPM 下完全相同的初始化 → 服务注册 → 路由 → 中间件 → 控制器链路。
 * 每次都 new 一个 App 与 Request，等价于 FPM 的「一请求一进程」，也让 Runtime 的
 * 请求隔离（RequestIsolationTest 的契约）在真框架下被顺带验证。
 *
 * 应用准备见 App.php，断言清单见 tests/Integration/FlowAssertions.php。
 */
class ThinkPhpFlowTest extends TestCase
{
    use FlowAssertions;

    /** @var string[] 本次请求落盘的临时上传文件，响应后清理 */
    private $tempFiles = [];

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
        return '本机 ' . App::redisHost() . ':' . App::redisPort() . ' 上没有可用的 redis'
            . '（装了 redis 但返回值不对也会走到这里）；第 8 条秒传未验证，其余 7 条不受影响';
    }

    /**
     * 四个控制台命令由 AetherUploadService 并进 console.commands，这里在真 think\Console 上验证。
     *
     * 只有 aetherupload:groups 真的跑一次（它只读配置 + 补齐目录，幂等）；
     * build 要真 redis 索引数据、clean 会删文件，都不适合在端到端里顺带跑，只断言注册。
     * publish 的落点与幂等由 testPublishWritesToExpectedPathsAndDoesNotOverwrite 单独验证。
     */
    public function testConsoleCommandsAreRegistered(): void
    {
        $app = new ThinkApp(App::root());
        $app->initialize();

        $console = $app->console;

        $this->assertTrue($console->hasCommand('aetherupload:build'), 'aetherupload:build 必须注册进 console.commands');
        $this->assertTrue($console->hasCommand('aetherupload:clean'), 'aetherupload:clean 必须注册进 console.commands');
        $this->assertTrue($console->hasCommand('aetherupload:groups'), 'aetherupload:groups 必须注册进 console.commands');
        $this->assertTrue($console->hasCommand('aetherupload:publish'), 'aetherupload:publish 必须注册进 console.commands');

        $output = (string)$console->call('aetherupload:groups')->fetch();

        $this->assertStringContainsString('Group-Directory List:', $output, 'aetherupload:groups 必须真的执行到业务逻辑');
        $this->assertStringContainsString($this->groupDir(), $output, 'aetherupload:groups 必须列出测试分组');
    }

    /**
     * aetherupload:publish：落点正确 + 重复执行不覆盖已存在的文件。
     *
     * 端到端骨架里的语言文件与前端 js 正是由这条命令发布的（见 App::install()），
     * 所以「第 5 条能断到译文原文」本身已经证明语言文件落到了内核查找的位置；
     * 这里再钉死两件事：两个落点都真有文件、第二遍 publish 不抹掉已存在的文件。
     */
    public function testPublishWritesToExpectedPathsAndDoesNotOverwrite(): void
    {
        $app = new ThinkApp(App::root());
        $console = $app->console;

        // 内核按 translationsPath()/aetherupload/<locale>/messages.php 查找（ThinkPhpPaths → app/lang）
        $translation = App::root() . '/app/lang/aetherupload/en/messages.php';
        $asset = App::root() . '/public/vendor/aetherupload/js/aetherupload-core.js';

        $this->assertFileExists($translation, 'publish 必须把语言文件放进内核查找的位置');
        $this->assertFileExists($asset, 'publish 必须把前端 js 放进 public/vendor/aetherupload/js');

        // 哨兵：模拟「使用者改过的文件」，第二遍 publish 不得抹掉它（copyDir 的硬性语义）
        $sentinel = "// sentinel\n";
        file_put_contents($asset, $sentinel);

        $output = (string)$console->call('aetherupload:publish')->fetch();

        $this->assertStringContainsString('translations → ', $output, 'publish 必须真的执行到业务逻辑');
        $this->assertStringContainsString('Done.', $output, 'publish 必须跑到结束');
        $this->assertSame($sentinel, file_get_contents($asset), 'publish 不得覆盖已存在的文件');
        $this->assertFileExists($translation, 'publish 重复执行不得删掉已有文件');
    }

    /**
     * 发一个请求。
     *
     * $files 形如 ['resource_chunk' => ['name' => 'chunk.gif', 'content' => $bytes]]。
     */
    protected function send(string $method, string $path, array $post = [], array $files = [], array $headers = []): array
    {
        $app = new ThinkApp(App::root());
        $request = new E2ERequest();

        $request->withEnv($app->env)
            ->withServer([
                'REQUEST_METHOD'  => $method,
                'REQUEST_URI'     => $path,
                // 显式给 PATH_INFO：否则 pathinfo() 只在 CLI 下回落到 REQUEST_URI，
                // 换 SAPI 跑（phpdbg）时解析路径会走别的分支
                'PATH_INFO'       => $path,
                'SCRIPT_NAME'     => '/index.php',
                'SCRIPT_FILENAME' => App::root() . '/public/index.php',
                'SERVER_NAME'     => '127.0.0.1',
                'SERVER_PORT'     => '80',
                'SERVER_PROTOCOL' => 'HTTP/1.1',
                'REMOTE_ADDR'     => '127.0.0.1',
                'HTTP_HOST'       => '127.0.0.1',
            ])
            ->withGet([])
            ->withPost($post)
            ->withHeader($this->requestHeaders($headers))
            ->withFiles($this->uploadedFiles($files));

        $temps = $this->tempFiles;
        $level = ob_get_level();

        try {
            $response = $app->http->run($request);

            $status = $response->getCode();

            // 先取 body 再取头：think\response\File::output() 是在 getContent() 里才补上
            // Content-Type / Content-Disposition 那几个头的
            $body = $response->getContent();
            $lines = $this->headerLines($response->getHeader());
        } finally {
            // think\response\File::output() 里有 while (ob_get_level() > 0) ob_end_clean()，
            // 会把 PHPUnit 的输出缓冲一起拆掉，这里补回原来的层数
            while ( ob_get_level() < $level ) {
                ob_start();
            }

            foreach ( $temps as $temp ) {
                @unlink($temp);
            }

            $this->tempFiles = [];
        }

        return ['status' => $status, 'body' => $body, 'headers' => $lines];
    }

    /**
     * 造 think\UploadedFile。
     *
     * 第 5 个参数 $test=true 让 isValid() 跳过 is_uploaded_file()：请求是进程内造的，
     * 没有走 PHP 的上传流程，而内核只依赖 isValid()/getRealPath() 两个能力（见 Kernel\UploadedFile）。
     */
    private function uploadedFiles(array $files): array
    {
        $uploaded = [];

        foreach ( $files as $field => $file ) {
            $temp = tempnam(sys_get_temp_dir(), 'aetherupload-tp-');

            if ( $temp === false || file_put_contents($temp, $file['content']) === false ) {
                $this->fail('无法写入临时上传文件');
            }

            $this->tempFiles[] = $temp;
            $uploaded[$field] = new UploadedFile($temp, $file['name'], 'application/octet-stream', UPLOAD_ERR_OK, true);
        }

        return $uploaded;
    }

    /** 'X-Foo: bar' 行 → ['x-foo' => 'bar']（think\Request::withHeader 要小写键） */
    private function requestHeaders(array $headers): array
    {
        $result = [];

        foreach ( $headers as $header ) {
            $pos = strpos($header, ':');

            if ( $pos !== false ) {
                $result[trim(substr($header, 0, $pos))] = trim(substr($header, $pos + 1));
            }
        }

        return $result;
    }

    /** 响应头数组 → 'Name: value' 行数组（FlowAssertions::header() 按行解析） */
    private function headerLines(array $header): array
    {
        $lines = [];

        foreach ( $header as $name => $value ) {
            foreach ( (array)$value as $one ) {
                $lines[] = $name . ': ' . $one;
            }
        }

        return $lines;
    }
}
