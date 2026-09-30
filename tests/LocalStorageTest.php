<?php

namespace AetherUpload\Tests;

use AetherUpload\ConfigMapper;
use AetherUpload\Storage\LocalStorage;
use AetherUpload\Tests\Support\TestState;
use PHPUnit\Framework\TestCase;

/**
 * 默认驱动 LocalStorage：语义必须与历史版本逐条相同
 * （= 旧 PartialResource::rename + Resource::exists/delete，见 tests/PartialResourceTest.php 与 tests/ResourceTest.php）。
 * 任何一条对不上，就是 local 默认路径的行为回归。
 */
class LocalStorageTest extends TestCase
{
    private const ROOT_DIR = 'storage/app/aetherupload';
    private const SUB_DIR = '202609';

    private string $base = '';

    protected function setUp(): void
    {
        TestState::reset();
        ConfigMapper::set('root_dir', self::ROOT_DIR);
        $this->base = TestState::$basePath;
        mkdir($this->targetDir(), 0777, true);
    }

    protected function tearDown(): void
    {
        remove_dir(TestState::$basePath);
    }

    private function localStorage(): LocalStorage
    {
        return new LocalStorage();
    }

    private function targetDir(): string
    {
        return $this->base . '/' . self::ROOT_DIR . '/file/' . self::SUB_DIR;
    }

    private function targetPath(string $name): string
    {
        return $this->targetDir() . '/' . $name;
    }

    private function writeFile(string $path, string $content): void
    {
        if ( ! is_dir(dirname($path)) ) {
            mkdir(dirname($path), 0777, true);
        }
        file_put_contents($path, $content);
        clearstatcache(true, $path);
    }

    /** unlink()/rename() 失败会带 PHP 警告；本套件关心的是异常与文件状态，警告按既有用例的做法抑制 */
    private function captureWithSuppressedWarnings(callable $fn): \Throwable
    {
        set_error_handler(static function () {
            return true;
        });
        try {
            $fn();
        } catch ( \Throwable $e ) {
            return $e;
        } finally {
            restore_error_handler();
        }

        return new \Exception('__no_exception__');
    }

    public function testPublishRenamesTempFileToCompletePath(): void
    {
        $temp = $this->base . '/upload.part';
        $this->writeFile($temp, 'payload');

        $this->localStorage()->publish($temp, 'file', self::SUB_DIR, 'final.gif');

        $this->assertFileDoesNotExist($temp);
        $this->assertSame('payload', file_get_contents($this->targetPath('final.gif')));
    }

    public function testPublishDeletesTempFileWhenTargetAlreadyExists(): void
    {
        $this->writeFile($this->targetPath('final.gif'), 'existing-content');
        $temp = $this->base . '/upload.part';
        $this->writeFile($temp, 'new-content');

        $this->localStorage()->publish($temp, 'file', self::SUB_DIR, 'final.gif');

        $this->assertFileDoesNotExist($temp, '同 hash 去重：临时文件必须删除');
        $this->assertSame('existing-content', file_get_contents($this->targetPath('final.gif')), '去重命中时目标内容不得被覆盖');
    }

    public function testPublishThrowsDeleteResourceFailWhenTargetExistsAndTempMissing(): void
    {
        $this->writeFile($this->targetPath('final.gif'), 'existing');

        $e = $this->captureWithSuppressedWarnings(function () {
            $this->localStorage()->publish($this->base . '/nope.part', 'file', self::SUB_DIR, 'final.gif');
        });

        $this->assertSame('delete_resource_fail', $e->getMessage(), '与旧 PartialResource::rename 的去重分支行为一致');
        $this->assertSame('existing', file_get_contents($this->targetPath('final.gif')));
    }

    public function testPublishThrowsRenameResourceFailWhenRenameFails(): void
    {
        $e = $this->captureWithSuppressedWarnings(function () {
            $this->localStorage()->publish($this->base . '/nope.part', 'file', self::SUB_DIR, 'final.gif');
        });

        $this->assertSame('rename_resource_fail', $e->getMessage());
    }

    public function testExistsReflectsTargetFile(): void
    {
        $storage = $this->localStorage();

        $this->assertFalse($storage->exists('file', self::SUB_DIR, 'final.gif'));

        $this->writeFile($this->targetPath('final.gif'), 'x');

        $this->assertTrue($storage->exists('file', self::SUB_DIR, 'final.gif'));
    }

    public function testDeleteRemovesTargetAndReportsMissing(): void
    {
        $storage = $this->localStorage();
        $this->writeFile($this->targetPath('final.gif'), 'x');

        $this->assertTrue($storage->delete('file', self::SUB_DIR, 'final.gif'));
        $this->assertFileDoesNotExist($this->targetPath('final.gif'));

        $e = $this->captureWithSuppressedWarnings(function () use ($storage) {
            $storage->delete('file', self::SUB_DIR, 'final.gif');
        });

        $this->assertSame('delete_resource_fail', $e->getMessage());
    }

    public function testUrlIsAlwaysNull(): void
    {
        $this->assertNull($this->localStorage()->url('file', self::SUB_DIR, 'final.gif'));
        $this->assertNull(
            $this->localStorage()->url('file', self::SUB_DIR, 'final.gif', ['response-content-disposition' => 'attachment']),
            'local 驱动恒无直发 URL，responseParams 不得改变结果'
        );
    }
}
