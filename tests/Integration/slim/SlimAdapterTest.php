<?php

namespace AetherUpload\Tests\Integration\Slim;

use AetherUpload\Adapter\Slim\Bootstrap;
use AetherUpload\Adapter\Slim\Console\Application as AetherUploadConsole;
use AetherUpload\Adapter\Slim\Event;
use AetherUpload\Adapter\Slim\Response;
use AetherUpload\Adapter\Slim\SlimAdapter;
use AetherUpload\Adapter\Slim\SlimEvents;
use AetherUpload\Adapter\Slim\SlimTranslator;
use AetherUpload\Adapter\Slim\SlimUploadedFile;
use AetherUpload\Runtime;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;
use Slim\Psr7\UploadedFile;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Slim 特有的行为（共享的 FlowAssertions 表达不了的那些），每条都对应一个真实约束：
 *
 *  1. 上传对象没有 getRealPath()，两条取路径的路都要能用（本文件实测走的是哪条）
 *  2. PSR-7 响应不可变 → 内核产物是可变的 shim，落成真 PSR-7 有唯一转换点
 *  3. 执行上下文身份 = 当前请求对象，请求切换必须换一份 RequestContext
 *  4. 端口契约：翻译未命中返回 key 本身、事件监听器异常不冒泡、发布命令不覆盖已存在文件
 */
class SlimAdapterTest extends TestCase
{
    // ------------------------------------------------ 上传对象：两条取路径的路

    /** 真机与 SlimFlowTest 走的路：流自带本地 uri，直接用，不拷贝、不动原文件 */
    public function testLocalFileStreamIsUsedInPlace(): void
    {
        $bytes = "chunk-bytes-\x00\x01\x02";
        $temp = tempnam(sys_get_temp_dir(), 'aetherupload-e2e-');
        file_put_contents($temp, $bytes);

        $registered = [];
        $port = new SlimUploadedFile(
            new UploadedFile($temp, 'chunk.gif', 'application/octet-stream', strlen($bytes), UPLOAD_ERR_OK, false),
            function (string $path) use (&$registered): void {
                $registered[] = $path;
            }
        );

        try {
            $this->assertTrue($port->isValid());
            $this->assertSame($temp, $port->getRealPath(), '本地文件流必须原样给出 uri（不复制落盘）');
            $this->assertSame([], $registered, '走 uri 这条路时不应登记任何临时文件');
        } finally {
            @unlink($temp);
        }
    }

    /** 没有本地 uri 的流（php://temp）必须自己落盘，并登记清理 */
    public function testMemoryStreamIsCopiedToTempFileAndRegistered(): void
    {
        $bytes = "chunk-bytes-\x03\x04\x05";
        $port = new SlimUploadedFile(
            new UploadedFile((new StreamFactory())->createStream($bytes), 'chunk.gif', 'application/octet-stream', strlen($bytes), UPLOAD_ERR_OK),
            function (string $path) use (&$registered): void {
                $registered[] = $path;
            }
        );

        $path = $port->getRealPath();

        try {
            $this->assertNotSame('php://temp', $path, '内存流不能把 php://temp 当路径交出去');
            $this->assertFileExists($path);
            $this->assertSame($bytes, file_get_contents($path), '回落落盘的内容必须与流一致');
            $this->assertSame([$path], $registered, '回落路径必须登记，否则请求结束不会清理');
            $this->assertSame($path, $port->getRealPath(), '路径只解析一次');
        } finally {
            @unlink($path);
        }
    }

    /**
     * 回落路的端到端：整条上传用内存流做分块，落盘内容仍逐字节相等，且临时副本请求结束即删。
     */
    public function testEndToEndUploadWithMemoryStreamChunks(): void
    {
        $bytes = "GIF89a\x01\x00\x01\x00\x80\x00\x00" . random_bytes(6)
            . "\x21\xf9\x04\x01\x00\x00\x00\x00"
            . "\x2c\x00\x00\x00\x00\x01\x00\x01\x00\x00"
            . "\x02\x02\x44\x01\x00"
            . "\x3b";

        $pre = $this->json($this->sendStreamed('POST', '/aetherupload/preprocess', [
            'resource_name' => 'memory.gif',
            'resource_size' => strlen($bytes),
            'group'         => 'file',
            'resource_hash' => md5($bytes),
            'locale'        => 'en',
        ]));

        $this->assertSame(0, $pre['error'], 'preprocess 必须成功：' . json_encode($pre, JSON_UNESCAPED_UNICODE));

        $chunks = str_split($bytes, (int)ceil(strlen($bytes) / 3));
        $last = [];

        foreach ( $chunks as $index => $chunk ) {
            $last = $this->json($this->sendStreamed('POST', '/aetherupload/uploading', [
                'chunk_total'            => 3,
                'chunk_index'            => $index + 1,
                'resource_temp_basename' => $pre['resourceTempBaseName'],
                'resource_ext'           => 'gif',
                'group_subdir'           => $pre['groupSubDir'],
                'group'                  => 'file',
                'resource_hash'          => md5($bytes),
                'locale'                 => 'en',
            ], ['name' => 'chunk.gif', 'content' => $chunk]));

            $this->assertSame(0, $last['error'], '第 ' . ($index + 1) . ' 块必须成功：' . json_encode($last, JSON_UNESCAPED_UNICODE));
        }

        $complete = App::root() . '/storage/app/aetherupload/file/' . $pre['groupSubDir'] . '/' . md5($bytes) . '.gif';

        $this->assertFileExists($complete);
        $this->assertSame($bytes, file_get_contents($complete), '内存流分块拼出的内容必须与上传字节一致');
        $this->assertSame([], glob(sys_get_temp_dir() . '/aetherupload-chunk-*'), '没有本地 uri 的流会落盘成 aetherupload-chunk-*，请求结束必须删干净');
    }

