<?php

namespace AetherUpload\Tests\Integration\Webman;

use AetherUpload\Tests\Integration\FlowAssertions;
use PHPUnit\Framework\TestCase;

/**
 * webman + 真 S3 兼容服务端（MinIO / SeaweedFS / 外部端点）的端到端。
 *
 * 与 WebmanFlowTest 只有一处前提不同：storage.driver=s3。由此全部落盘断言翻转：
 *   - 成品不得留在本地（publish 落桶后删本地），桶内对象必须与源字节相等；
 *   - 字节校验走 mc（MinIO 官方客户端），独立于我们自己的 S3 客户端，避免自证；
 *   - display/download 是 302 到预签名 URL：既断言 302/Location，也真跟随 Location 取回字节
 *     （跟随只用 GET：预签名把 HTTP 方法签进了 canonical request，HEAD 会签名不匹配）。
 *
 * 服务端解析顺序见 App::ensureS3Server()（env 端点 → 本机缓存二进制 → docker 镜像）；
 * 一个都没有时每个用例 markTestSkipped 并写明原因 —— **绝不假绿**。
 * 另有验签负例（错误 secret 必须被服务端拒绝），否则正例只是「服务端没验签」的空证明。
 *
 * 用例清单：
 *   [S1] 完整链路（单次 PUT）：savedPath 形态不变、.part/header 清掉、本地无成品、桶内字节相等
 *   [S2] display → 302 + Location（指向桶内对象的预签名 URL）→ 跟随取回逐字节相等
 *   [S3] download 改名 → 302 的 URL 带 response-content-disposition → 跟随取回带新文件名
 *   [S4] 秒传：同 hash 二次 preprocess 直接回 savedPath、不建 .part（需 redis）
 *   [S5] multipart 真跑：> threshold 的 GIF 走分片上传，对象字节相等且服务端留下分片痕迹
 *   [S6] 内联黑名单：svg 的 display 也 302，且 disposition 为 attachment
 *   [S7] 验签负例：错误 secret 被拒 + 正确凭据可列桶（一正一负）
 *   另继承 FlowAssertions 的错误 hash/输入边界与事件探针两条（与存储驱动无关，S3 模式下同样必须成立）
 *
 * 运行：vendor/bin/phpunit -c tests/Integration/webman/phpunit-s3.xml
 */
class S3FlowTest extends TestCase
{
    use FlowAssertions;
    use WebmanHttp;

    /** 内联黑名单用例的最小合法 SVG（svg 在扩展名白名单里，但 display 必须转为附件） */
    const SVG_BYTES = <<<'SVG'
<?xml version="1.0" encoding="UTF-8"?>
<svg xmlns="http://www.w3.org/2000/svg" width="1" height="1"><rect width="1" height="1" fill="#000"/></svg>
SVG;

    protected function setUp(): void
    {
        parent::setUp();

        if ( getenv('AETHERA_E2E_S3') === '0' ) {
            $this->markTestSkipped('AETHERA_E2E_S3=0：S3 端到端被显式关闭，未验证（不假装通过）');
        }

        if ( ! App::s3Mode() ) {
            $this->markTestSkipped('本套件要求 AETHERA_E2E_S3=1（见 phpunit-s3.xml 的 <env>）；当前未设置，S3 端到端未验证');
        }

        if ( ! App::s3ServerAvailable() ) {
            // 上一条用例之后服务端可能被别的运行的收尾带走了（缓存目录是共享的）：原地再解析一次
            // （缓存二进制还在就能重启）。真起不来时 ensureS3Server() 会抛异常 —— 那是环境坏了，
            // 响亮地失败，而不是把「服务端半路消失」静默降级成 skipped
            App::ensureS3Server();
        }

        if ( ! App::s3ServerAvailable() ) {
            $this->markTestSkipped(
                '本机没有可用 S3 服务端（' . App::s3Endpoint() . ' 未探活；' . App::s3ResolutionHint() . '），S3 端到端未验证'
            );
        }

        // 每条用例从「无秒传记录」开始：残留记录会让第二次 preprocess 短路，看不到真实的落桶过程
        App::flushInstantCompletionKeys();
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
        return '本机 ' . App::redisHost() . ':' . App::redisPort() . ' 上没有可用的 redis；'
            . '[S4] 秒传未验证，其余用例不受影响';
    }

