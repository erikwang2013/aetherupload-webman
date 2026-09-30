<?php

namespace AetherUpload\Tests;

use AetherUpload\Storage\S3Client;
use AetherUpload\Tests\Support\TestState;
use PHPUnit\Framework\TestCase;

/**
 * S3Client 协议层：注入 FakeS3Transport，逐字节断言请求形态、重试矩阵与 multipart 编排。
 * 所有断言都是「确切输入 → 确切期望」；Authorization 只断言形状与不泄漏，签名正确性由 SignatureV4Test 的官方向量锚定。
 */
class S3ClientTest extends TestCase
{
    private const SECRET = 'wJalrXUtnFEMI/K7MDENG+bPxRfiCYEXAMPLEKEY';

    private string $base = '';

    protected function setUp(): void
    {
        TestState::reset();
        $this->base = TestState::$basePath;
        mkdir($this->base, 0777, true);
    }

    protected function tearDown(): void
    {
        remove_dir(TestState::$basePath);
    }

    private function writeFile(string $name, string $content): string
    {
        $path = $this->base . '/' . $name;
        file_put_contents($path, $content);
        clearstatcache(true, $path);

        return $path;
    }

    private function config(array $overrides = []): array
    {
        return array_merge([
            'endpoint' => 'http://127.0.0.1:9900',
            'region' => 'us-east-1',
            'bucket' => 'b',
            'access_key' => 'AKIDEXAMPLE',
            'secret_key' => self::SECRET,
            'path_style' => true,
            'prefix' => '',
            'multipart_threshold' => 104857600,
        ], $overrides);
    }

    private function client(?FakeS3Transport $transport, array $overrides = []): S3Client
    {
        return new S3Client($this->config($overrides), $transport);
    }

    /** 头名大小写不敏感查找（HTTP 语义；实现按 HTTP 惯例大小写混用） */
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

    /** 独立现算 [offset, offset+length) 字节的 sha256（hash_file 无区间参数，只能 fread 循环） */
    private function sliceSha256(string $path, int $offset, int $length): string
    {
        $handle = fopen($path, 'rb');
        fseek($handle, $offset);

        $context = hash_init('sha256');
        $remaining = $length;

        while ( $remaining > 0 ) {
            $chunk = fread($handle, (int)min(1048576, $remaining));

            if ( $chunk === false || $chunk === '' ) {
                break;
            }

            hash_update($context, $chunk);
            $remaining -= strlen($chunk);
        }

        fclose($handle);

        return hash_final($context);
    }

    public function testPutSendsExactSignedRequestForObjectKey(): void
    {
        $file = $this->writeFile('a.gif', 'GIF89a');
        $transport = new FakeS3Transport();
        $transport->respond(200);

        $this->client($transport, ['prefix' => 'up/'])->put('up/gd/sub/a.gif', $file, 'image/gif');

        $this->assertCount(1, $transport->requests);
        $request = $transport->requests[0];

        $this->assertSame('PUT', $request['method']);
        $this->assertSame('http://127.0.0.1:9900/b/up/gd/sub/a.gif', $request['url']);
        $this->assertSame(hash_file('sha256', $file), $this->header($request['headers'], 'x-amz-content-sha256'));
        $this->assertSame('image/gif', $this->header($request['headers'], 'Content-Type'));
        $this->assertSame($file, $request['bodyFile']);
        $this->assertSame(filesize($file), $request['bodySize'], 'Content-Length 语义：bodySize 必须等于文件大小');
        $this->assertSame(0, $request['bodyOffset']);
        $this->assertNull($request['body']);

        $authorization = $this->header($request['headers'], 'Authorization');
        $this->assertStringStartsWith('AWS4-HMAC-SHA256 Credential=AKIDEXAMPLE/', (string)$authorization);
        $this->assertStringContainsString('SignedHeaders=host;x-amz-content-sha256;x-amz-date', (string)$authorization);
        $this->assertStringNotContainsString(self::SECRET, (string)$authorization, 'Authorization 不得泄漏 secret_key');
        $this->assertSame(0, $transport->remaining());
    }