    /** 真实（本地文件）上传不产生任何副本 —— 这条断言就是「实测走的是哪条路」的证据 */
    public function testRealUploadDoesNotLeaveCopies(): void
    {
        $bytes = "GIF89a\x01\x00\x01\x00\x80\x00\x00" . random_bytes(6)
            . "\x21\xf9\x04\x01\x00\x00\x00\x00"
            . "\x2c\x00\x00\x00\x00\x01\x00\x01\x00\x00"
            . "\x02\x02\x44\x01\x00"
            . "\x3b";

        $pre = $this->json($this->sendStreamed('POST', '/aetherupload/preprocess', [
            'resource_name' => 'real.gif',
            'resource_size' => strlen($bytes),
            'group'         => 'file',
            'resource_hash' => md5($bytes),
            'locale'        => 'en',
        ]));

        $this->assertSame(0, $pre['error'], 'preprocess 必须成功：' . json_encode($pre, JSON_UNESCAPED_UNICODE));

        $last = $this->json($this->sendStreamed('POST', '/aetherupload/uploading', [
            'chunk_total'            => 1,
            'chunk_index'            => 1,
            'resource_temp_basename' => $pre['resourceTempBaseName'],
            'resource_ext'           => 'gif',
            'group_subdir'           => $pre['groupSubDir'],
            'group'                  => 'file',
            'resource_hash'          => md5($bytes),
            'locale'                 => 'en',
        ], ['name' => 'chunk.gif', 'content' => $bytes]));

        // 单块即完成
        $this->assertSame(0, $last['error'], json_encode($last, JSON_UNESCAPED_UNICODE));
        $this->assertSame([], glob(sys_get_temp_dir() . '/aetherupload-chunk-*'), '本地文件流必须被就地读取，不能有副本');
    }

    // ------------------------------------------------ 可变 shim → 真 PSR-7

    /** 丢弃 withHeader 返回值也要生效（webman 语义；PSR-7 不可变响应做不到，这正是 shim 存在的理由） */
    public function testDiscardedWithHeaderReturnValueStillLands(): void
    {
        $shim = Response::text('x', 201);
        $shim->withHeader('X-Dropped', 'kept');

        $psr7 = $shim->toPsr7(new ResponseFactory());

        $this->assertSame(201, $psr7->getStatusCode());
        $this->assertSame('kept', $psr7->getHeaderLine('X-Dropped'));
        $this->assertSame('x', (string)$psr7->getBody());
    }

    /** 文件响应在有 PSR-17 流工厂时直接交出文件流（零拷贝），不是整份读进内存 */
    public function testFileResponseIsBackedByTheRealFileStream(): void
    {
        $temp = tempnam(sys_get_temp_dir(), 'aetherupload-e2e-');
        file_put_contents($temp, 'file-bytes');

        try {
            $psr7 = Response::file($temp)->withHeader('X-Content-Type-Options', 'nosniff')->toPsr7(new ResponseFactory(), new StreamFactory());

            $this->assertSame($temp, $psr7->getBody()->getMetadata('uri'), '响应体必须是文件流本身');
            $this->assertSame('file-bytes', (string)$psr7->getBody());
            $this->assertSame('nosniff', $psr7->getHeaderLine('X-Content-Type-Options'));
            $this->assertNotSame('', $psr7->getHeaderLine('Content-Type'), '展示/下载要补 Content-Type（响应带 nosniff，缺了浏览器不渲染）');
        } finally {
            @unlink($temp);
        }
    }

