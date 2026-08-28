<?php

namespace AetherUpload\Tests;

use AetherUpload\ResourceController;
use AetherUpload\Tests\Support\TestState;
use PHPUnit\Framework\TestCase;
use Webman\Http\Request;

class ResourceControllerTest extends TestCase
{
    private const ROOT_DIR = 'storage/app/aetherupload';
    private const GROUP_DIR = 'file';
    private const SUB_DIR  = '202608';

    protected function setUp(): void
    {
        TestState::reset();
        TestState::normalizeConfig();
        TestState::resetConfigMapper();
    }

    protected function tearDown(): void
    {
        remove_dir(TestState::$basePath);
    }

    private function resourceDir(): string
    {
        return TestState::dir(self::ROOT_DIR, self::GROUP_DIR, self::SUB_DIR);
    }

    private function createResource(string $name, string $content = 'GIF89a'): string
    {
        $dir = $this->resourceDir();
        mkdir($dir, 0755, true);
        $path = $dir . '/' . $name;
        file_put_contents($path, $content);

        return $path;
    }

    public function testDisplayReturnsFileResponseWithNosniff()
    {
        $realPath = $this->createResource('abc.gif');

        $resp = (new ResourceController())->display(new Request(), 'file_202608_abc.gif');

        $this->assertSame(200, $resp->getStatusCode());
        $this->assertSame('file', $resp->type);
        $this->assertSame($realPath, $resp->filePath);
        $this->assertSame('nosniff', $resp->getHeaderLine('X-Content-Type-Options'));
    }

    public function testDisplayForcesDownloadForSvg()
    {
        $realPath = $this->createResource('abc.svg', '<svg xmlns="http://www.w3.org/2000/svg"></svg>');

        $resp = (new ResourceController())->display(new Request(), 'file_202608_abc.svg');

        $this->assertSame(200, $resp->getStatusCode());
        $this->assertSame('download', $resp->type);
        $this->assertSame($realPath, $resp->filePath);
        $this->assertSame('abc.svg', $resp->fileName);
        $this->assertSame('nosniff', $resp->getHeaderLine('X-Content-Type-Options'));
    }

    public function testDisplayForcesDownloadForJs()
    {
        $this->createResource('x.js', 'alert(1);');

        $resp = (new ResourceController())->display(new Request(), 'file_202608_x.js');

        $this->assertSame('download', $resp->type);
        $this->assertSame('x.js', $resp->fileName);
    }

    public function testDisplayForcesDownloadForHtml()
    {
        $this->createResource('page.html', '<html></html>');

        $resp = (new ResourceController())->display(new Request(), 'file_202608_page.html');

        $this->assertSame('download', $resp->type);
    }

    public function testDisplayInvalidUriReturns404()
    {
        $resp = (new ResourceController())->display(new Request(), 'garbage');

        $this->assertSame(404, $resp->getStatusCode());
        $this->assertSame('display fail', $resp->getBody());
    }

    public function testDisplayMissingFileReturns404()
    {
        $this->createResource('abc.gif');

        $resp = (new ResourceController())->display(new Request(), 'file_202608_nope.gif');

        $this->assertSame(404, $resp->getStatusCode());
        $this->assertSame('display fail', $resp->getBody());
    }

    public function testDisplayUnknownGroupReturns404()
    {
        $this->createResource('abc.gif');

        $resp = (new ResourceController())->display(new Request(), 'nope_202608_abc.gif');

        $this->assertSame(404, $resp->getStatusCode());
        $this->assertSame('display fail', $resp->getBody());
    }

    public function testDownloadReturnsDownloadResponseWithNewName()
    {
        $realPath = $this->createResource('abc.gif');

        $resp = (new ResourceController())->download(new Request(), 'file_202608_abc.gif', 'newname');

        $this->assertSame(200, $resp->getStatusCode());
        $this->assertSame('download', $resp->type);
        $this->assertSame($realPath, $resp->filePath);
        $this->assertSame('newname.gif', $resp->fileName);
        $this->assertSame('nosniff', $resp->getHeaderLine('X-Content-Type-Options'));
    }

    public function testDownloadWithoutNewNameKeepsOriginalExtension()
    {
        $this->createResource('abc.gif');

        $resp = (new ResourceController())->download(new Request(), 'file_202608_abc.gif');

        $this->assertSame('download', $resp->type);
        $this->assertStringEndsWith('.gif', $resp->fileName);
    }

    public function testDownloadSanitizesCrlfInNewName()
    {
        $this->createResource('abc.gif');

        $resp = (new ResourceController())->download(new Request(), 'file_202608_abc.gif', "evil\r\nX-Injected: yes");

        $this->assertSame('download', $resp->type);
        $this->assertStringNotContainsString("\r", $resp->fileName);
        $this->assertStringNotContainsString("\n", $resp->fileName);
    }

    public function testDownloadMissingFileReturns404()
    {
        $this->createResource('abc.gif');

        $resp = (new ResourceController())->download(new Request(), 'file_202608_nope.gif', 'newname');

        $this->assertSame(404, $resp->getStatusCode());
        $this->assertSame('download fail', $resp->getBody());
    }
}
