<?php

namespace AetherUpload\Tests;

use AetherUpload\ResourceController;
use AetherUpload\Tests\Support\TestState;
use PHPUnit\Framework\TestCase;
use Webman\Http\Request;

/**
 * X-Accel-Redirect（nginx 内部重定向）开关回归测试。
 *
 * 默认关闭时必须与开启前完全一致；开启后 header 只允许由服务端已校验的数据拼出，
 * 且必须是「相对 root_dir」的内部路径——nginx 的 alias 已经指向上传根目录，
 * 头里再带上 root_dir 会在 alias 下多套一层目录，nginx 找不到文件。
 * 断言写成完整字符串：这个 bug 的坏值是"看着挺对"的路径，宽松断言抓不住。
 */
class XAccelRedirectTest extends TestCase
{
    private const PREFIX    = 'plugin.erikwang2013.aetherupload-webman.app';
    private const ROOT_DIR  = 'storage/app/aetherupload';
    private const GROUP_DIR = 'file';
    private const SUB_DIR   = '202609';

    /** 与 config/app.php 里 nginx location 一致，改了它就是改 nginx 契约 */
    private const ACCEL_PREFIX = '/internal-aetherupload/';

    /** 无 switch 时所有文件响应都只有这一个 header */
    private const NOSNIFF = ['X-Content-Type-Options' => 'nosniff'];

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

    private function setConfig(string $key, $value): void
    {
        TestState::set(self::PREFIX . '.' . $key, $value);
        TestState::resetConfigMapper();
    }

    private function resourceDir(string $rootDir = self::ROOT_DIR): string
    {
        return TestState::dir($rootDir, self::GROUP_DIR, self::SUB_DIR);
    }

    private function createResource(string $name, string $content = 'GIF89a', string $rootDir = self::ROOT_DIR): string
    {
        $dir = $this->resourceDir($rootDir);
        mkdir($dir, 0755, true);
        $path = $dir . '/' . $name;
        file_put_contents($path, $content);

        return $path;
    }

    private function savedPath(string $name): string
    {
        return self::GROUP_DIR . '_' . self::SUB_DIR . '_' . $name;
    }

    private function expectedAccelPath(string $name): string
    {
        return self::ACCEL_PREFIX . self::GROUP_DIR . '/' . self::SUB_DIR . '/' . $name;
    }

    /** 所有响应头里都不允许出现换行，否则就是 header 注入 */
    private function assertNoCrlfInHeaders($response): void
    {
        foreach ( $response->headers as $name => $value ) {
            $this->assertStringNotContainsString("\r", (string)$value, $name . ' 头含 CR');
            $this->assertStringNotContainsString("\n", (string)$value, $name . ' 头含 LF');
        }
    }

    // ---------- 开关关闭（默认） ----------

    /**
     * 守护 bug 7：开关默认必须是关的（升级上来的站点不会因为多出这个选项而改变行为），
     * 且内部前缀必须与 README 里给出的 nginx location 对齐——改了常量不改 nginx 配置，线上就是 404。
     */
    public function testShippedDefaultIsOffAndPrefixMatchesDocumentedNginxLocation(): void
    {
        $config = require dirname(__DIR__) . '/config/app.php';

        $this->assertArrayHasKey('x_accel_redirect', $config);
        $this->assertFalse($config['x_accel_redirect'], 'x_accel_redirect 必须默认关闭');
        $this->assertSame(self::ACCEL_PREFIX, ResourceController::ACCEL_PREFIX);
        $this->assertStringContainsString(
            'location ' . ResourceController::ACCEL_PREFIX,
            (string)file_get_contents(dirname(__DIR__) . '/README.md'),
            'README 的 nginx 示例必须与 ACCEL_PREFIX 一致'
        );
    }

    /**
     * 守护 bug 7：x_accel_redirect 默认关闭，展示行为必须与引入开关前逐字节一致。
     */
    public function testDisplayWithoutXAccelRedirectIsUnchanged(): void
    {
        $realPath = $this->createResource('abc.gif');

        $resp = (new ResourceController())->display(new Request(), $this->savedPath('abc.gif'));

        $this->assertSame(200, $resp->getStatusCode());
        $this->assertSame('file', $resp->type);
        $this->assertSame($realPath, $resp->filePath);
        $this->assertSame(self::NOSNIFF, $resp->headers);
    }

    /**
     * 守护 bug 7：默认关闭时下载行为同样不变（含客户端重命名）。
     */
    public function testDownloadWithoutXAccelRedirectIsUnchanged(): void
    {
        $realPath = $this->createResource('abc.gif');

        $resp = (new ResourceController())->download(new Request(), $this->savedPath('abc.gif'), 'newname');

        $this->assertSame(200, $resp->getStatusCode());
        $this->assertSame('download', $resp->type);
        $this->assertSame($realPath, $resp->filePath);
        $this->assertSame('newname.gif', $resp->fileName);
        $this->assertSame(self::NOSNIFF, $resp->headers);
    }

    // ---------- 开关开启 ----------

    /**
     * 守护 bug 7：开启后展示返回 X-Accel-Redirect，值必须是相对 root_dir 的内部路径
     * （ACCEL_PREFIX + group_dir + 子目录 + 文件名），不含 root_dir、不含客户端原始输入。
     */
    public function testDisplayWithXAccelRedirectReturnsRootDirRelativePath(): void
    {
        $this->setConfig('x_accel_redirect', true);
        $this->createResource('abc.gif');

        $resp = (new ResourceController())->display(new Request(), $this->savedPath('abc.gif'));
        $value = $resp->getHeaderLine('X-Accel-Redirect');

        $this->assertSame(200, $resp->getStatusCode());
        $this->assertSame($this->expectedAccelPath('abc.gif'), $value);
        $this->assertStringNotContainsString(self::ROOT_DIR, $value, 'root_dir 由 nginx alias 提供，不得重复出现在头里');
        // 普通图片不强制下载，不应带 Content-Disposition
        $this->assertSame(
            ['X-Accel-Redirect' => $value, 'X-Content-Type-Options' => 'nosniff'],
            $resp->headers
        );
    }