    /** 没有流工厂时也必须给出正确字节（分块拷贝，不整份进内存） */
    public function testFileResponseWithoutStreamFactoryStillCarriesBytes(): void
    {
        $temp = tempnam(sys_get_temp_dir(), 'aetherupload-e2e-');
        file_put_contents($temp, str_repeat('A', 300000));

        try {
            $psr7 = Response::file($temp)->toPsr7(new ResponseFactory());

            $this->assertSame(str_repeat('A', 300000), (string)$psr7->getBody());
        } finally {
            @unlink($temp);
        }
    }

    /** 下载响应必须带 Content-Disposition */
    public function testDownloadResponseCarriesAttachmentName(): void
    {
        $temp = tempnam(sys_get_temp_dir(), 'aetherupload-e2e-');
        file_put_contents($temp, 'file-bytes');

        try {
            $psr7 = Response::download($temp, 'new.gif')->toPsr7(new ResponseFactory(), new StreamFactory());

            $this->assertStringContainsString('new.gif', $psr7->getHeaderLine('Content-Disposition'));
        } finally {
            @unlink($temp);
        }
    }

    /** 未知路由必须是 404：不挂错误中间件的话 Slim 会把 HttpNotFoundException 抛成 500 */
    public function testUnknownRouteIsNotFoundNotServerError(): void
    {
        $this->assertSame(404, $this->sendStreamed('GET', '/definitely-not-a-route')['status']);
    }

    /**
     * redis 选项写成容器里的服务 id 时必须取到容器里那一个实例（Slim 的服务都放 PSR-11 容器）。
     * 临时改绑 Runtime，结束后恢复，免得影响别的用例。
     */
    public function testRedisServiceIdIsResolvedFromTheContainer(): void
    {
        $container = new class implements ContainerInterface {
            /** @var object|null */
            public $service;

            public function get(string $id)
            {
                if ( ! $this->has($id) ) {
                    throw new \RuntimeException('unknown service: ' . $id);
                }

                return $this->service;
            }

            public function has(string $id): bool
            {
                return $id === 'redis.e2e' && $this->service !== null;
            }
        };

        $container->service = new class {
            /** phpredis / predis 的方法名一致，都是小写 */
            public function get(string $key)
            {
                return 'from-container:' . $key;
            }
        };

        $original = Runtime::adapter();

        try {
            $app = Bootstrap::create([
                'container' => $container,
                'base_path' => App::root(),
                'redis'     => 'redis.e2e',
            ]);

            $this->assertSame('from-container:probe', Runtime::adapter()->redis()->get('probe'));

            // 有容器时 Slim 会把中间件与路由回调 bindTo($container)，
            // 静态闭包会在这里变成 null —— 所以这条请求是必需的（只建应用测不出来）
            $bytes = "GIF89a\x01\x00\x01\x00\x80\x00\x00" . random_bytes(6)
                . "\x21\xf9\x04\x01\x00\x00\x00\x00\x2c\x00\x00\x00\x00\x01\x00\x01\x00\x00\x02\x02\x44\x01\x00\x3b";
            $request = (new ServerRequestFactory())->createServerRequest('POST', 'http://127.0.0.1/aetherupload/preprocess')
                ->withParsedBody([
                    'resource_name' => 'container.gif',
                    'resource_size' => strlen($bytes),
                    'group'         => 'file',
                    'resource_hash' => md5($bytes),
                    'locale'        => 'en',
                ]);

            $result = json_decode((string)$app->handle($request)->getBody(), true);

            $this->assertIsArray($result, '带着容器走完路由后响应必须是 JSON');
            $this->assertSame(0, $result['error'], '容器版应用必须能跑通 preprocess');
        } finally {
            Runtime::bind($original);
        }
    }

    // ------------------------------------------------ 控制台：发布命令

    /**
     * aetherupload:publish 必须把前端脚本与语言文件放到约定落点，且**不覆盖已存在的文件**
     * （Runtime::filesystem()->copyDir() 的语义，与 webman 的 Install 一致）。
     * 骨架里其它用例依赖的翻译文件不动，这里拿前端脚本做破坏性验证。
     */
    public function testPublishCommandPutsAssetsInPlaceAndDoesNotOverwrite(): void
    {
        $assets = Runtime::paths()->assetPath();
        $asset = $assets . '/aetherupload-all.js';

        @unlink($asset);
        $this->assertFileDoesNotExist($asset);

        // 先把目标删掉再发布：否则这条断言只是「之前就存在」，证明不了是发布命令放进去的
        $this->assertStringContainsString($assets, $this->runConsole('aetherupload:publish'), '发布命令必须报告资源落点');
        $this->assertFileExists($asset, '前端脚本必须落到 assetPath()');
        $this->assertFileExists(Runtime::paths()->translationsPath() . '/aetherupload/en/messages.php', '语言文件必须落到 translationsPath()/aetherupload');

        // 使用者改过的文件放哨兵，同时删掉另一个待发布文件：
        // 再发布一次必须「哨兵原样保留」且「缺的那个补回来」—— 不覆盖是逐文件的，不是整个目录一看到就跳过
        $edited = '// user edit ' . uniqid() . "\n";
        $core = $assets . '/aetherupload-core.js';
        file_put_contents($asset, $edited);
        @unlink($core);
        $this->assertFileDoesNotExist($core);

        $this->runConsole('aetherupload:publish');

        $this->assertSame($edited, file_get_contents($asset), '重复发布不得覆盖已存在的文件');
        $this->assertFileExists($core, '已存在的文件不覆盖，但缺失的文件仍要补齐');
    }