    // ------------------------------------------------------------- 用例

    /** [S1] 完整链路（单次 PUT）：成品只进桶、本地不留、桶内字节与源逐字节相等（mc 独立校验） */
    public function testFullUploadFlow(): void
    {
        $flow = $this->uploadBytes('gif', $this->gifBytes());

        $this->assertSame(0, $flow['last']['error'], '[S1] 完整上传必须成功：' . json_encode($flow['last'], JSON_UNESCAPED_UNICODE));
        $this->assertMatchesRegularExpression('/^file_\d{6}_[0-9a-f]{32}\.gif$/', $flow['savedPath'], '[S1] savedPath 形态必须与 local 模式一致');
        $this->assertFileDoesNotExist($this->partPathFor('gif', $flow['subdir'], $flow['temp']), '[S1] 完成后 .part 必须消失');
        $this->assertFileDoesNotExist($this->headerPath($flow['temp']), '[S1] 完成后 header 必须消失');
        $this->assertFileDoesNotExist($this->completePath($flow['subdir'], $flow['hash']), '[S1] S3 模式下成品不得留在本地磁盘');

        $key = $this->objectKey($flow);
        $this->assertSame(md5($flow['bytes']), App::s3ObjectMd5($key), '[S1] 桶内对象必须与源文件逐字节相等（mc cat 独立读出，key=' . $key . '）');
    }

    /** [S2] display：302 到预签名 URL，跟随 Location 取回逐字节相同的字节 */
    public function testDisplayRedirectsToPresignedUrl(): void
    {
        $flow = $this->uploadBytes('gif', $this->gifBytes());
        $display = $this->send('GET', $this->path('display') . '/' . $flow['savedPath']);

        $this->assertSame(302, $display['status'], '[S2] S3 模式下 display 必须 302 到预签名 URL，而不是自己发文件');
        $this->assertSame('nosniff', $this->header($display['headers'], 'X-Content-Type-Options'), '[S2] 302 必须带 nosniff');

        $location = $this->header($display['headers'], 'Location');
        $this->assertStringStartsWith(
            App::s3Endpoint() . '/' . App::s3Bucket() . '/' . $this->objectKey($flow),
            $location,
            '[S2] Location 必须指向桶内对象：' . $location
        );
        $this->assertStringContainsString('X-Amz-Signature=', $location, '[S2] Location 必须是预签名 URL');

        $followed = App::httpFetch($location);
        $this->assertSame(200, $followed['status'], '[S2] 跟随 302 必须取回对象：' . substr($followed['body'], 0, 300));
        $this->assertSame($flow['bytes'], $followed['body'], '[S2] 302 之后取回的字节必须与上传字节逐字节相等');
    }

    /** [S3] download 改名：302 的预签名 URL 带 response-content-disposition，跟随取回带新文件名 */
    public function testDownloadRedirectsWithDisposition(): void
    {
        $flow = $this->uploadBytes('gif', $this->gifBytes());
        $download = $this->send('GET', $this->path('download') . '/' . $flow['savedPath'] . '/new.gif');

        $this->assertSame(302, $download['status'], '[S3] S3 模式下 download 必须 302 到预签名 URL');
        $location = $this->header($download['headers'], 'Location');
        $this->assertStringContainsString('response-content-disposition', $location, '[S3] 改名必须签进预签名 URL 的 response-content-disposition');

        // 跟随只用 GET：预签名把方法签进了 canonical request，HEAD 会签名不匹配
        $followed = App::httpFetch($location);
        $this->assertSame(200, $followed['status'], '[S3] 跟随预签名 URL 必须成功：' . substr($followed['body'], 0, 300));
        $this->assertStringContainsString('new.gif', $this->header($followed['headers'], 'Content-Disposition'), '[S3] 取回时必须带改名后的 Content-Disposition');
        $this->assertSame($flow['bytes'], $followed['body'], '[S3] 改名下载的字节必须逐字节相等');
    }

