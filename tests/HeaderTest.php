<?php

namespace AetherUpload\Tests;

use AetherUpload\ConfigMapper;
use AetherUpload\Header;
use AetherUpload\Tests\Support\TestState;
use PHPUnit\Framework\TestCase;

class HeaderTest extends TestCase
{
    protected function setUp(): void
    {
        TestState::reset();
        // ConfigMapper caches config() at first instantiation; push defaults into the singleton
        ConfigMapper::set('root_dir', 'storage/app/aetherupload');
    }

    protected function tearDown(): void
    {
        if ( is_dir(TestState::$basePath) ) {
            remove_dir(TestState::$basePath);
        }
    }

    private function makeHeaderDir(): void
    {
        mkdir(TestState::$basePath . '/storage/app/aetherupload/_header', 0777, true);
    }

    public function testConstructorBuildsNamePathAndRealPath(): void
    {
        $header = new Header('temp123');

        $this->assertSame('temp123', $header->name);
        $this->assertSame('storage/app/aetherupload/_header/temp123', $header->path);
        $this->assertSame(
            TestState::$basePath . '/storage/app/aetherupload/_header/temp123',
            $header->realPath
        );
    }

    public function testExistsIsFalseBeforeFileIsCreated(): void
    {
        $this->assertFalse((new Header('temp123'))->exists());
    }

    public function testWriteThenReadRoundTrip(): void
    {
        $this->makeHeaderDir();
        $header = new Header('temp123');

        $header->write('chunk-1-data');

        $this->assertTrue($header->exists());
        $this->assertSame('chunk-1-data', $header->read());
        $this->assertSame('chunk-1-data', file_get_contents($header->realPath));
    }

    public function testWriteOverwritesPreviousContent(): void
    {
        $this->makeHeaderDir();
        $header = new Header('temp123');
        $header->write('old');
        $header->write('new');

        $this->assertSame('new', $header->read());
    }

    public function testReadThrowsWhenFileMissing(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('read_header_fail');
        (new Header('temp123'))->read();
    }

    public function testWriteThrowsWhenTargetIsNotWritable(): void
    {
        // create a directory at the target path so file_put_contents fails
        $this->makeHeaderDir();
        mkdir(TestState::$basePath . '/storage/app/aetherupload/_header/temp123', 0777, true);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('write_header_fail');
        (new Header('temp123'))->write('data');
    }

    public function testDeleteRemovesFile(): void
    {
        $this->makeHeaderDir();
        $header = new Header('temp123');
        $header->write('data');

        $header->delete();

        $this->assertFalse($header->exists());
        $this->assertFileDoesNotExist($header->realPath);
    }

    public function testDeleteThrowsWhenFileMissing(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('delete_header_fail');
        (new Header('temp123'))->delete();
    }

    public function testRealPathFollowsConfiguredRootDir(): void
    {
        ConfigMapper::set('root_dir', 'custom/root');
        $header = new Header('temp123');

        $this->assertSame(
            TestState::$basePath . '/custom/root/_header/temp123',
            $header->realPath
        );
    }
}