    /**
     * 守护 bug 7：root_dir 改了，头仍必须相对 root_dir（nginx alias 是唯一的 root_dir 出口）。
     * 这条正是"头里多套了一层 root_dir"那个 bug 的回归用例。
     */
    public function testXAccelRedirectPathStaysRootDirRelativeWhenRootDirChanges(): void
    {
        $this->setConfig('root_dir', 'custom/uploads');
        $this->setConfig('x_accel_redirect', true);
        $this->createResource('abc.gif', 'GIF89a', 'custom/uploads');

        $resp = (new ResourceController())->display(new Request(), $this->savedPath('abc.gif'));
        $value = $resp->getHeaderLine('X-Accel-Redirect');

        $this->assertSame($this->expectedAccelPath('abc.gif'), $value);
        $this->assertStringNotContainsString('custom/uploads', $value);
    }

    /**
     * 守护 bug 7：开启后仍要对 svg/html/js 这类可内联执行的后缀强制下载（Content-Disposition 得由程序补上）。
     */
    public function testDisplayWithXAccelRedirectStillForcesDownloadForSvg(): void
    {
        $this->setConfig('x_accel_redirect', true);
        $this->createResource('abc.svg', '<svg xmlns="http://www.w3.org/2000/svg"></svg>');

        $resp = (new ResourceController())->display(new Request(), $this->savedPath('abc.svg'));

        $this->assertSame(200, $resp->getStatusCode());
        $this->assertSame(
            [
                'X-Accel-Redirect'      => $this->expectedAccelPath('abc.svg'),
                'Content-Disposition'   => 'attachment; filename="abc.svg"',
                'X-Content-Type-Options' => 'nosniff',
            ],
            $resp->headers
        );
    }

    /**
     * 守护 bug 7：开启后下载返回 X-Accel-Redirect + 服务端生成的 Content-Disposition。
     */
    public function testDownloadWithXAccelRedirectReturnsPathAndDisposition(): void
    {
        $this->setConfig('x_accel_redirect', true);
        $this->createResource('abc.gif');

        $resp = (new ResourceController())->download(new Request(), $this->savedPath('abc.gif'), 'newname');

        $this->assertSame(200, $resp->getStatusCode());
        $this->assertSame(
            [
                'X-Accel-Redirect'       => $this->expectedAccelPath('abc.gif'),
                'Content-Disposition'    => 'attachment; filename="newname.gif"',
                'X-Content-Type-Options' => 'nosniff',
            ],
            $resp->headers
        );
    }

    /**
     * 守护 bug 7：客户端提交的文件名不得注入 header——X-Accel-Redirect 一个客户端字符都不许有。
     */
    public function testDownloadWithXAccelRedirectDoesNotInjectCrlfFromFilename(): void
    {
        $this->setConfig('x_accel_redirect', true);
        $this->createResource('abc.gif');

        $resp = (new ResourceController())->download(
            new Request(),
            $this->savedPath('abc.gif'),
            "evil\r\nX-Injected: yes"
        );

        $this->assertSame(200, $resp->getStatusCode());
        // 头值只由服务端数据拼出，客户端提交的字符串不得出现
        $this->assertSame($this->expectedAccelPath('abc.gif'), $resp->getHeaderLine('X-Accel-Redirect'));
        $this->assertNoCrlfInHeaders($resp);
        // Content-Disposition 保留客户端命名（下载重命名功能），但换行必须被去掉、扩展名保留
        $this->assertStringContainsString('.gif', $resp->getHeaderLine('Content-Disposition'));
    }

    /**
     * 守护 bug 7：uri 里塞 CRLF 时应在解码阶段就被拒绝（404），不得带出任何 X-Accel-Redirect。
     */
    public function testXAccelRedirectRejectsCrlfInjectedUri(): void
    {
        $this->setConfig('x_accel_redirect', true);
        $this->createResource('abc.gif');

        $resp = (new ResourceController())->display(new Request(), $this->savedPath('abc.gif') . "\r\nX-Injected: yes");

        $this->assertSame(404, $resp->getStatusCode());
        $this->assertArrayNotHasKey('X-Accel-Redirect', $resp->headers);
        $this->assertNoCrlfInHeaders($resp);
    }

    /**
     * 守护 bug 7：开启后仍要做存在性校验与分组校验（不能因为交给 nginx 就跳过 404 判断）。
     */
    public function testXAccelRedirectStillReturns404ForMissingFileAndUnknownGroup(): void
    {
        $this->setConfig('x_accel_redirect', true);
        $this->createResource('abc.gif');

        $missing = (new ResourceController())->display(new Request(), $this->savedPath('nope.gif'));
        $this->assertSame(404, $missing->getStatusCode());
        $this->assertArrayNotHasKey('X-Accel-Redirect', $missing->headers);

        $unknownGroup = (new ResourceController())->download(new Request(), 'nope_' . self::SUB_DIR . '_abc.gif');
        $this->assertSame(404, $unknownGroup->getStatusCode());
        $this->assertArrayNotHasKey('X-Accel-Redirect', $unknownGroup->headers);
    }
}
