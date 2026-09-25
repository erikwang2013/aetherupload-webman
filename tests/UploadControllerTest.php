<?php

namespace AetherUpload\Tests;

use AetherUpload\RedisSavedPath;
use AetherUpload\Tests\Support\TestState;
use AetherUpload\UploadController;
use PHPUnit\Framework\TestCase;
use Webman\Http\Request;

class UploadControllerTest extends TestCase
{
    private const PREFIX   = 'plugin.erikwang2013.aetherupload-webman.app';
    private const ROOT_DIR = 'storage/app/aetherupload';
    private const GROUP_DIR = 'file';
    private const SUB_DIR  = '202608';
    private const TEMP_BASE = 'tempabc123';

    protected function setUp(): void
    {
        TestState::reset();
        TestState::normalizeConfig();
        TestState::resetConfigMapper();
        mkdir(TestState::$basePath, 0777, true);
    }

    protected function tearDown(): void
    {
        remove_dir(TestState::$basePath);
    }

    private function setInstantCompletion(bool $enabled): void
    {
        TestState::set(TestState::PREFIX . '.instant_completion', $enabled);
        TestState::resetConfigMapper();
    }

    // ---------- helpers ----------

    private function uploadDir(): string
    {
        return TestState::dir(self::ROOT_DIR, self::GROUP_DIR, self::SUB_DIR);
    }

    private function headerDir(): string
    {
        return TestState::$basePath . '/' . self::ROOT_DIR . '/_header';
    }

    private function partPath(): string
    {
        return $this->uploadDir() . '/' . self::TEMP_BASE . '.gif.part';
    }

    private function headerPath(): string
    {
        return $this->headerDir() . '/' . self::TEMP_BASE;
    }

    private function createDirs(): void
    {
        mkdir($this->uploadDir(), 0755, true);
        mkdir($this->headerDir(), 0755, true);
    }

    private function createPartAndHeader(string $chunkIndex = '0'): void
    {
        $this->createDirs();
        file_put_contents($this->partPath(), '');
        file_put_contents($this->headerPath(), $chunkIndex);
    }

    private function chunkObject(string $content, bool $valid = true): object
    {
        $path = tempnam(sys_get_temp_dir(), 'aetherupload-chunk');
        file_put_contents($path, $content);

        return new class($path, $valid) {
            public string $path;
            private bool $valid;

            public function __construct(string $path, bool $valid)
            {
                $this->path  = $path;
                $this->valid = $valid;
            }

            public function isValid(): bool
            {
                return $this->valid;
            }

            public function getRealPath(): string
            {
                return $this->path;
            }

            public function __destruct()
            {
                @unlink($this->path);
            }
        };
    }

    private function preprocessRequest(array $overrides = []): Request
    {
        return new Request(array_merge([
            'resource_name' => 'a.gif',
            'resource_size' => '100',
            'group'         => 'file',
            'resource_hash' => 'h',
            'locale'        => 'zh',
        ], $overrides));
    }

    private function saveChunkRequest(array $overrides = [], ?object $chunk = null): Request
    {
        return new Request(array_merge([
            'chunk_total'            => '1',
            'chunk_index'            => '1',
            'resource_temp_basename' => self::TEMP_BASE,
            'resource_ext'           => 'gif',
            'group_subdir'           => self::SUB_DIR,
            'group'                  => 'file',
            'resource_hash'          => 'h',
        ], $overrides), ['resource_chunk' => $chunk ?? $this->chunkObject('')]);
    }

    private function runPreprocess(?Request $request = null)
    {
        TestState::$request = $request ?? $this->preprocessRequest();

        return (new UploadController())->preprocess();
    }

    private function runSaveChunk(?Request $request = null)
    {
        TestState::$request = $request ?? $this->saveChunkRequest();

        return (new UploadController())->saveChunk();
    }

    private function decoded($response): array
    {
        return json_decode($response->getBody(), true);
    }

    /** Minimal valid 1x1 GIF89a, recognized by mime_content_type as image/gif */
    private function gifContent(): string
    {
        return "GIF89a"
            . "\x01\x00\x01\x00"
            . "\x80\x00\x00"
            . "\x00\x00\x00\xff\xff\xff"
            . "\x21\xf9\x04\x00\x00\x00\x00\x00"
            . "\x2c\x00\x00\x00\x00\x01\x00\x01\x00\x00"
            . "\x02\x02\x44\x01\x00"
            . "\x3b";
    }

    // ---------- preprocess ----------

    public function testPreprocessConstructorRegistersTranslationResources()
    {
        new UploadController();

        $this->assertCount(2, TestState::$translationResources);
    }

    public function testPreprocessRejectsMissingParameters()
    {
        $resp = $this->runPreprocess($this->preprocessRequest(['resource_name' => '']));

        $this->assertSame('invalid_resource_params', $this->decoded($resp)['error']);
    }