    public function testObjectUrlFollowsEndpointPathStyleAndRegion(): void
    {
        $matrix = [
            // [配置覆盖, 期望 URL]，自托管必须保端点前缀（端口/子路径），AWS 默认按 region 推导
            [['endpoint' => '', 'region' => 'eu-west-1', 'path_style' => true], 'https://s3.eu-west-1.amazonaws.com/b/gd/sub/a.gif'],
            [['endpoint' => '', 'region' => 'eu-west-1', 'path_style' => false], 'https://b.s3.eu-west-1.amazonaws.com/gd/sub/a.gif'],
            [['endpoint' => 'http://h:9900/base', 'path_style' => true], 'http://h:9900/base/b/gd/sub/a.gif'],
            [['endpoint' => 'http://h:9900/base', 'path_style' => false], 'http://b.h:9900/base/gd/sub/a.gif'],
            [['endpoint' => 'minio.local:9000', 'path_style' => true], 'https://minio.local:9000/b/gd/sub/a.gif'],
        ];

        foreach ( $matrix as $case => $row ) {
            list($overrides, $expected) = $row;

            $transport = new FakeS3Transport();
            $transport->respond(200);

            $this->assertTrue($this->client($transport, $overrides)->head('gd/sub/a.gif'));
            $this->assertSame($expected, $transport->requests[0]['url'], '用例 #' . $case);
        }
    }

    public function testKeyJoinsPrefixGroupSubdirAndName(): void
    {
        $this->assertSame('up/gd/sub/name.gif', $this->client(null, ['prefix' => 'up'])->key('gd', 'sub', 'name.gif'));
        $this->assertSame('up/gd/sub/name.gif', $this->client(null, ['prefix' => 'up/'])->key('gd', 'sub', 'name.gif'));
        $this->assertSame('gd/sub/name.gif', $this->client(null, ['prefix' => ''])->key('gd', 'sub', 'name.gif'));
    }

    public function testHeadMapsStatusesToBooleanAndThrowsOnOthers(): void
    {
        $transport = new FakeS3Transport();

        $transport->respond(200);
        $this->assertTrue($this->client($transport)->head('gd/sub/a.gif'));

        $transport->respond(404);
        $this->assertFalse($this->client($transport)->head('gd/sub/a.gif'));

        $transport->respond(403, [], '<Error><Code>AccessDenied</Code><Message>Access Denied</Message></Error>');
        $error = $this->capture(function () use ($transport) {
            $this->client($transport)->head('gd/sub/a.gif');
        });

        $this->assertNotNull($error, '403 必须抛异常而不是当成「不存在」');
        $this->assertStringContainsString('HTTP 403', $error->getMessage());
        $this->assertStringContainsString('AccessDenied', $error->getMessage());
        $this->assertStringContainsString('gd/sub/a.gif', $error->getMessage());
        $this->assertSame(['HEAD', 'HEAD', 'HEAD'], $transport->methods(), '403 不重试：每个 head() 恰好一次调用');
    }

    public function testDeleteIsIdempotentAndRetriesOnServerErrors(): void
    {
        $transport = new FakeS3Transport();

        $transport->respond(204);
        $this->client($transport)->delete('gd/sub/a.gif');

        $transport->respond(404);
        $this->client($transport)->delete('gd/sub/a.gif');

        $this->assertSame(['DELETE', 'DELETE'], $transport->methods(), '404 是幂等成功，不得重试');

        // 重试会把这次 500 重发满 3 次，脚本必须给足 3 条，否则第 2 次调用撞上「队列耗尽」把断言带偏
        $transport->respond(500, [], '<Error><Code>InternalError</Code><Message>boom</Message></Error>');
        $transport->respond(500, [], '<Error><Code>InternalError</Code><Message>boom</Message></Error>');
        $transport->respond(500, [], '<Error><Code>InternalError</Code><Message>boom</Message></Error>');
        $error = $this->capture(function () use ($transport) {
            $this->client($transport)->delete('gd/sub/a.gif');
        });

        $this->assertNotNull($error);
        $this->assertStringContainsString('HTTP 500', $error->getMessage());
        $this->assertStringContainsString('gd/sub/a.gif', $error->getMessage());
        $this->assertSame(['DELETE', 'DELETE', 'DELETE', 'DELETE', 'DELETE'], $transport->methods(), '500 重试至 3 次后抛');
    }