    /** [S4] 秒传：S3 模式下同 hash 二次 preprocess 直接回 savedPath、不建 .part */
    public function testInstantCompletion(): void
    {
        if ( ! $this->supportsInstantCompletion() ) {
            $this->markTestSkipped($this->instantCompletionSkipReason());
        }

        $flow = $this->uploadBytes('gif', $this->gifBytes());

        $this->assertNotSame('', $flow['savedPath'], '[S4] 首次上传必须落桶');
        $this->assertSame(md5($flow['bytes']), App::s3ObjectMd5($this->objectKey($flow)), '[S4] 首次上传的对象必须真的在桶里');

        $pre = $this->preprocess($flow['bytes'], $flow['hash'], 'en');

        $this->assertSame(0, $pre['error'], '[S4] 秒传 preprocess 必须成功');
        $this->assertSame($flow['savedPath'], $pre['savedPath'], '[S4] 秒传必须直接回已存在的 savedPath');
        $this->assertFileDoesNotExist($this->partPathFor('gif', $pre['groupSubDir'], $pre['resourceTempBaseName']), '[S4] 秒传不得新建 .part');
        $this->assertFileDoesNotExist($this->headerPath($pre['resourceTempBaseName']), '[S4] 秒传不得新建 header');
    }

    /** [S5] multipart 真跑：> multipart_threshold 的 GIF 必须走分片上传，对象字节相等且服务端留下分片痕迹 */
    public function testMultipartUploadLandsByteIdenticalObject(): void
    {
        $bytes = $this->paddedGifBytes();

        $this->assertGreaterThan(
            App::S3_MULTIPART_THRESHOLD,
            strlen($bytes),
            '[S5] 前置：fixture（' . strlen($bytes) . 'B）必须大于 multipart_threshold（' . App::S3_MULTIPART_THRESHOLD . 'B）'
        );

        $flow = $this->uploadBytes('gif', $bytes);
        $key = $this->objectKey($flow);

        $this->assertSame(0, $flow['last']['error'], '[S5] multipart 上传必须成功：' . json_encode($flow['last'], JSON_UNESCAPED_UNICODE));
        $this->assertSame(md5($bytes), App::s3ObjectMd5($key), '[S5] multipart 上传的对象必须与源文件逐字节相等（key=' . $key . '）');

        // 服务端侧痕迹：证明走的真是分片上传，而不是悄悄退化成单次 PUT
        $evidence = App::s3MultipartEvidence($key);

        if ( $evidence['multipart'] === true ) {
            $this->assertTrue(true, '[S5] 服务端留下分片痕迹：' . $evidence['detail']);
        } elseif ( $evidence['multipart'] === false ) {
            $this->fail('[S5] 文件 ' . strlen($bytes) . 'B > 阈值 ' . App::S3_MULTIPART_THRESHOLD . 'B，服务端却按单次 PUT 收下：' . $evidence['detail']);
        } else {
            // 服务端给不出可判别信息（非 MinIO 实现等）时如实说明，不静默放过
            fwrite(STDERR, "\n[S5] 注意：服务端未暴露可判别的分片痕迹（" . App::s3EvidenceSource() . "）：" . $evidence['detail'] . "，"
                . "本用例的 multipart 真跑证据退化为「对象字节相等 + 阈值选路（单测锁定）」\n");
        }
    }

    /** [S6] 内联黑名单：svg 在白名单里但禁止内联，display 也必须 302 成 attachment */
    public function testInlineBlockedExtensionIsForcedToAttachment(): void
    {
        $flow = $this->uploadBytes('svg', self::SVG_BYTES);
        $display = $this->send('GET', $this->path('display') . '/' . $flow['savedPath']);

        $this->assertSame(302, $display['status'], '[S6] svg 的 display 也必须 302（不内联）');
        $location = $this->header($display['headers'], 'Location');
        $this->assertStringContainsString('response-content-disposition', $location, '[S6] 内联黑名单必须体现在 disposition 覆写上');

        $followed = App::httpFetch($location);
        $this->assertSame(200, $followed['status'], '[S6] 跟随预签名 URL 必须成功：' . substr($followed['body'], 0, 300));
        $this->assertStringContainsString('attachment', $this->header($followed['headers'], 'Content-Disposition'), '[S6] 取回必须是 attachment');
        $this->assertSame($flow['bytes'], $followed['body'], '[S6] 附件内容必须逐字节相等');
    }

