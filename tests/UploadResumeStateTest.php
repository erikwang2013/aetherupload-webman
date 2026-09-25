<?php

namespace AetherUpload\Tests;

use AetherUpload\Tests\Support\TestState;
use AetherUpload\Tests\Support\UploadFixtures;
use PHPUnit\Framework\TestCase;

/**
 * 上传失败时的续传状态保护：无效分块不等于客户端放弃上传。
 */
class UploadResumeStateTest extends TestCase
{
    use UploadFixtures;

    private const PREFIX = 'plugin.erikwang2013.aetherupload-webman.app';

    protected function setUp(): void
    {
        TestState::reset();
        TestState::normalizeConfig();
        TestState::resetConfigMapper();
        mkdir(TestState::$basePath, 0777, true);
        $this->createDirs();
    }

    protected function tearDown(): void
    {
        remove_dir(TestState::$basePath);
    }

    /**
     * 用 preprocess 造出真实的进行中上传状态，返回 [tempBaseName, groupSubDir]。
     *
     * @return array{0:string, 1:string}
     */
    private function beginUpload(string $hash = 'h'): array
    {
        $pre = $this->decoded($this->runPreprocess($this->preprocessRequest(['resource_hash' => $hash])));
        $this->assertSame(0, $pre['error']);

        return [$pre['resourceTempBaseName'], $pre['groupSubDir']];
    }

    /**
     * 守护 bug 3（P1）：分块无效（模拟网络截断，PHP 报 UPLOAD_ERR_PARTIAL，即 isValid() === false）时
     * 只能返回 upload_error，不得 cleanup 掉已拼好的 .part 与 header——
     * 修复前 cleanup 会把续传进度一起删掉，客户端重传该分块就无处可续。
     */
    public function testInvalidChunkKeepsPartAndHeaderForResume(): void
    {
        [$tempBase, $groupSubDir] = $this->beginUpload();

        $this->assertFileExists($this->partPath($tempBase));
        $this->assertFileExists($this->headerPath($tempBase));

        $body = $this->decoded($this->runSaveChunk($this->saveChunkRequest([
            'chunk_total'            => '2',
            'chunk_index'            => '1',
            'resource_temp_basename' => $tempBase,
            'group_subdir'           => $groupSubDir,
        ], $this->chunkObject('GIF89a', false))));

        $this->assertSame('upload_error', $body['error']);
        $this->assertFileExists($this->partPath($tempBase), '无效分块不得删除已上传的分块内容');
        $this->assertFileExists($this->headerPath($tempBase), '无效分块不得删除续传进度 header');
        $this->assertSame('0', file_get_contents($this->headerPath($tempBase)), '续传进度不得被改写');

        // 客户端重传同一分块：续传状态还在，可以接着写
        $resend = $this->decoded($this->runSaveChunk($this->saveChunkRequest([
            'chunk_total'            => '2',
            'chunk_index'            => '1',
            'resource_temp_basename' => $tempBase,
            'group_subdir'           => $groupSubDir,
        ], $this->chunkObject('GIF89a-part1'))));

        $this->assertSame(0, $resend['error']);
        $this->assertSame('GIF89a-part1', file_get_contents($this->partPath($tempBase)));
        $this->assertSame('1', file_get_contents($this->headerPath($tempBase)));
    }

    /**
     * 守护 bug 3 的另一面：缺分块（chunk_index 跳号）同样只报错、不销毁进度。
     */
    public function testOutOfOrderChunkKeepsPartAndHeader(): void
    {
        [$tempBase, $groupSubDir] = $this->beginUpload();

        $body = $this->decoded($this->runSaveChunk($this->saveChunkRequest([
            'chunk_total'            => '3',
            'chunk_index'            => '3',
            'resource_temp_basename' => $tempBase,
            'group_subdir'           => $groupSubDir,
        ], $this->chunkObject('GIF89a'))));

        $this->assertSame('upload_error', $body['error']);
        $this->assertFileExists($this->partPath($tempBase));
        $this->assertFileExists($this->headerPath($tempBase));
    }
}
