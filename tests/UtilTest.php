<?php

namespace AetherUpload\Tests;

use AetherUpload\ConfigMapper;
use AetherUpload\Resource;
use AetherUpload\Util;
use AetherUpload\Tests\Support\TestState;
use PHPUnit\Framework\TestCase;

class UtilTest extends TestCase
{
    private const PREFIX = 'plugin.erikwang2013.aetherupload-webman.app';

    protected function setUp(): void
    {
        TestState::reset();
        // harness defect: defaultConfig() stores the dotted prefix as one literal key while
        // TestState::get() splits on dots, so config('<prefix>.*') returns null unless seeded
        TestState::set(self::PREFIX . '.groups', [
            'file' => [
                'group_dir' => 'file',
                'resource_maxsize' => 104857600,
                'resource_extensions' => ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg', 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'zip', 'rar', '7z', 'mp4', 'mp3', 'wav'],
                'event_before_upload_complete' => false,
                'event_upload_complete' => false,
            ],
        ]);
        // ConfigMapper caches config() at first instantiation; push defaults into the singleton
        ConfigMapper::set('root_dir', 'storage/app/aetherupload');
        ConfigMapper::set('route_display', '/aetherupload/display');
        ConfigMapper::set('route_download', '/aetherupload/download');
        ConfigMapper::set('resource_subdir_rule', 'month');
    }

    protected function tearDown(): void
    {
        if ( is_dir(TestState::$basePath) ) {
            remove_dir(TestState::$basePath);
        }
    }

    private function makeResourceFile(string $groupSubDir, string $name): string
    {
        $dir = TestState::$basePath . '/storage/app/aetherupload/file/' . $groupSubDir;
        mkdir($dir, 0777, true);
        $file = $dir . '/' . $name;
        file_put_contents($file, 'data');
        return $file;
    }

    public function testGenerateTempNameReturns16LowercaseHexChars(): void
    {
        $name = Util::generateTempName();
        $this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $name);
    }

    public function testGenerateTempNameIsRandomAcrossCalls(): void
    {
        $this->assertNotSame(Util::generateTempName(), Util::generateTempName());
    }

    public function testGetFileNameConcatenatesBaseNameAndExtension(): void
    {
        $this->assertSame('photo.jpg', Util::getFileName('photo', 'jpg'));
        $this->assertSame('noext.', Util::getFileName('noext', ''));
    }

    public function testGenerateSubDirNameYearRule(): void
    {
        ConfigMapper::set('resource_subdir_rule', 'year');
        $this->assertSame(date('Y'), Util::generateSubDirName());
    }

    public function testGenerateSubDirNameMonthRule(): void
    {
        ConfigMapper::set('resource_subdir_rule', 'month');
        $this->assertSame(date('Ym'), Util::generateSubDirName());
    }

    public function testGenerateSubDirNameDateRule(): void
    {
        ConfigMapper::set('resource_subdir_rule', 'date');
        $this->assertSame(date('Ymd'), Util::generateSubDirName());
    }

    public function testGenerateSubDirNameConstRule(): void
    {
        ConfigMapper::set('resource_subdir_rule', 'const');
        $this->assertSame('subdir', Util::generateSubDirName());
    }

    public function testGenerateSubDirNameDefaultRuleFallsBackToMonth(): void
    {
        ConfigMapper::set('resource_subdir_rule', 'unknown-rule');
        $this->assertSame(date('Ym'), Util::generateSubDirName());
    }

    public function testGetDisplayLinkAppendsSavedPathToDisplayRoute(): void
    {
        $this->assertSame(
            '/aetherupload/display/file_201701_abc.jpg',
            Util::getDisplayLink('file_201701_abc.jpg')
        );
    }

    public function testGetDisplayLinkUsesConfiguredRoute(): void
    {
        ConfigMapper::set('route_display', '/custom/display');
        $this->assertSame('/custom/display/x_y_z', Util::getDisplayLink('x_y_z'));
    }

    public function testGetDownloadLinkAppendsSavedPathAndNewName(): void
    {
        $this->assertSame(
            '/aetherupload/download/file_201701_abc.jpg/new.jpg',
            Util::getDownloadLink('file_201701_abc.jpg', 'new.jpg')
        );
    }

    public function testGetDownloadLinkUsesConfiguredRoute(): void
    {
        ConfigMapper::set('route_download', '/custom/download');
        $this->assertSame('/custom/download/a_b/c.jpg', Util::getDownloadLink('a_b', 'c.jpg'));
    }

    public function testDeleteResourceDeletesFileAndRedisRecord(): void
    {
        $this->makeResourceFile('201701', 'abc.jpg');
        TestState::$redisHash['aetherupload_resource']['file_abc'] = 'file_201701_abc.jpg';

        $this->assertTrue(Util::deleteResource('file_201701_abc.jpg'));
        $this->assertFileDoesNotExist(TestState::$basePath . '/storage/app/aetherupload/file/201701/abc.jpg');
        $this->assertArrayNotHasKey('file_abc', TestState::$redisHash['aetherupload_resource']);
    }

    public function testDeleteResourceReturnsFalseForUnknownGroup(): void
    {
        $this->assertFalse(Util::deleteResource('nope_201701_abc.jpg'));
    }

    public function testDeleteResourceThrowsOnUndecodableSavedPath(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('invalid_operation');
        Util::deleteResource('too_short');
    }

    public function testGetResourceReturnsConfiguredResourceInstance(): void
    {
        $resource = Util::getResource('file_201701_abc.jpg');

        $this->assertInstanceOf(Resource::class, $resource);
        $this->assertSame('file', $resource->group);
        $this->assertSame('file', $resource->groupDir);
        $this->assertSame('201701', $resource->groupSubDir);
        $this->assertSame('abc.jpg', $resource->name);
        $this->assertSame(
            'storage/app/aetherupload/file/201701/abc.jpg',
            $resource->path
        );
        $this->assertSame(
            TestState::$basePath . '/storage/app/aetherupload/file/201701/abc.jpg',
            $resource->realPath
        );
    }

    public function testGetResourceReturnsFalseForUnknownGroup(): void
    {
        $this->assertFalse(Util::getResource('nope_201701_abc.jpg'));
    }

    public function testGetResourceThrowsOnUndecodableSavedPath(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('invalid_operation');
        Util::getResource('not-enough');
    }

    public function testDeleteRedisSavedPathDeletesStoredRecord(): void
    {
        TestState::$redisHash['aetherupload_resource']['file_abc'] = 'file_201701_abc.jpg';

        $this->assertTrue(Util::deleteRedisSavedPath('file_201701_abc.jpg'));
        $this->assertArrayNotHasKey('file_abc', TestState::$redisHash['aetherupload_resource']);
    }

    public function testDeleteRedisSavedPathIsIdempotentWhenRecordMissing(): void
    {
        // 秒传记录不存在时的删除按幂等成功处理（见 RedisSavedPath::delete），不得报错、也不得凭空造出记录
        $this->assertTrue(Util::deleteRedisSavedPath('file_201701_abc.jpg'));
        $this->assertSame([], TestState::$redisStrings);
        $this->assertSame([], TestState::$redisHash);
    }

    public function testDeleteRedisSavedPathThrowsOnUndecodableSavedPath(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('invalid_operation');
        Util::deleteRedisSavedPath('bad');
    }
}
