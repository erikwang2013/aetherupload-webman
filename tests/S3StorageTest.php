<?php

namespace AetherUpload\Tests;

use AetherUpload\Storage\S3Storage;
use AetherUpload\Tests\Support\TestState;
use AetherUpload\Tests\Support\UploadFixtures;
use PHPUnit\Framework\TestCase;

/**
 * S3 驱动 S3Storage：去重（HEAD 命中零 PUT）、multipart 选路、错误键归一、预签名 URL。
 * 传输层全部由 FakeS3Transport 脚本化，断言到 URL / 方法序列 / 本地文件去留。
 */
class S3StorageTest extends TestCase
{
    use UploadFixtures;

    private const SECRET = 'wJalrXUtnFEMI/K7MDENG+bPxRfiCYEXAMPLEKEY';

    protected function setUp(): void
    {
        TestState::reset();
        mkdir(TestState::$basePath, 0777, true);
    }

    protected function tearDown(): void
    {
        remove_dir(TestState::$basePath);
    }

    private function config(array $overrides = []): array
    {
        return array_merge([
            'endpoint' => 'http://ep:9000',
            'region' => 'us-east-1',
            'bucket' => 'b',
            'access_key' => 'AKIDEXAMPLE',
            'secret_key' => self::SECRET,
            'path_style' => true,
            'prefix' => 'up',
            'multipart_threshold' => 104857600,
        ], $overrides);
    }

    private function storage(?FakeS3Transport $transport, array $overrides = []): S3Storage
    {
        return new S3Storage($this->config($overrides), $transport);
    }

    /** 与上传链路真实产物同构的最小 GIF（mime_content_type 认作 image/gif） */
    private function gifFile(string $name = 'a.gif'): string
    {
        $path = TestState::$basePath . '/' . $name;
        file_put_contents($path, $this->gifContent());
        clearstatcache(true, $path);

        return $path;
    }

    private function header(array $headers, string $name): ?string
    {
        foreach ( $headers as $key => $value ) {
            if ( strcasecmp((string)$key, $name) === 0 ) {
                return (string)$value;
            }
        }

        return null;
    }

    private function capture(callable $fn): ?\Throwable
    {
        try {
            $fn();
        } catch ( \Throwable $e ) {
            return $e;
        }

        return null;
    }

    public function testPublishSkipsUploadWhenObjectAlreadyExists(): void
    {
        $file = $this->gifFile();
        $transport = new FakeS3Transport();
        $transport->respond(200); // HEAD → 已存在

        $this->storage($transport)->publish($file, 'gd', 'sub', 'a.gif');

        $this->assertSame(['HEAD'], $transport->methods(), '同 hash 去重：对象已存在时零 PUT');
        $this->assertSame('http://ep:9000/b/up/gd/sub/a.gif', $transport->requests[0]['url']);
        $this->assertFileDoesNotExist($file, '去重命中后本地临时文件必须删除');
    }

    public function testPublishUploadsAndRemovesLocalFileWhenObjectMissing(): void
    {
        $file = $this->gifFile();
        $transport = new FakeS3Transport();
        $transport->respond(404); // HEAD → 不存在
        $transport->respond(200); // PUT → 成功

        $this->storage($transport)->publish($file, 'gd', 'sub', 'a.gif');

        $this->assertSame(['HEAD', 'PUT'], $transport->methods());
        $this->assertSame('http://ep:9000/b/up/gd/sub/a.gif', $transport->requests[1]['url']);
        $this->assertSame('image/gif', $this->header($transport->requests[1]['headers'], 'Content-Type'), 'Content-Type 必须按本地文件真实 mime 写入');
        $this->assertSame($file, $transport->requests[1]['bodyFile']);
        $this->assertFileDoesNotExist($file, '上传成功后本地 .part 必须删除');
    }