    public function testPreprocessRejectsMissingResourceHash()
    {
        $request = $this->preprocessRequest();
        unset($request->inputs['resource_hash']);

        $this->assertSame('invalid_resource_params', $this->decoded($this->runPreprocess($request))['error']);
    }

    public function testPreprocessCreatesPartAndHeaderFilesOnSuccess()
    {
        $this->createDirs();
        $resp = $this->runPreprocess();

        $this->assertSame(200, $resp->getStatusCode());
        $this->assertSame('zh', TestState::$locale);
        $body = $this->decoded($resp);
        $this->assertSame(0, $body['error']);
        $this->assertSame(1000000, $body['chunkSize']);
        $this->assertSame(date('Ym'), $body['groupSubDir']);
        $this->assertNotEmpty($body['resourceTempBaseName']);
        $this->assertSame('gif', $body['resourceExt']);

        $part   = TestState::$basePath . '/' . self::ROOT_DIR . '/' . self::GROUP_DIR . '/' . $body['groupSubDir'] . '/' . $body['resourceTempBaseName'] . '.gif.part';
        $header = TestState::$basePath . '/' . self::ROOT_DIR . '/_header/' . $body['resourceTempBaseName'];
        $this->assertFileExists($part);
        $this->assertFileExists($header);
        $this->assertSame('0', file_get_contents($header));
    }

    public function testPreprocessRejectsOversizedResource()
    {
        $resp = $this->runPreprocess($this->preprocessRequest(['resource_size' => '104857601']));

        $this->assertSame('invalid_resource_size', $this->decoded($resp)['error']);
    }

    public function testPreprocessRejectsZeroSizeResource()
    {
        $resp = $this->runPreprocess($this->preprocessRequest(['resource_size' => '00']));

        $this->assertSame('invalid_resource_size', $this->decoded($resp)['error']);
    }

    public function testPreprocessRejectsForbiddenExtension()
    {
        $resp = $this->runPreprocess($this->preprocessRequest(['resource_name' => 'a.php']));

        $this->assertSame('invalid_resource_type', $this->decoded($resp)['error']);
    }

    public function testPreprocessRejectsExtensionOutsideWhitelist()
    {
        $resp = $this->runPreprocess($this->preprocessRequest(['resource_name' => 'a.xyz']));

        $this->assertSame('invalid_resource_type', $this->decoded($resp)['error']);
    }

    public function testPreprocessUnknownGroupMapsToInvalidOperation()
    {
        $resp = $this->runPreprocess($this->preprocessRequest(['group' => 'nope']));

        $this->assertSame('invalid_operation', $this->decoded($resp)['error']);
    }

    public function testPreprocessInstantCompletionReturnsSavedPathWithoutCreatingFiles()
    {
        $this->setInstantCompletion(true);
        TestState::$redisHash['aetherupload_resource'] = ['file_abc123' => 'file_202608_old.gif'];

        $resp = $this->runPreprocess($this->preprocessRequest(['resource_hash' => 'abc123']));
        $body = $this->decoded($resp);

        $this->assertSame(0, $body['error']);
        $this->assertSame('file_202608_old.gif', $body['savedPath']);
        $this->assertFileDoesNotExist($this->uploadDir());
    }

    // ---------- saveChunk ----------

    public function testSaveChunkRejectsMissingParameters()
    {
        $request = $this->saveChunkRequest();
        unset($request->inputs['chunk_index']);

        $this->assertSame('invalid_resource_params', $this->decoded($this->runSaveChunk($request))['error']);
    }

    public function testSaveChunkRejectsPathTraversalInGroupSubDir()
    {
        $resp = $this->runSaveChunk($this->saveChunkRequest(['group_subdir' => '..']));

        $this->assertSame('invalid_resource_params', $this->decoded($resp)['error']);
    }

    public function testSaveChunkRejectsSlashInTempBasename()
    {
        $resp = $this->runSaveChunk($this->saveChunkRequest(['resource_temp_basename' => 'a/b']));

        $this->assertSame('invalid_resource_params', $this->decoded($resp)['error']);
    }

    public function testSaveChunkRejectsSlashInResourceExt()
    {
        $resp = $this->runSaveChunk($this->saveChunkRequest(['resource_ext' => 'gif/../php']));

        $this->assertSame('invalid_resource_params', $this->decoded($resp)['error']);
    }

    public function testSaveChunkRejectsTooManyChunks()
    {
        $resp = $this->runSaveChunk($this->saveChunkRequest(['chunk_total' => '10001']));

        $this->assertSame('invalid_resource_params', $this->decoded($resp)['error']);
    }

    public function testSaveChunkUnknownGroupMapsToInvalidOperation()
    {
        $resp = $this->runSaveChunk($this->saveChunkRequest(['group' => 'nope']));

        $this->assertSame('invalid_operation', $this->decoded($resp)['error']);
    }

