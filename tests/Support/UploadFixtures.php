<?php

namespace AetherUpload\Tests\Support;

use AetherUpload\UploadController;
use Webman\Http\Request;

/**
 * 分块上传测试脚手架：伪造上传文件对象、构造 preprocess/saveChunk 请求、直接调用控制器。
 * 与 UploadControllerTest 中的私有辅助方法保持同一形状（匿名类伪造上传文件对象）。
 *
 * 注意：trait 常量需要 PHP 8.2，本库支持 PHP 8.0，因此路径一律用私有属性而非 const。
 */
trait UploadFixtures
{
    /** 上传根目录（相对 base_path()） */
    private string $fxRootDir = 'storage/app/aetherupload';

    /** 分组目录名 */
    private string $fxGroupDir = 'file';

    /** 分块文件落在的子目录名（resource_subdir_rule=month 时为 Ym） */
    private string $fxSubDir = '202609';

    /** 临时文件基础名（自行构造请求时用，走 preprocess 时用返回的 resourceTempBaseName） */
    private string $fxTempBase = 'tempabc123';

    private function uploadDir(string $subDir = ''): string
    {
        return TestState::dir($this->fxRootDir, $this->fxGroupDir, $subDir === '' ? $this->fxSubDir : $subDir);
    }

    private function headerDir(): string
    {
        return TestState::$basePath . '/' . $this->fxRootDir . '/_header';
    }

    private function partPath(string $tempBase, string $ext = 'gif'): string
    {
        return $this->uploadDir() . '/' . $tempBase . '.' . $ext . '.part';
    }

    private function headerPath(string $tempBase): string
    {
        return $this->headerDir() . '/' . $tempBase;
    }

    /** preprocess 只会在已存在的分组目录下建月份子目录，_header 目录也需预先存在 */
    private function createDirs(): void
    {
        // 同一个用例里可能多次调用（如矩阵循环），已存在时不再 mkdir，避免 "File exists" 告警
        foreach ( [$this->uploadDir(), $this->headerDir()] as $dir ) {
            if ( ! is_dir($dir) ) {
                mkdir($dir, 0755, true);
            }
        }
    }

    /** 伪造上传文件对象：控制器只用到 isValid() 与 getRealPath() */
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
            'resource_temp_basename' => $this->fxTempBase,
            'resource_ext'           => 'gif',
            'group_subdir'           => $this->fxSubDir,
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

    /**
     * Minimal valid 1x1 GIF89a, recognized by mime_content_type as image/gif.
     * $suffix 用于造出内容不同但依然被识别为 gif 的第二个文件（尾部附加字节不影响头部魔数识别）。
     */
    private function gifContent(string $suffix = ''): string
    {
        return "GIF89a"
            . "\x01\x00\x01\x00"
            . "\x80\x00\x00"
            . "\x00\x00\x00\xff\xff\xff"
            . "\x21\xf9\x04\x00\x00\x00\x00\x00"
            . "\x2c\x00\x00\x00\x00\x01\x00\x01\x00\x00"
            . "\x02\x02\x44\x01\x00"
            . "\x3b"
            . $suffix;
    }
}
