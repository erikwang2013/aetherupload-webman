<?php

namespace AetherUpload\Adapter\ThinkPhp;

use AetherUpload\Adapter\ThinkPhp\Response\FileResponse;
use AetherUpload\Adapter\ThinkPhp\Response\JsonResponse;
use AetherUpload\Adapter\ThinkPhp\Response\TextResponse;
use AetherUpload\Contract\ResponseFactoryInterface;

/**
 * 响应端口。
 *
 * think\Response 没有 withHeader()（只有合并式的 header(array)），而内核依赖
 * `->withHeader(...)` 串起来，所以四种响应由薄子类补上这一个方法。
 */
class ThinkPhpResponseFactory implements ResponseFactoryInterface
{
    public function text(string $body, int $status = 200): object
    {
        return new TextResponse($body, $status);
    }

    /**
     * @param mixed $data
     */
    public function json($data, int $status = 200): object
    {
        return new JsonResponse($data, $status);
    }

    public function file(string $path): object
    {
        // force(false)：展示用，Content-Disposition 不带 attachment
        return (new FileResponse($path))->force(false);
    }

    public function download(string $path, string $name): object
    {
        // File 的 force 默认为 true，即 attachment
        return (new FileResponse($path))->name($name);
    }
}