    public function testSaveChunkRejectsExtensionOutsideWhitelist()
    {
        $resp = $this->runSaveChunk($this->saveChunkRequest(['resource_ext' => 'xyz']));

        $this->assertSame('invalid_resource_type', $this->decoded($resp)['error']);
    }

    public function testSaveChunkHardRejectsExecutableExtensionWhenWhitelistEmpty()
    {
        TestState::set(self::PREFIX . '.groups.file.resource_extensions', []);

        $resp = $this->runSaveChunk($this->saveChunkRequest(['resource_ext' => 'phtml']));

        $this->assertSame('invalid_resource_type', $this->decoded($resp)['error']);
    }

    public function testSaveChunkRejectsWhenPartFileMissing()
    {
        $resp = $this->runSaveChunk();

        $this->assertSame('invalid_operation', $this->decoded($resp)['error']);
    }

    public function testSaveChunkRejectsInvalidChunkObject()
    {
        $this->createPartAndHeader('0');

        $resp = $this->runSaveChunk($this->saveChunkRequest([], $this->chunkObject('GIF89a', false)));

        $this->assertSame('upload_error', $this->decoded($resp)['error']);
    }

    public function testSaveChunkRejectsMissingChunkFile()
    {
        $this->createPartAndHeader('0');
        $request = $this->saveChunkRequest();
        unset($request->files['resource_chunk']);

        $resp = $this->runSaveChunk($request);

        $this->assertSame('upload_error', $this->decoded($resp)['error']);
    }

    public function testSaveChunkRejectsChunkExceedingMaxSize()
    {
        TestState::set(self::PREFIX . '.groups.file.resource_maxsize', 5);
        $this->createPartAndHeader('0');

        $resp = $this->runSaveChunk($this->saveChunkRequest(
            ['chunk_index' => '1', 'chunk_total' => '1'],
            $this->chunkObject('1234567890')
        ));

        $this->assertSame('invalid_resource_size', $this->decoded($resp)['error']);
        $this->assertFileDoesNotExist($this->partPath());
        $this->assertFileDoesNotExist($this->headerPath());
    }

    public function testSaveChunkRejectsMalformedResourceHash()
    {
        $this->createPartAndHeader('0');

        $resp = $this->runSaveChunk($this->saveChunkRequest(['resource_hash' => 'bad hash']));

        $this->assertSame('invalid_operation', $this->decoded($resp)['error']);
    }

    public function testSaveChunkDuplicateChunkIsIdempotent()
    {
        $this->createPartAndHeader('0');
        $partContent = 'GIF89a-part1';

        $resp = $this->runSaveChunk($this->saveChunkRequest(
            ['chunk_index' => '1', 'chunk_total' => '2'],
            $this->chunkObject($partContent)
        ));
        $this->assertSame(0, $this->decoded($resp)['error']);
        $this->assertSame($partContent, file_get_contents($this->partPath()));
        $this->assertSame('1', file_get_contents($this->headerPath()));

        // re-send the same chunk with different payload -> success, part untouched
        $resp2 = $this->runSaveChunk($this->saveChunkRequest(
            ['chunk_index' => '1', 'chunk_total' => '2'],
            $this->chunkObject($partContent . 'corrupted')
        ));
        $this->assertSame(0, $this->decoded($resp2)['error']);
        $this->assertSame($partContent, file_get_contents($this->partPath()));
        $this->assertSame('1', file_get_contents($this->headerPath()));
    }

    public function testSaveChunkOutOfOrderChunkIsRejected()
    {
        $this->createPartAndHeader('1');

        $resp = $this->runSaveChunk($this->saveChunkRequest(
            ['chunk_index' => '3', 'chunk_total' => '3'],
            $this->chunkObject('GIF89a')
        ));

        $this->assertSame('upload_error', $this->decoded($resp)['error']);
        $this->assertSame('', file_get_contents($this->partPath()));
    }

    public function testSaveChunkAppendsChunkAndUpdatesHeader()
    {
        $this->createPartAndHeader('0');

        $resp = $this->runSaveChunk($this->saveChunkRequest(
            ['chunk_index' => '1', 'chunk_total' => '2'],
            $this->chunkObject('GIF89a-chunk1')
        ));

        $this->assertSame(0, $this->decoded($resp)['error']);
        $this->assertSame('GIF89a-chunk1', file_get_contents($this->partPath()));
        $this->assertSame('1', file_get_contents($this->headerPath()));
    }