    public function testRetriesOnNetworkErrorsAndServerErrorsButNotOn4xx(): void
    {
        // 网络异常两次后成功 → 恰 3 次调用
        $transport = new FakeS3Transport();
        $transport->failWith(new \Exception('connection reset'));
        $transport->failWith(new \Exception('connection reset'));
        $transport->respond(200);

        $this->assertTrue($this->client($transport)->head('gd/sub/a.gif'));
        $this->assertCount(3, $transport->requests);
        $this->assertSame(0, $transport->remaining());

        // 403 → 恰 1 次（4xx 不重试）
        $transport = new FakeS3Transport();
        $transport->respond(403, [], '<Error><Code>AccessDenied</Code></Error>');
        $this->capture(function () use ($transport) {
            $this->client($transport)->head('gd/sub/a.gif');
        });
        $this->assertCount(1, $transport->requests);

        // 503,503,200 → 恰 3 次
        $transport = new FakeS3Transport();
        $transport->respond(503);
        $transport->respond(503);
        $transport->respond(200);

        $this->assertTrue($this->client($transport)->head('gd/sub/a.gif'));
        $this->assertCount(3, $transport->requests);
        $this->assertSame(0, $transport->remaining());
    }

    public function testMultipartOrchestrationSendsInitPartAndComplete(): void
    {
        $file = $this->writeFile('small.gif', 'abc'); // 3 字节 → 单片
        $transport = new FakeS3Transport();
        $transport->respond(200, [], '<InitiateMultipartUploadResult><UploadId>u-1</UploadId></InitiateMultipartUploadResult>');
        $transport->respond(200, ['etag' => '"e1"']);
        $transport->respond(200, [], '<CompleteMultipartUploadResult/>');

        $this->client($transport)->multipart('gd/sub/a.gif', $file, 'image/gif');

        $this->assertSame(['POST', 'PUT', 'POST'], $transport->methods());

        $this->assertSame('http://127.0.0.1:9900/b/gd/sub/a.gif?uploads=', $transport->requests[0]['url']);

        $part = $transport->requests[1];
        $this->assertSame('http://127.0.0.1:9900/b/gd/sub/a.gif?partNumber=1&uploadId=u-1', $part['url']);
        $this->assertSame($file, $part['bodyFile']);
        $this->assertSame(3, $part['bodySize']);
        $this->assertSame(0, $part['bodyOffset']);

        $complete = $transport->requests[2];
        $this->assertSame('http://127.0.0.1:9900/b/gd/sub/a.gif?uploadId=u-1', $complete['url']);
        $this->assertSame(
            '<CompleteMultipartUpload><Part><PartNumber>1</PartNumber><ETag>"e1"</ETag></Part></CompleteMultipartUpload>',
            $complete['body'],
            'complete 请求体必须逐字节等于约定 XML（ETag 的双引号是值的一部分）'
        );
        $this->assertSame('application/xml', $this->header($complete['headers'], 'Content-Type'));
        $this->assertSame(0, $transport->remaining());
    }

    public function testCompleteWithEmbeddedErrorAbortsAndThrows(): void
    {
        $file = $this->writeFile('small.gif', 'abc');
        $transport = new FakeS3Transport();
        $transport->respond(200, [], '<InitiateMultipartUploadResult><UploadId>u-1</UploadId></InitiateMultipartUploadResult>');
        $transport->respond(200, ['etag' => '"e1"']);
        // 经典陷阱：HTTP 200 但体内嵌 <Error> 仍是失败
        $transport->respond(200, [], '<Error><Code>InternalError</Code><Message>x</Message></Error>');
        $transport->respond(404); // 失败后 HEAD 对象 → 不在
        $transport->respond(204); // AbortMultipartUpload

        $error = $this->capture(function () use ($transport, $file) {
            $this->client($transport)->multipart('gd/sub/a.gif', $file, 'image/gif');
        });

        $this->assertNotNull($error, '200 + 内嵌 <Error> 必须抛异常');
        $this->assertStringContainsString('InternalError', $error->getMessage());

        $this->assertSame(['POST', 'PUT', 'POST', 'HEAD', 'DELETE'], $transport->methods());
        $last = $transport->requests[count($transport->requests) - 1];
        $this->assertSame('http://127.0.0.1:9900/b/gd/sub/a.gif?uploadId=u-1', $last['url'], '必须发 AbortMultipartUpload');
    }

