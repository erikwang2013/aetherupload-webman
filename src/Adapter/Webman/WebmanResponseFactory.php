<?php

namespace AetherUpload\Adapter\Webman;

use AetherUpload\Contract\ResponseFactoryInterface;

class WebmanResponseFactory implements ResponseFactoryInterface
{
    public function text(string $body, int $status = 200): object
    {
        return response($body, $status);
    }

    /**
     * @param mixed $data
     */
    public function json($data, int $status = 200): object
    {
        // webman 的 json() 使用 JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR
        return json($data, $status);
    }

    public function file(string $path): object
    {
        return response()->file($path);
    }

    public function download(string $path, string $name): object
    {
        return response()->download($path, $name);
    }
}