    public function testSaveChunkCompletesResourceAndRenamesToHashName()
    {
        $gif   = $this->gifContent();
        $hash  = md5($gif);
        $split = 10;
        $this->createPartAndHeader('0');

        // chunk 1/2
        $resp = $this->runSaveChunk($this->saveChunkRequest(
            ['chunk_index' => '1', 'chunk_total' => '2', 'resource_hash' => $hash],
            $this->chunkObject(substr($gif, 0, $split))
        ));
        $this->assertSame(0, $this->decoded($resp)['error']);
        $this->assertSame(substr($gif, 0, $split), file_get_contents($this->partPath()));

        // final chunk 2/2
        $resp = $this->runSaveChunk($this->saveChunkRequest(
            ['chunk_index' => '2', 'chunk_total' => '2', 'resource_hash' => $hash],
            $this->chunkObject(substr($gif, $split))
        ));
        $body = $this->decoded($resp);

        $this->assertSame(0, $body['error']);
        $expectedPath = 'file_' . self::SUB_DIR . '_' . $hash . '.gif';
        $this->assertSame($expectedPath, $body['savedPath']);
        $this->assertFileDoesNotExist($this->partPath());
        $this->assertFileDoesNotExist($this->headerPath());
        $this->assertFileExists($this->uploadDir() . '/' . $hash . '.gif');
        $this->assertSame($gif, file_get_contents($this->uploadDir() . '/' . $hash . '.gif'));
        $this->assertSame([], TestState::$events);
    }

    public function testSaveChunkHashMismatchFailsAndCleansUp()
    {
        $gif = $this->gifContent();
        $this->createPartAndHeader('0');

        $resp = $this->runSaveChunk($this->saveChunkRequest(
            ['chunk_index' => '1', 'chunk_total' => '1', 'resource_hash' => 'deadbeef'],
            $this->chunkObject($gif)
        ));

        $this->assertSame('upload_error', $this->decoded($resp)['error']);
        $this->assertFileDoesNotExist($this->partPath());
        $this->assertFileDoesNotExist($this->headerPath());
    }

    public function testSaveChunkMimeTypeMismatchFailsAndCleansUp()
    {
        $this->createPartAndHeader('0');
        // unclassifiable binary -> application/octet-stream -> extension 'bin' (not whitelisted)
        $content = "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00";

        $resp = $this->runSaveChunk($this->saveChunkRequest(
            ['chunk_index' => '1', 'chunk_total' => '1', 'resource_hash' => md5($content)],
            $this->chunkObject($content)
        ));

        $this->assertSame('invalid_resource_type', $this->decoded($resp)['error']);
        $this->assertFileDoesNotExist($this->partPath());
        $this->assertFileDoesNotExist($this->headerPath());
    }

    public function testSaveChunkInstantCompletionCleansPartAndHeaderAndIsIdempotent()
    {
        $this->setInstantCompletion(true);
        $this->createPartAndHeader('0');
        TestState::$redisHash['aetherupload_resource'] = ['file_hash' => 'file_202608_done.gif'];

        $resp = $this->runSaveChunk($this->saveChunkRequest(['resource_hash' => 'hash']));
        $this->assertSame(0, $this->decoded($resp)['error']);
        $this->assertSame('file_202608_done.gif', $this->decoded($resp)['savedPath']);
        $this->assertFileDoesNotExist($this->partPath());
        $this->assertFileDoesNotExist($this->headerPath());

        // second call with no files on disk still succeeds (idempotent)
        $resp2 = $this->runSaveChunk($this->saveChunkRequest(['resource_hash' => 'hash']));
        $this->assertSame(0, $this->decoded($resp2)['error']);
        $this->assertSame('file_202608_done.gif', $this->decoded($resp2)['savedPath']);
    }

    public function testSaveChunkCompletionWritesRedisAndFiresEventsWhenEnabled()
    {
        $this->setInstantCompletion(true);
        TestState::set(self::PREFIX . '.groups.file.event_before_upload_complete', true);
        TestState::set(self::PREFIX . '.groups.file.event_upload_complete', true);

        $gif  = $this->gifContent();
        $hash = md5($gif);
        $this->createPartAndHeader('0');

        $resp = $this->runSaveChunk($this->saveChunkRequest(
            ['chunk_index' => '1', 'chunk_total' => '1', 'resource_hash' => $hash],
            $this->chunkObject($gif)
        ));
        $savedPath = $this->decoded($resp)['savedPath'];

        $this->assertSame(0, $this->decoded($resp)['error']);
        $this->assertSame('file_' . self::SUB_DIR . '_' . $hash . '.gif', $savedPath);
        // 秒传记录必须能被读回；具体存储形态（hash 还是独立 key）由 RedisSavedPath 自己决定，见 RedisSavedPathTest
        $this->assertSame($savedPath, RedisSavedPath::get(RedisSavedPath::getKey('file', $hash)));

        $names = array_column(TestState::$events, 'name');
        $this->assertContains('aetherupload.before_upload_complete', $names);
        $this->assertContains('aetherupload.upload_complete', $names);
    }
}
