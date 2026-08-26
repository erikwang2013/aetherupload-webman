<?php

namespace AetherUpload\Tests;

use AetherUpload\ConfigMapper;
use AetherUpload\Resource;
use AetherUpload\Tests\Support\TestState;
use PHPUnit\Framework\TestCase;

class ResourceTest extends TestCase
{
    private string $rootDir = 'storage/app/aetherupload';
    private string $base = '';

    protected function setUp(): void
    {
        TestState::reset();
        ConfigMapper::set('root_dir', 'storage/app/aetherupload');
        $this->base = TestState::$basePath;
    }

    private function makeResource(string $groupSubDir = '202608', string $name = 'demo.jpg', string $groupDir = 'file'): Resource
    {
        return new Resource('file', $groupDir, $groupSubDir, $name);
    }

    public function testConstructorBuildsPathAndRealPath(): void
    {
        $resource = $this->makeResource('202608', 'demo.jpg');

        $this->assertSame('demo.jpg', $resource->name);
        $this->assertSame('file', $resource->group);
        $this->assertSame('file', $resource->groupDir);
        $this->assertSame('202608', $resource->groupSubDir);
        $this->assertSame('storage/app/aetherupload/file/202608/demo.jpg', $resource->path);
        $this->assertSame($this->base . '/storage/app/aetherupload/file/202608/demo.jpg', $resource->realPath);
    }

    public function testExistsReturnsFalseForMissingFile(): void
    {
        $this->assertFalse($this->makeResource()->exists());
    }

    public function testExistsReturnsTrueAfterFileCreated(): void
    {
        $resource = $this->makeResource();
        $this->createRealFile($resource->realPath);

        $this->assertTrue($resource->exists());
    }

    public function testDeleteRemovesExistingFile(): void
    {
        $resource = $this->makeResource();
        $this->createRealFile($resource->realPath);

        $this->assertTrue($resource->delete());
        $this->assertFileDoesNotExist($resource->realPath);
    }

    public function testDeleteMissingFileThrowsDeleteResourceFail(): void
    {
        $e = $this->withSuppressedWarnings(fn() => $this->makeResource()->delete());

        $this->assertSame('delete_resource_fail', $e->getMessage());
    }

    private function createRealFile(string $path): void
    {
        if ( ! is_dir(dirname($path)) ) {
            mkdir(dirname($path), 0777, true);
        }
        file_put_contents($path, 'content');
    }

    private function withSuppressedWarnings(callable $fn): \Throwable
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
}