    public function testPublishKeepsLocalFileAndReportsRenameFailWhenPutFails(): void
    {
        $file = $this->gifFile();
        $transport = new FakeS3Transport();
        $transport->respond(404);
        $transport->respond(500, [], '<Error><Code>InternalError</Code><Message>boom</Message></Error>');

        $error = $this->capture(function () use ($transport, $file) {
            $this->storage($transport)->publish($file, 'gd', 'sub', 'a.gif');
        });

        $this->assertNotNull($error);
        $this->assertSame('rename_resource_fail', $error->getMessage(), '对外统一为上传链路既有错误键（trans 替身原样返回 key）');
        $this->assertFileExists($file, '上传失败时本地文件必须保留，交由 UploadController 的 cleanup 兜底');
        $this->assertSame(['HEAD', 'PUT', 'PUT', 'PUT'], $transport->methods(), '5xx 重试 3 次后才放弃');
    }

    public function testPublishWrapsHeadTransportFailureAsRenameFail(): void
    {
        $file = $this->gifFile();
        $transport = new FakeS3Transport();
        $transport->failWith(new \Exception('dns lookup failed'));
        $transport->failWith(new \Exception('dns lookup failed'));
        $transport->failWith(new \Exception('dns lookup failed'));

        $error = $this->capture(function () use ($transport, $file) {
            $this->storage($transport)->publish($file, 'gd', 'sub', 'a.gif');
        });

        $this->assertNotNull($error);
        $this->assertSame('rename_resource_fail', $error->getMessage());
        $this->assertFileExists($file);
        $this->assertSame(['HEAD', 'HEAD', 'HEAD'], $transport->methods());
    }

    public function testPublishRoutesThroughMultipartAboveThreshold(): void
    {
        $file = $this->gifFile(); // 43 字节
        $transport = new FakeS3Transport();
        $transport->respond(404);
        $transport->respond(200, [], '<UploadId>u-1</UploadId>');
        $transport->respond(200, ['etag' => '"e1"']);
        $transport->respond(200, [], '<CompleteMultipartUploadResult/>');

        $this->storage($transport, ['multipart_threshold' => 10])->publish($file, 'gd', 'sub', 'a.gif');

        $this->assertSame(['HEAD', 'POST', 'PUT', 'POST'], $transport->methods(), '超过阈值必须走 multipart 编排');
        $this->assertStringContainsString('uploads=', $transport->requests[1]['url']);
        $this->assertFileDoesNotExist($file);
    }

    public function testExistsAndDeleteDelegateToHeadAndDelete(): void
    {
        $transport = new FakeS3Transport();
        $storage = $this->storage($transport, ['prefix' => '']);

        $transport->respond(200);
        $this->assertTrue($storage->exists('gd', 'sub', 'a.gif'));
        $this->assertSame('http://ep:9000/b/gd/sub/a.gif', $transport->requests[0]['url']);

        $transport->respond(404);
        $this->assertFalse($storage->exists('gd', 'sub', 'a.gif'));

        $transport->respond(204);
        $this->assertTrue($storage->delete('gd', 'sub', 'a.gif'));

        $this->assertSame(['HEAD', 'HEAD', 'DELETE'], $transport->methods());
        $this->assertSame('http://ep:9000/b/gd/sub/a.gif', $transport->requests[2]['url']);
    }

    public function testUrlReturnsPresignedDownloadWithDispositionParam(): void
    {
        $transport = new FakeS3Transport();
        $storage = $this->storage($transport);

        $plain = $storage->url('gd', 'sub', 'a.gif');

        $this->assertStringStartsWith('http://ep:9000/b/up/gd/sub/a.gif?', $plain);
        $this->assertStringContainsString('X-Amz-Signature=', $plain);
        $this->assertMatchesRegularExpression('/X-Amz-Signature=[0-9a-f]{64}$/', $plain);
        $this->assertStringNotContainsString(self::SECRET, $plain, '预签名 URL 不得泄漏 secret_key');

        $disposition = 'attachment; filename="a b.gif"; filename*=UTF-8\'\'a%20b.gif';
        $withParams = $storage->url('gd', 'sub', 'a.gif', ['response-content-disposition' => $disposition]);

        $this->assertStringContainsString('response-content-disposition=' . rawurlencode($disposition), $withParams);
        $this->assertStringContainsString('%20', $withParams, '空格必须编码为 %20');
        $this->assertSame([], $transport->requests, '预签名是纯本地计算，不得发出任何 HTTP 请求');
    }
}
