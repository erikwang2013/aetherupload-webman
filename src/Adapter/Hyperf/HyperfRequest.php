<?php

namespace AetherUpload\Adapter\Hyperf;

use AetherUpload\Contract\RequestInterface;
use AetherUpload\Contract\UploadedFileInterface as AetherUploadedFileInterface;
use AetherUpload\Kernel\UploadedFile;
use Hyperf\Context\Context;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface as PsrUploadedFileInterface;
use Throwable;

/**
 * 把 Hyperf 的协程局部 PSR-7 请求映射到内核请求端口。
 *
 * 刻意只读 getParsedBody()/getQueryParams() 的原始值，不走 Hyperf\HttpServer\Request::input()：
 * 后者经 data_get()，点号会被当路径分隔符，且内核契约要求「原样返回、不做任何转换与过滤」。
 */
class HyperfRequest implements RequestInterface
{
    /**
     * @param mixed $default
     * @return mixed
     */
    public function input(string $key, $default = null)
    {
        $request = $this->current();

        if ( $request === null ) {
            return $default;
        }

        $body = $this->parsedBody($request);

        // array_key_exists 而非 isset：显式传 null 的字段要原样返回 null，不能当成缺失
        if ( array_key_exists($key, $body) ) {
            return $body[$key];
        }

        $query = $request->getQueryParams();

        return array_key_exists($key, $query) ? $query[$key] : $default;
    }

    public function file(string $key): ?AetherUploadedFileInterface
    {
        $request = $this->current();

        if ( $request === null ) {
            return null;
        }

        $files = $request->getUploadedFiles();
        $file = isset($files[$key]) ? $files[$key] : null;

        if ( ! $file instanceof PsrUploadedFileInterface ) {
            return null;
        }

        // Hyperf 的 UploadedFile 继承 SplFileInfo，isValid()/getRealPath() 都有，
        // 与 webman 的 UploadFile 一样可以直接套内核的包装类（getRealPath 由 SplFileInfo 提供）
        return new UploadedFile($file);
    }

    public function all(): array
    {
        $request = $this->current();

        if ( $request === null ) {
            return [];
        }

        return $this->parsedBody($request) + $request->getQueryParams();
    }

    /** @return array<string,mixed> */
    private function parsedBody(ServerRequestInterface $request): array
    {
        $body = $request->getParsedBody();

        // PSR-7 允许 object（如 stdClass），内核只认数组
        if ( is_object($body) ) {
            $body = (array)$body;
        }

        return is_array($body) ? $body : [];
    }

    private function current(): ?ServerRequestInterface
    {
        try {
            $request = Context::get(ServerRequestInterface::class);

            return $request instanceof ServerRequestInterface ? $request : null;
        } catch ( Throwable $e ) {
            return null;
        }
    }
}
