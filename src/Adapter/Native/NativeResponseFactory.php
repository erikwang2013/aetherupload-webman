<?php

namespace AetherUpload\Adapter\Native;

use AetherUpload\Contract\ResponseFactoryInterface;

/**
 * 响应端口：只生产可变载体（NativeResponse），真正的发送在 Bootstrap::handle() 的最后
 * 由 NativeResponse::send() 完成（那是全案唯一一次 header()/echo）。
 */
class NativeResponseFactory implements ResponseFactoryInterface
{
    public function text(string $body, int $status = 200): object
    {
        return NativeResponse::text($body, $status);
    }

    /**
     * @param mixed $data
     */
    public function json($data, int $status = 200): object
    {
        return NativeResponse::json($data, $status);
    }

    public function file(string $path): object
    {
        return NativeResponse::file($path);
    }

    public function download(string $path, string $name): object
    {
        return NativeResponse::download($path, $name);
    }
}