    public function testLostCompleteResponseIsRescuedByHeadCheck(): void
    {
        $file = $this->writeFile('small.gif', 'abc');
        $transport = new FakeS3Transport();
        $transport->respond(200, [], '<UploadId>u-1</UploadId>');
        $transport->respond(200, ['etag' => '"e1"']);
        // complete 三次都网络异常（响应丢失），但对象实际已写入
        $transport->failWith(new \Exception('timeout'));
        $transport->failWith(new \Exception('timeout'));
        $transport->failWith(new \Exception('timeout'));
        $transport->respond(200); // HEAD 确认对象存在

        $error = $this->capture(function () use ($transport, $file) {
            $this->client($transport)->multipart('gd/sub/a.gif', $file, 'image/gif');
        });

        $this->assertNull($error, 'complete 响应丢失但对象已存在 → 视为成功（spec §5）');
        $this->assertSame(['POST', 'PUT', 'POST', 'POST', 'POST', 'HEAD'], $transport->methods(), 'complete 重试 3 次后 HEAD 兜底');
        $this->assertNotContains('DELETE', $transport->methods(), '成功路径不得发 abort');
        $this->assertSame(0, $transport->remaining());
    }

    public function testMultipartPartPayloadHashCoversTheSliceNotTheWholeFile(): void
    {
        $partSize = 67108864; // spec §4：分片大小固定 64MB，非末片恒为该值

        $file = $this->base . '/large.bin';
        $handle = fopen($file, 'wb');
        for ( $i = 0; $i < 64; $i++ ) {
            fwrite($handle, str_repeat(pack('N', $i), 262144)); // 每块 1MiB，内容按序号各不相同
        }
        fwrite($handle, 'TAIL-16-BYTES!!!'); // 恰好 16 字节的尾片
        fclose($handle);
        clearstatcache(true, $file);

        $this->assertSame($partSize + 16, filesize($file), '前置条件：文件 = 64MiB + 16B，正好两片');

        $transport = new FakeS3Transport();
        $transport->respond(200, [], '<UploadId>u-1</UploadId>');
        $transport->respond(200, ['etag' => '"e1"']);
        $transport->respond(200, ['etag' => '"e2"']);
        $transport->respond(200, [], '<CompleteMultipartUploadResult/>');

        $this->client($transport)->multipart('gd/sub/big.bin', $file, 'application/octet-stream');

        $this->assertSame(['POST', 'PUT', 'PUT', 'POST'], $transport->methods());

        $part1 = $transport->requests[1];
        $part2 = $transport->requests[2];

        $this->assertSame('http://127.0.0.1:9900/b/gd/sub/big.bin?partNumber=1&uploadId=u-1', $part1['url']);
        $this->assertSame(0, $part1['bodyOffset']);
        $this->assertSame($partSize, $part1['bodySize']);
        $this->assertSame('http://127.0.0.1:9900/b/gd/sub/big.bin?partNumber=2&uploadId=u-1', $part2['url']);
        $this->assertSame($partSize, $part2['bodyOffset']);
        $this->assertSame(16, $part2['bodySize']);

        // 分片签名必须覆盖「该分片自己的字节」——用整文件哈希签分片会被真 S3 以 XAmzContentSHA256Mismatch 拒绝
        $wholeFileHash = hash_file('sha256', $file);
        $part1Hash = $this->sliceSha256($file, 0, $partSize);
        $part2Hash = $this->sliceSha256($file, $partSize, 16);

        $this->assertSame($part1Hash, $this->header($part1['headers'], 'x-amz-content-sha256'), 'part1 必须是前 64MiB 切片的 sha256');
        $this->assertNotSame($wholeFileHash, $this->header($part1['headers'], 'x-amz-content-sha256'), '分片签名不得用整文件哈希');
        $this->assertSame($part2Hash, $this->header($part2['headers'], 'x-amz-content-sha256'), 'part2 必须是末 16 字节切片的 sha256');
    }

    public function testUploadMultipartOrPutRoutesOnThresholdBoundary(): void
    {
        $atThreshold = $this->writeFile('at.bin', 'abcd');      // 4 字节 == threshold
        $overThreshold = $this->writeFile('over.bin', 'abcde'); // 5 字节 > threshold

        $transport = new FakeS3Transport();
        $transport->respond(200);
        $this->client($transport, ['multipart_threshold' => 4])
            ->uploadMultipartOrPut('gd/sub/a.bin', $atThreshold, 'application/octet-stream');
        $this->assertSame(['PUT'], $transport->methods(), 'filesize == threshold 必须走单次 PUT');

        $transport = new FakeS3Transport();
        $transport->respond(200, [], '<UploadId>u-1</UploadId>');
        $transport->respond(200, ['etag' => '"e1"']);
        $transport->respond(200, [], '<CompleteMultipartUploadResult/>');
        $this->client($transport, ['multipart_threshold' => 4])
            ->uploadMultipartOrPut('gd/sub/a.bin', $overThreshold, 'application/octet-stream');
        $this->assertSame(['POST', 'PUT', 'POST'], $transport->methods(), 'filesize > threshold 必须走 multipart');
    }

