<?php

namespace AetherUpload\Tests;

use AetherUpload\ConfigMapper;
use AetherUpload\PartialResource;
use AetherUpload\Resource;
use AetherUpload\ResourceController;
use AetherUpload\Storage\Storage;
use AetherUpload\Tests\Support\TestState;
use AetherUpload\Tests\Support\UploadFixtures;
use PHPUnit\Framework\TestCase;

/**
 * 内核三处接线：Resource::exists/delete、PartialResource::rename、ResourceController::display/download。
 * 用 Storage::inject() 塞入 FakeStorageDriver，逐参数断言内核发出的调用；
 * local 分支必须一字不改地走下去（响应类型/路径与既有用例同构）。
 */
class StorageWiringTest extends TestCase
{
    use UploadFixtures;

    private const PREFIX = 'plugin.erikwang2013.aetherupload-webman.app';
    private const SUB_DIR = '202609';

    private FakeStorageDriver $driver;

    protected function setUp(): void
    {
        TestState::reset();
        TestState::normalizeConfig();
        TestState::resetConfigMapper();
        Storage::reset();

        ConfigMapper::applyGroupConfig('file');
        $this->createDirs();

        $this->driver = new FakeStorageDriver();
        Storage::inject($this->driver);
    }

    protected function tearDown(): void
    {
        // 静态注入位必须还原，否则会串到其它测试类（既有 Controller/Resource 用例走真实 driver）
        Storage::reset();
        remove_dir(TestState::$basePath);
    }

    private function savedPath(string $name): string
    {
        return 'file_' . self::SUB_DIR . '_' . $name;
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

    public function testResourceExistsAndDeleteDelegateToDriver(): void
    {
        $resource = new Resource('file', 'file', self::SUB_DIR, 'abc.gif');

        $this->assertTrue($resource->exists());
        $this->assertSame(['exists', ['file', self::SUB_DIR, 'abc.gif']], $this->driver->calls[0]);

        $this->assertTrue($resource->delete());
        $this->assertSame(['delete', ['file', self::SUB_DIR, 'abc.gif']], $this->driver->calls[1]);
    }

    public function testResourceExistsReflectsDriverAnswer(): void
    {
        $this->driver->existsResult = false;

        $this->assertFalse((new Resource('file', 'file', self::SUB_DIR, 'abc.gif'))->exists());
    }

    public function testResourceDeletePropagatesDriverFailure(): void
    {
        $this->driver->deleteThrows = true;

        $error = $this->capture(function () {
            (new Resource('file', 'file', self::SUB_DIR, 'abc.gif'))->delete();
        });

        $this->assertNotNull($error);
        $this->assertSame('delete_resource_fail', $error->getMessage());
    }

    public function testPartialResourceRenamePublishesThroughDriver(): void
    {
        $partial = new PartialResource($this->fxTempBase, 'gif', self::SUB_DIR);
        $partial->create();
        $this->assertFileExists($partial->realPath, '前置条件：.part 已建好');

        $partial->rename('x.gif');

        $this->assertSame(
            ['publish', [$partial->realPath, 'file', self::SUB_DIR, 'x.gif']],
            $this->driver->calls[0],
            '门面必须把 realPath/groupDir/groupSubDir/completeName 原样交给 driver'
        );
    }

    public function testDisplayRedirectsToPresignedUrlWithNosniff(): void
    {
        $this->driver->urlResult = 'http://s3/x';

        $response = (new ResourceController())->display($this->savedPath('abc.gif'));

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('', $response->getBody());
        $this->assertSame(
            ['Location' => 'http://s3/x', 'X-Content-Type-Options' => 'nosniff'],
            $response->headers
        );
        $this->assertSame(
            ['file', self::SUB_DIR, 'abc.gif', []],
            $this->driver->lastArgs('url'),
            'gif 不在内联黑名单：不得带 response-content-disposition'
        );
        $this->assertSame(1, $this->driver->callCount('url'));
    }

    public function testDisplayForSvgRequestsAttachmentDispositionOnPresignedUrl(): void
    {
        $this->driver->urlResult = 'http://s3/svg';

        $response = (new ResourceController())->display($this->savedPath('abc.svg'));

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame(
            ['file', self::SUB_DIR, 'abc.svg', [
                'response-content-disposition' => 'attachment; filename="abc.svg"; filename*=UTF-8\'\'abc.svg',
            ]],
            $this->driver->lastArgs('url'),
            '内联黑名单命中：预签名必须带 attachment 覆写（ASCII 回退 + RFC 5987 双写法）'
        );
    }

    public function testDisplayFallsBackToLocalFileWhenDriverHasNoUrl(): void
    {
        $this->driver->urlResult = null;

        $response = (new ResourceController())->display($this->savedPath('abc.gif'));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('file', $response->type, 'url() 为 null 时必须走既有本地分支');
        $this->assertSame(
            TestState::$basePath . '/storage/app/aetherupload/file/' . self::SUB_DIR . '/abc.gif',
            $response->filePath
        );
        $this->assertSame(['X-Content-Type-Options' => 'nosniff'], $response->headers);
    }

    public function testDownloadRedirectsWithRenamedAttachmentDisposition(): void
    {
        $this->driver->urlResult = 'http://s3/dl';

        $response = (new ResourceController())->download($this->savedPath('abc.gif'), 'new');

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame(
            ['Location' => 'http://s3/dl', 'X-Content-Type-Options' => 'nosniff'],
            $response->headers
        );
        $this->assertSame(
            ['file', self::SUB_DIR, 'abc.gif', [
                'response-content-disposition' => 'attachment; filename="new.gif"; filename*=UTF-8\'\'new.gif',
            ]],
            $this->driver->lastArgs('url'),
            '下载改名后的 .gif 必须体现在 disposition 的两种写法里'
        );
    }

    public function testPresignedUrlWinsOverXAccelRedirect(): void
    {
        TestState::set(self::PREFIX . '.x_accel_redirect', true);
        TestState::resetConfigMapper();
        $this->driver->urlResult = 'http://s3/x';

        $response = (new ResourceController())->display($this->savedPath('abc.gif'));

        $this->assertSame(302, $response->getStatusCode(), 'driver 给出 url 时预签名优先于 X-Accel');
        $this->assertArrayNotHasKey('X-Accel-Redirect', $response->headers);
        $this->assertSame(
            ['Location' => 'http://s3/x', 'X-Content-Type-Options' => 'nosniff'],
            $response->headers
        );
    }

    public function testMissingResourceReturns404WithoutConsultingDriverUrl(): void
    {
        $this->driver->existsResult = false;

        $display = (new ResourceController())->display($this->savedPath('nope.gif'));
        $this->assertSame(404, $display->getStatusCode());
        $this->assertSame('display fail', $display->getBody());

        $download = (new ResourceController())->download($this->savedPath('nope.gif'), 'x');
        $this->assertSame(404, $download->getStatusCode());
        $this->assertSame('download fail', $download->getBody());

        $this->assertSame(2, $this->driver->callCount('exists'), '存在性校验必须走 driver');
        $this->assertSame(0, $this->driver->callCount('url'), '存在性校验失败时不得计算预签名 URL');
    }
}
