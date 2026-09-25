<?php

namespace AetherUpload\Adapter\Laravel;

use AetherUpload\Contract\ResponseFactoryInterface;
use Illuminate\Support\Str;

class LaravelResponseFactory implements ResponseFactoryInterface
{
    public function text(string $body, int $status = 200): object
    {
        return new LaravelResponse($body, $status, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }

    /**
     * @param mixed $data
     */
    public function json($data, int $status = 200): object
    {
        // 与其余五个适配器保持同一组 flag，保证同一份错误消息字节一致；
        // 因此不用 JsonResponse（它默认不转义斜杠与 Unicode），自己编码后交给普通响应承载。
        $body = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return new LaravelResponse($body, $status, ['Content-Type' => 'application/json']);
    }

    public function file(string $path): object
    {
        return new LaravelFileResponse($path);
    }

    public function download(string $path, string $name): object
    {
        $response = new LaravelFileResponse($path, 200, [], true, 'attachment');

        // 与 Illuminate\Routing\ResponseFactory::download() 同款文件名编码：
        // 非 ASCII 名字必须有 ASCII 兜底，否则 Symfony 的 HeaderUtils 直接抛异常
        $response->setContentDisposition('attachment', $name, str_replace('%', '', Str::ascii($name)));

        return $response;
    }
}