    public function testConstructorRequiresCredentialsAndNamesTheMissingKey(): void
    {
        $base = [
            'endpoint' => 'http://127.0.0.1:9900',
            'region' => 'us-east-1',
            'bucket' => 'b',
            'access_key' => 'AKIDEXAMPLE',
            'secret_key' => 'SUPER-SECRET-VALUE',
        ];

        foreach ( ['region', 'bucket', 'access_key', 'secret_key'] as $missing ) {
            $config = $base;
            $config[$missing] = '';

            $error = $this->capture(function () use ($config) {
                new S3Client($config);
            });

            $this->assertNotNull($error, '缺少 storage.s3.' . $missing . ' 必须构造失败（fail-fast）');
            $this->assertStringContainsString('storage.s3.' . $missing, $error->getMessage());
            $this->assertStringNotContainsString('SUPER-SECRET-VALUE', $error->getMessage(), '错误消息不得回显凭据');
        }
    }

    public function testPresignBuildsSignedQueryWithTtlAndResponseParams(): void
    {
        $url = $this->client(null)->presign('gd/sub/a.gif', 300);

        $this->assertStringStartsWith('http://127.0.0.1:9900/b/gd/sub/a.gif?', $url);
        $this->assertStringContainsString('X-Amz-Algorithm=AWS4-HMAC-SHA256', $url);
        $this->assertStringContainsString('X-Amz-Credential=AKIDEXAMPLE%2F', $url);
        $this->assertStringContainsString('X-Amz-Expires=300', $url);
        $this->assertStringContainsString('X-Amz-SignedHeaders=host', $url);
        $this->assertMatchesRegularExpression('/X-Amz-Signature=[0-9a-f]{64}$/', $url);
        $this->assertStringNotContainsString('response-content-disposition', $url);
        $this->assertStringNotContainsString(self::SECRET, $url, '预签名 URL 不得泄漏 secret_key');

        $disposition = 'attachment; filename="a b.gif"; filename*=UTF-8\'\'a%20b.gif';
        $withParams = $this->client(null)->presign('gd/sub/a.gif', 300, ['response-content-disposition' => $disposition]);

        $this->assertStringContainsString('response-content-disposition=' . rawurlencode($disposition), $withParams, 'responseParams 必须只编码一次后进入签名查询串');
        $this->assertMatchesRegularExpression('/X-Amz-Signature=[0-9a-f]{64}$/', $withParams);
    }

    /**
     * payload_signing='unsigned'：payload 哈希位改发 UNSIGNED-PAYLOAD（华为云 OBS 等只接受该形式的实现）。
     * 三臂对照（默认 / 显式 hash / unsigned）压在同一秒内：日期相同、URL 与其余头逐项相同，
     * 此时签名差异只可能来自 payload 哈希位本身——断言才真正指向 payload_signing，
     * 而不是被 x-amz-date 之类的杂变量「凑巧凑出不同」。
     */
    public function testUnsignedPayloadModeReplacesPayloadHashAndChangesSignature(): void
    {
        $file = $this->writeFile('a.gif', 'GIF89a-unsigned');
        $arms = [];

        for ( $attempt = 0; $attempt < 10; $attempt++ ) {
            $arms = [];

            foreach ( ['default' => null, 'hash' => 'hash', 'unsigned' => 'unsigned'] as $label => $mode ) {
                $transport = new FakeS3Transport();
                $transport->respond(200);
                $this->client($transport, $mode === null ? [] : ['payload_signing' => $mode])
                    ->put('up/gd/sub/a.gif', $file, 'image/gif');
                $arms[$label] = $transport->requests[0];
            }

            $dates = [];
            foreach ( $arms as $request ) {
                $dates[] = $this->header($request['headers'], 'x-amz-date');
            }

            if ( count(array_unique($dates)) === 1 ) {
                break;
            }
        }

        // 对照前提：三臂同一秒、同一 URL；不成立则下面的签名对比无意义，直接失败而不是给出假绿
        $date = $this->header($arms['default']['headers'], 'x-amz-date');
        $this->assertSame($date, $this->header($arms['hash']['headers'], 'x-amz-date'), '对照前提：三臂必须落在同一秒内');
        $this->assertSame($date, $this->header($arms['unsigned']['headers'], 'x-amz-date'), '对照前提：三臂必须落在同一秒内');
        $this->assertSame($arms['default']['url'], $arms['unsigned']['url'], '对照前提：除 payload 哈希位外请求一致');
        $this->assertSame($arms['default']['method'], $arms['unsigned']['method']);

        // 不带该键 = 现状（hash 模式）：与显式 'hash' 逐字节相同
        $this->assertSame(hash_file('sha256', $file), $this->header($arms['default']['headers'], 'x-amz-content-sha256'), '默认必须仍是真实 payload 哈希');
        $this->assertSame(
            $this->header($arms['default']['headers'], 'x-amz-content-sha256'),
            $this->header($arms['hash']['headers'], 'x-amz-content-sha256'),
            "显式 payload_signing='hash' 必须与默认逐字节相同"
        );
        $this->assertSame(
            $this->header($arms['default']['headers'], 'Authorization'),
            $this->header($arms['hash']['headers'], 'Authorization'),
            "显式 payload_signing='hash' 的签名必须与默认逐字节相同"
        );

        // unsigned：哈希位是令牌，且签名必须真的基于该令牌（而非仍按真实哈希签）
        $this->assertSame('UNSIGNED-PAYLOAD', $this->header($arms['unsigned']['headers'], 'x-amz-content-sha256'));
        $this->assertNotSame(
            $this->header($arms['default']['headers'], 'Authorization'),
            $this->header($arms['unsigned']['headers'], 'Authorization'),
            'Authorization 必须签在 x-amz-content-sha256 的值上：两模式签名不得相同'
        );
        $this->assertStringNotContainsString(self::SECRET, (string)$this->header($arms['unsigned']['headers'], 'Authorization'));
    }