    /** [S7] 验签负例：错误 secret 必须被服务端拒绝；正确凭据能列出桶 —— 一正一负才证明正例不是空证明 */
    public function testServerEnforcesSignature(): void
    {
        $positive = App::mcRun(['ls', 'e2e/' . App::s3Bucket()]);
        $this->assertSame(0, $positive['code'], '[S7] 正确凭据必须能列出桶：' . trim($positive['stdout'] . $positive['stderr']));

        $negative = App::mcRun(['ls', 'bad/' . App::s3Bucket()]);
        $this->assertNotSame(0, $negative['code'], '[S7] 错误 secret 必须被拒：若这条通过，说明服务端根本没在验签，正例是空证明');
        // mc 把服务端 XML 的 Message 直接打出来（"The request signature we calculated does not match…"），
        // 不一定带 AWS 的 Code（SignatureDoesNotMatch），两种形态都要认
        $this->assertMatchesRegularExpression(
            '/SignatureDoesNotMatch|signature we calculated|AccessDenied|Access Denied|InvalidAccessKeyId|Forbidden/i',
            $negative['stdout'] . $negative['stderr'],
            '[S7] 拒绝原因必须是签名/权限错误：' . trim($negative['stderr'] . $negative['stdout'])
        );
    }

    // ------------------------------------------------------------- 流程助手

    /**
     * 走一遍 preprocess + 3 块（$ext 决定扩展名：gif / svg），返回中间量。
     * 与 FlowAssertions::uploadGif() 的区别只在于扩展名可变（内联黑名单用例需要 svg）。
     */
    private function uploadBytes(string $ext, string $bytes): array
    {
        $hash = md5($bytes);

        $pre = $this->parseJsonResponse($this->send('POST', $this->path('preprocess'), [
            'resource_name' => 'probe.' . $ext,
            'resource_size' => strlen($bytes),
            'group'         => $this->groupName(),
            'resource_hash' => $hash,
            'locale'        => 'en',
        ]));

        $this->assertSame(0, $pre['error'], 'preprocess 必须成功：' . json_encode($pre, JSON_UNESCAPED_UNICODE));

        $last = [];

        foreach ( $this->chunks($bytes) as $index => $chunk ) {
            $last = $this->parseJsonResponse($this->send('POST', $this->path('uploading'), [
                'chunk_total'            => self::CHUNK_TOTAL,
                'chunk_index'            => $index + 1,
                'resource_temp_basename' => $pre['resourceTempBaseName'],
                'resource_ext'           => $ext,
                'group_subdir'           => $pre['groupSubDir'],
                'group'                  => $this->groupName(),
                'resource_hash'          => $hash,
                'locale'                 => 'en',
            ], ['resource_chunk' => ['name' => 'chunk.' . $ext, 'content' => $chunk]]));

            $this->assertSame(0, $last['error'], '第 ' . ($index + 1) . ' 块必须成功：' . json_encode($last, JSON_UNESCAPED_UNICODE));
        }

        return [
            'bytes'     => $bytes,
            'hash'      => $hash,
            'ext'       => $ext,
            'subdir'    => $pre['groupSubDir'],
            'temp'      => $pre['resourceTempBaseName'],
            'savedPath' => (string)($last['savedPath'] ?? ''),
            'last'      => $last,
        ];
    }

    /** 桶内对象键（prefix 配空；与 S3Storage 的键构造同构：group_dir/subdir/<md5>.<ext>） */
    private function objectKey(array $flow): string
    {
        return $this->groupDir() . '/' . $flow['subdir'] . '/' . $flow['hash'] . '.' . $flow['ext'];
    }

    /** .part 路径（FlowAssertions::partPath 把扩展名写死成 gif，svg 用例需要参数化） */
    private function partPathFor(string $ext, string $subdir, string $temp): string
    {
        return $this->uploadRoot() . '/' . $this->groupDir() . '/' . $subdir . '/' . $temp . '.' . $ext . '.part';
    }

    /**
     * 合法 GIF89a + 注释扩展块填充（结构不变、mime 仍是 image/gif，但体积超过 multipart_threshold）。
     * 注释块插在 trailer(0x3B) 之前：0x21 0xFE <块长> <数据> 0x00。
     */
    private function paddedGifBytes(): string
    {
        $gif = $this->gifBytes();
        $padding = "\x21\xfe" . chr(150) . str_repeat('pad', 50) . "\x00";

        return substr($gif, 0, -1) . $padding . "\x3b";
    }
}
