<?php

namespace AetherUpload\Adapter\Slim;

use AetherUpload\Contract\ResponseFactoryInterface;

/**
 * 响应端口：只生产可变 shim，落成真 PSR-7 由 Response::toPsr7() 在路由包装闭包里完成（见 Response 的类注释）。
 */
class SlimResponseFactory implements ResponseFactoryInterface
{
    public function text(string $body, int $status = 200): object
    {
        return Response::text($body, $status);
    }

    /**
     * @param mixed $data
     */
    public function json($data, int $status = 200): object
    {
        return Response::json($data, $status);
    }

    public function file(string $path): object
    {
        return Response::file($path);
    }

    public function download(string $path, string $name): object
    {
        return Response::download($path, $name);
    }
}