    /**
     * unsigned 模式必须覆盖 multipart 的每个请求（含 create/complete：OBS 限制对 XML body 同样适用），
     * 且分片几何（offset/size）不受模式影响。
     */
    public function testUnsignedPayloadModeAppliesToEveryMultipartRequest(): void
    {
        $partSize = 67108864;

        $file = $this->base . '/large-unsigned.bin';
        $handle = fopen($file, 'wb');
        for ( $i = 0; $i < 64; $i++ ) {
            fwrite($handle, str_repeat(pack('N', $i), 262144));
        }
        fwrite($handle, 'TAIL-16-BYTES!!!');
        fclose($handle);
        clearstatcache(true, $file);

        $this->assertSame($partSize + 16, filesize($file), '前置条件：文件 = 64MiB + 16B，正好两片');

        $transport = new FakeS3Transport();
        $transport->respond(200, [], '<UploadId>u-1</UploadId>');
        $transport->respond(200, ['etag' => '"e1"']);
        $transport->respond(200, ['etag' => '"e2"']);
        $transport->respond(200, [], '<CompleteMultipartUploadResult/>');

        $this->client($transport, ['payload_signing' => 'unsigned'])
            ->multipart('gd/sub/big.bin', $file, 'application/octet-stream');

        $this->assertSame(['POST', 'PUT', 'PUT', 'POST'], $transport->methods());
        $this->assertSame(0, $transport->remaining());

        foreach ( $transport->requests as $index => $request ) {
            $this->assertSame(
                'UNSIGNED-PAYLOAD',
                $this->header($request['headers'], 'x-amz-content-sha256'),
                'unsigned 模式下第 ' . ($index + 1) . ' 个请求（' . $request['method'] . '）必须发 UNSIGNED-PAYLOAD'
            );
        }

        $part1 = $transport->requests[1];
        $part2 = $transport->requests[2];
        $this->assertSame(0, $part1['bodyOffset']);
        $this->assertSame($partSize, $part1['bodySize']);
        $this->assertSame($partSize, $part2['bodyOffset']);
        $this->assertSame(16, $part2['bodySize']);
        $this->assertNotSame($this->sliceSha256($file, 0, $partSize), $this->header($part1['headers'], 'x-amz-content-sha256'), 'unsigned 模式不得再按切片现算哈希');
    }

    public function testIllegalPayloadSigningValueIsRejectedAtConstruction(): void
    {
        $error = $this->capture(function () {
            new S3Client($this->config(['payload_signing' => 'md5']));
        });

        $this->assertNotNull($error, "payload_signing='md5' 必须构造失败（只允许 hash|unsigned）");
        $this->assertStringContainsString('storage.s3.payload_signing', $error->getMessage(), '错误消息必须点名配置键');
        $this->assertStringNotContainsString(self::SECRET, $error->getMessage(), '错误消息不得回显凭据');
    }
}
