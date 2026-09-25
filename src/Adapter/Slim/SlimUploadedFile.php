<?php

namespace AetherUpload\Adapter\Slim;

use AetherUpload\Contract\UploadedFileInterface;
use Psr\Http\Message\UploadedFileInterface as Psr7UploadedFileInterface;
use RuntimeException;

/**
 * PSR-7 上传对象 → 内核上传端口。
 *
 * PSR-7 的 UploadedFileInterface **没有 getRealPath()**（内核要从本地路径读取分块追加进 .part），
 * 因此这里按两条路取路径，取到哪条已实测（见下）：
 *
 *  1. **首选**：`$file->getStream()->getMetadata('uri')`。本地文件流一定带 uri：
 *     - 真机上传：slim/psr7 的 `UploadedFile::parseUploadedFiles()`（UploadedFile.php:255）拿
 *       `$_FILES[..]['tmp_name']`（字符串路径）造对象，getStream() 内部 `createStreamFromFile($path)`，
 *       uri 即那个临时文件路径；
 *     - 本仓库集成测试：`new Slim\Psr7\UploadedFile($tmpPath, ...)`（同为字符串路径），走的是同一条。
 *     实测结论：**真机上传、进程内 E2E、真 HTTP 冒烟（php -S + curl -F）走的都是这一条，不产生任何拷贝**；
 *     证据是 tests/Integration/slim/SlimAdapterTest::testLocalFileStreamIsUsedInPlace（断言
 *     getRealPath() 原样等于源路径、且没有登记任何临时文件）与 testRealUploadDoesNotLeaveCopies。
 *  2. **回落**：流没有可用的 uri（内存流 `php://temp`、`php://memory`，或非本地流）。
 *     这类流必须自己落盘成临时文件并把路径登记给 SlimRequest，请求结束时删除 ——
 *     否则内核 append() 读不到内容，分块会静默拼不上。
 *     实测证据：SlimAdapterTest::testEndToEndUploadWithMemoryStreamChunks（内存流分 3 块上传，
 *     落盘内容逐字节相等，且请求结束后 /tmp/aetherupload-chunk-* 一个不剩）。
 */
class SlimUploadedFile implements UploadedFileInterface
{
    /** @var Psr7UploadedFileInterface */
    private $file;

    /** @var callable|null function (string $path): void —— 登记回落路径，请求结束由适配器删除 */
    private $onTempFile;

    /** @var string|null 已解析出的路径（两条路都只解析一次） */
    private $realPath;

    public function __construct(Psr7UploadedFileInterface $file, ?callable $onTempFile = null)
    {
        $this->file = $file;
        $this->onTempFile = $onTempFile;
    }

    public function isValid(): bool
    {
        // PSR-7 用一个错误码表达「上传是否成功」，UPLOAD_ERR_OK 才算有效
        return $this->file->getError() === UPLOAD_ERR_OK;
    }

    public function getRealPath(): string
    {
        if ( $this->realPath !== null ) {
            return $this->realPath;
        }

        $uri = $this->streamUri();

        if ( $uri !== null && is_file($uri) && is_readable($uri) ) {
            return $this->realPath = $uri;
        }

        return $this->realPath = $this->copyToTempFile();
    }

    /** 流自己报告的 uri；拿不到（已 detach、无 metadata、非字符串）时返回 null */
    private function streamUri(): ?string
    {
        try {
            $stream = $this->file->getStream();
        } catch ( \Throwable $e ) {
            return null;
        }

        $uri = $stream->getMetadata('uri');

        return is_string($uri) && $uri !== '' ? $uri : null;
    }

    private function copyToTempFile(): string
    {
        $temp = tempnam(sys_get_temp_dir(), 'aetherupload-chunk-');

        if ( $temp === false ) {
            throw new RuntimeException('AetherUpload: 无法为上载流创建临时文件');
        }

        $stream = $this->file->getStream();

        if ( $stream->isSeekable() ) {
            $stream->rewind();
        }

        $target = fopen($temp, 'wb');

        if ( $target === false ) {
            throw new RuntimeException('AetherUpload: 无法写入临时文件 ' . $temp);
        }

        try {
            while ( ! $stream->eof() ) {
                $chunk = $stream->read(262144);

                if ( $chunk === '' ) {
                    break;
                }

                fwrite($target, $chunk);
            }
        } finally {
            fclose($target);
        }

        if ( $this->onTempFile !== null ) {
            ($this->onTempFile)($temp);
        }

        return $temp;
    }
}