    /** 在进程内跑一条控制台命令（App::root() 里的 bin/aetherupload 就是同一套 Application） */
    private function runConsole(string $command): string
    {
        $application = new AetherUploadConsole();
        $application->setAutoExit(false);

        $output = new BufferedOutput();
        $status = $application->run(new ArrayInput(['command' => $command]), $output);
        $text = $output->fetch();

        $this->assertSame(0, $status, '命令 ' . $command . ' 失败：' . $text);

        return $text;
    }

    // ------------------------------------------------ 执行上下文与端口

    /** contextToken = 当前请求对象：换请求必须换一份 RequestContext，请求结束要清空 */
    public function testContextTokenFollowsTheCurrentRequest(): void
    {
        /** @var SlimAdapter $adapter */
        $adapter = Runtime::adapter();
        $factory = new ServerRequestFactory();

        $this->assertNull($adapter->contextToken(), '没有请求时上下文身份为 null（CLI 退化）');

        $adapter->beginRequest($factory->createServerRequest('GET', 'http://127.0.0.1/'));
        $first = Runtime::context();

        $adapter->endRequest();
        $this->assertNull($adapter->contextToken(), '请求结束必须清掉当前请求');

        $adapter->beginRequest($factory->createServerRequest('GET', 'http://127.0.0.1/'));
        $second = Runtime::context();
        $adapter->endRequest();

        $this->assertNotSame($first, $second, '换了请求就必须换一份 RequestContext（分组配置不能跨请求残留）');
    }

    /** 翻译端口：未命中返回 key 本身、幂等装载、语种不跨上下文泄漏 */
    public function testTranslatorContract(): void
    {
        $translator = new SlimTranslator();
        $directory = App::root() . '/resource/translations/aetherupload';

        $translator->loadMessages($directory, 'en');
        $translator->loadMessages($directory, 'en'); // 幂等：重复登记不重读文件

        $translator->setLocale('en');
        $this->assertSame('Error: error occurs during upload', $translator->trans('upload_error'));

        // 未装载的语种：必须回落成 key 本身，绝不做 en fallback（内核的 fail() 白名单靠这条）
        $translator->setLocale('zz');
        $this->assertSame('upload_error', $translator->trans('upload_error'));

        $translator->setLocale('en');
        $this->assertSame('en', $translator->getLocale());
    }

    /** 事件端口：本地监听器逐个隔离、外部派发器异常不冒泡 */
    public function testListenerExceptionDoesNotBubbleToTheDispatcher(): void
    {
        $called = [];

        $events = new SlimEvents([
            'aetherupload.upload_complete' => [
                static function (Event $event): void {
                    throw new \RuntimeException('probe: must not bubble');
                },
                static function (Event $event) use (&$called): void {
                    $called[] = $event->name;
                },
            ],
        ], static function (Event $event): void {
            throw new \RuntimeException('外部派发器也抛');
        });

        $events->emit('aetherupload.upload_complete', (object)['name' => 'x']);

        $this->assertSame(['aetherupload.upload_complete'], $called, '前一个监听器抛异常不得影响后一个');
    }

    // ------------------------------------------------------------- 收发

    /** 发一个请求；$chunk 给出时以内存流（没有本地 uri）作为上传对象 */
    private function sendStreamed(string $method, string $path, array $post = [], ?array $chunk = null): array
    {
        $request = (new ServerRequestFactory())->createServerRequest($method, 'http://127.0.0.1' . $path);

        $request = $request->withParsedBody($post);

        if ( $chunk !== null ) {
            $stream = (new StreamFactory())->createStream($chunk['content']);

            $request = $request->withUploadedFiles([
                'resource_chunk' => new UploadedFile($stream, $chunk['name'], 'application/octet-stream', strlen($chunk['content']), UPLOAD_ERR_OK),
            ]);
        }

        $response = App::app()->handle($request);

        return ['status' => $response->getStatusCode(), 'body' => (string)$response->getBody(), 'headers' => []];
    }

    private function json(array $response): array
    {
        $data = json_decode($response['body'], true);

        if ( ! is_array($data) ) {
            $this->fail('响应不是合法 JSON（status ' . $response['status'] . '）：' . substr($response['body'], 0, 500));
        }

        return $data;
    }
}
