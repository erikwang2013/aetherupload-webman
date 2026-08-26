<?php

namespace AetherUpload\Tests;

use AetherUpload\ConfigMapper;
use AetherUpload\PartialResource;
use AetherUpload\Tests\Support\TestState;
use PHPUnit\Framework\TestCase;

class PartialResourceTest extends TestCase
{
    private const GROUP_SUB_DIR = '202608';

    private string $base = '';
    private string $rootDir = 'storage/app/aetherupload';
    private string $groupDirPath = '';
    private string $groupSubDirPath = '';
    private string $headerDirPath = '';

    protected function setUp(): void
    {
        TestState::reset();
        // ConfigMapper 单例属性直接注入默认组配置（config() 无法读取含点号的 vendor 前缀键）
        ConfigMapper::set('group', 'file');
        ConfigMapper::set('group_dir', 'file');
        ConfigMapper::set('root_dir', 'storage/app/aetherupload');
        ConfigMapper::set('resource_maxsize', 104857600);
        ConfigMapper::set('resource_extensions', ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg', 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'zip', 'rar', '7z', 'mp4', 'mp3', 'wav']);
        ConfigMapper::set('forbidden_extensions', ['php', 'part', 'html', 'shtml', 'htm', 'shtm', 'xhtml', 'xml', 'js', 'jsp', 'asp', 'java', 'py', 'sh', 'bat', 'exe', 'dll', 'cgi', 'htaccess', 'reg', 'aspx', 'vbs']);
        ConfigMapper::set('extra_mime_types', []);
        $this->base = TestState::$basePath;
        $this->groupDirPath = $this->base . DIRECTORY_SEPARATOR . $this->rootDir . DIRECTORY_SEPARATOR . 'file';
        $this->groupSubDirPath = $this->groupDirPath . DIRECTORY_SEPARATOR . self::GROUP_SUB_DIR;
        $this->headerDirPath = $this->base . DIRECTORY_SEPARATOR . $this->rootDir . DIRECTORY_SEPARATOR . '_header';
        mkdir($this->groupDirPath, 0777, true);
        mkdir($this->headerDirPath, 0777, true);
    }

    private function makePartial(string $tempBaseName = 'tmpname', string $extension = 'part'): PartialResource
    {
        return new PartialResource($tempBaseName, $extension, self::GROUP_SUB_DIR);
    }

    private function writeRealFile(string $path, string $content): void
    {
        if ( ! is_dir(dirname($path)) ) {
            mkdir(dirname($path), 0777, true);
        }
        file_put_contents($path, $content);
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

    public function testConstructorBuildsTempNameHeaderAndPaths(): void
    {
        $partial = $this->makePartial('tmpname', 'part');

        $this->assertSame('tmpname.part', $partial->tempName);
        $this->assertSame('file', $partial->group);
        $this->assertSame('file', $partial->groupDir);
        $this->assertSame(self::GROUP_SUB_DIR, $partial->groupSubDir);
        $this->assertSame('storage/app/aetherupload/file/202608/tmpname.part.part', $partial->path);
        $this->assertSame($this->base . '/storage/app/aetherupload/file/202608/tmpname.part.part', $partial->realPath);
        $this->assertSame('tmpname', $partial->header->name);
        $this->assertSame('storage/app/aetherupload/_header/tmpname', $partial->header->path);
        $this->assertSame($this->base . '/storage/app/aetherupload/_header/tmpname', $partial->header->realPath);
    }

    public function testCreateThrowsWhenGroupDirParentMissing(): void
    {
        rmdir($this->groupDirPath);

        $e = $this->withSuppressedWarnings(fn() => $this->makePartial()->create());

        $this->assertSame('create_subfolder_fail', $e->getMessage());
    }

    public function testCreateBuildsSubDirAndEmptyFile(): void
    {
        $partial = $this->makePartial();
        $partial->create();

        $this->assertDirectoryExists($this->groupSubDirPath);
        $this->assertFileExists($partial->realPath);
        $this->assertSame('', file_get_contents($partial->realPath));
    }

    public function testAppendWritesChunkContentToRealFile(): void
    {
        $partial = $this->makePartial();
        $partial->create();

        $chunk1 = $this->base . '/chunk1.bin';
        $chunk2 = $this->base . '/chunk2.bin';
        file_put_contents($chunk1, 'first-part');
        file_put_contents($chunk2, '-second-part');

        $partial->append($chunk1);
        $partial->append($chunk2);

        $this->assertSame('first-part-second-part', file_get_contents($partial->realPath));
    }

    public function testAppendMissingChunkThrowsUploadError(): void
    {
        $partial = $this->makePartial();
        $partial->create();

        $e = $this->withSuppressedWarnings(fn() => $partial->append($this->base . '/no-such-chunk.bin'));

        $this->assertSame('upload_error', $e->getMessage());
    }

    public function testDeleteRemovesFile(): void
    {
        $partial = $this->makePartial();
        $partial->create();

        $this->assertTrue($partial->delete());
        $this->assertFileDoesNotExist($partial->realPath);
    }

    public function testDeleteMissingFileThrowsDeleteResourceFail(): void
    {
        $e = $this->withSuppressedWarnings(fn() => $this->makePartial()->delete());

        $this->assertSame('delete_resource_fail', $e->getMessage());
    }

    public function testRenameDeletesSelfWhenTargetAlreadyExists(): void
    {
        $partial = $this->makePartial();
        $partial->create();
        $completePath = $this->groupSubDirPath . '/existing.jpg';
        file_put_contents($completePath, 'existing-content');

        $partial->rename('existing.jpg');

        $this->assertFileDoesNotExist($partial->realPath);
        $this->assertFileExists($completePath);
        $this->assertSame('existing-content', file_get_contents($completePath));
    }

    public function testRenameMovesFileToCompletePath(): void
    {
        $partial = $this->makePartial();
        $partial->create();

        $partial->rename('final.jpg');

        $this->assertFileDoesNotExist($partial->realPath);
        $this->assertFileExists($this->groupSubDirPath . '/final.jpg');
    }

    public function testRenameFailureThrowsRenameResourceFail(): void
    {
        $partial = $this->makePartial();
        $e = $this->withSuppressedWarnings(fn() => $partial->rename('final.jpg'));

        $this->assertSame('rename_resource_fail', $e->getMessage());
    }

    public function testFilterBySizeZeroSizeThrows(): void
    {
        $e = $this->withSuppressedWarnings(fn() => $this->makePartial()->filterBySize(0));

        $this->assertSame('invalid_resource_size', $e->getMessage());
    }

    public function testFilterBySizeOverMaxSizeThrows(): void
    {
        $e = $this->withSuppressedWarnings(fn() => $this->makePartial()->filterBySize(104857601));

        $this->assertSame('invalid_resource_size', $e->getMessage());
    }

    public function testFilterBySizeWithinLimitPasses(): void
    {
        $partial = $this->makePartial();

        $this->assertNull($partial->filterBySize(1024));
        $this->assertNull($partial->filterBySize(104857600));
    }

    public function testFilterBySizeUnlimitedWhenMaxSizeZero(): void
    {
        ConfigMapper::set('resource_maxsize', 0);

        $partial = $this->makePartial();

        $this->assertNull($partial->filterBySize(104857601));
    }

    public function testFilterByExtensionEmptyThrows(): void
    {
        $e = $this->withSuppressedWarnings(fn() => $this->makePartial()->filterByExtension(''));

        $this->assertSame('invalid_resource_type', $e->getMessage());
    }

    public function testFilterByExtensionOutsideWhitelistThrows(): void
    {
        $e = $this->withSuppressedWarnings(fn() => $this->makePartial()->filterByExtension('psd'));

        $this->assertSame('invalid_resource_type', $e->getMessage());
    }

    public function testFilterByExtensionForbiddenThrows(): void
    {
        $e = $this->withSuppressedWarnings(fn() => $this->makePartial()->filterByExtension('php'));

        $this->assertSame('invalid_resource_type', $e->getMessage());
    }

    public function testFilterByExtensionWhitelistedPasses(): void
    {
        $this->assertNull($this->makePartial()->filterByExtension('jpg'));
        $this->assertNull($this->makePartial()->filterByExtension('png'));
    }

    public function testFilterByExtensionEmptyWhitelistOnlyBlacklistApplies(): void
    {
        ConfigMapper::set('resource_extensions', []);

        $partial = $this->makePartial();

        $this->assertNull($partial->filterByExtension('jpg'));
        $e = $this->withSuppressedWarnings(fn() => $partial->filterByExtension('php'));
        $this->assertSame('invalid_resource_type', $e->getMessage());
    }

    public function testCheckSizePassesForNonEmptyFile(): void
    {
        $partial = $this->makePartial();
        $this->writeRealFile($partial->realPath, 'hello world');

        $this->assertNull($partial->checkSize());
    }

    public function testCheckSizeThrowsForEmptyFile(): void
    {
        $partial = $this->makePartial();
        $this->writeRealFile($partial->realPath, '');

        $e = $this->withSuppressedWarnings(fn() => $partial->checkSize());

        $this->assertSame('invalid_resource_size', $e->getMessage());
    }

    public function testCheckMimeTypePassesForRecognizedGif(): void
    {
        $partial = $this->makePartial();
        $this->writeRealFile($partial->realPath, "GIF89a" . str_repeat("\x00", 50));

        $this->assertNull($partial->checkMimeType());
    }

    public function testCheckMimeTypeThrowsMissingMimetypeForEmptyFile(): void
    {
        $partial = $this->makePartial();
        $this->writeRealFile($partial->realPath, '');

        $e = $this->withSuppressedWarnings(fn() => $partial->checkMimeType());

        $this->assertSame('missing_mimetype', $e->getMessage());
    }

    public function testCheckMimeTypeThrowsInvalidTypeForUnrecognizedBinary(): void
    {
        $partial = $this->makePartial();
        // NUL bytes force binary detection ("application/octet-stream" -> extension "bin")
        $this->writeRealFile($partial->realPath, "\x00\x01\x02\x03" . random_bytes(64));

        $e = $this->withSuppressedWarnings(fn() => $partial->checkMimeType());

        $this->assertSame('invalid_resource_type', $e->getMessage());
    }

    public function testCalculateHashMatchesMd5File(): void
    {
        $partial = $this->makePartial();
        $this->writeRealFile($partial->realPath, 'hashable content');

        $this->assertSame(md5_file($partial->realPath), $partial->calculateHash());
    }

    public function testChunkIndexMagicWritesReadsAndDeletesHeaderFile(): void
    {
        $partial = $this->makePartial();

        // 未写入前读取 -> header 文件缺失 -> read_header_fail
        $e = $this->withSuppressedWarnings(fn() => $partial->chunkIndex);
        $this->assertSame('read_header_fail', $e->getMessage());

        $partial->chunkIndex = 7;

        $this->assertSame('7', $partial->chunkIndex);
        $this->assertFileExists($partial->header->realPath);

        unset($partial->chunkIndex);

        $this->assertFileDoesNotExist($partial->header->realPath);
        $e = $this->withSuppressedWarnings(fn() => $partial->chunkIndex);
        $this->assertSame('read_header_fail', $e->getMessage());
    }

    public function testMagicGetUnknownPropertyReturnsNull(): void
    {
        $this->assertNull($this->makePartial()->unknownProperty);
    }

    public function testGetCompletePathBuildsAbsolutePath(): void
    {
        $this->assertSame(
            $this->base . '/storage/app/aetherupload/file/202608/complete.jpg',
            $this->makePartial()->getCompletePath('complete.jpg')
        );
    }

    public function testGetGroupSubDirPathBuildsAbsolutePath(): void
    {
        $this->assertSame(
            $this->base . '/storage/app/aetherupload/file/202608',
            $this->makePartial()->getGroupSubDirPath()
        );
    }
}
