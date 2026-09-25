<?php

namespace AetherUpload\Adapter\Hyperf;

use AetherUpload\Contract\ResponseFactoryInterface;
use Hyperf\Codec\Json;
use Hyperf\Context\ApplicationContext;
use Hyperf\Context\ResponseContext;
use Hyperf\HttpMessage\Stream\SwooleFileStream;
use Hyperf\HttpMessage\Stream\SwooleStream;
use Hyperf\HttpServer\Contract\ResponseInterface;
use Swow\Psr7\Message\ResponsePlusInterface;
use Throwable;

class HyperfResponseFactory implements ResponseFactoryInterface
{
    /**
     * 契约要求六框架字节一致的 JSON flag。
     *
     * Hyperf 的 Response::json() 只带 JSON_UNESCAPED_UNICODE（缺 SLASHES），
     * 而 Json::encode() 内部会 OR 上 JSON_THROW_ON_ERROR，因此这里显式补齐前两个。
     */
    const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

    public function text(string $body, int $status = 200): object
    {
        return $this->base($status)
            ->withHeader('content-type', 'text/plain; charset=utf-8')
            ->withBody(new SwooleStream($body));
    }

    /**
     * @param mixed $data
     */
    public function json($data, int $status = 200): object
    {
        return $this->base($status)
            ->withHeader('content-type', 'application/json; charset=utf-8')
            ->withBody(new SwooleStream(Json::encode($data, self::JSON_FLAGS)));
    }

    public function file(string $path): object
    {
        return $this->base(200)
            ->withHeader('content-type', \AetherUpload\MimeType::from($path))
            ->withBody(new SwooleFileStream($path));
    }

    public function download(string $path, string $name): object
    {
        // 宿主的 download() 自带 ETag/If-None-Match → 304、Content-Disposition 与文件流，
        // 直接复用；它读 RequestContext::get()，只在本请求的协程里可用（内核只会在控制器里调它）
        return ApplicationContext::getContainer()
            ->get(ResponseInterface::class)
            ->download($path, $name);
    }

    /**
     * 本协程的响应对象（服务端在 initRequestAndResponse 里按请求放好）。
     *
     * 克隆出来的对象仍是 Swow 的 ResponsePlusInterface —— Hyperf 的 CoreMiddleware 靠这个
     * 接口判断「已经是响应、不必再转换」，所以绝不能换成自建的非 PSR-7 对象。
     */
    private function base(int $status): ResponsePlusInterface
    {
        try {
            return ResponseContext::get()->withStatus($status);
        } catch ( Throwable $e ) {
            // 没有请求上下文（CLI 里直接调内核）时退化为一个独立响应对象，不抛异常
            return (new \Hyperf\HttpMessage\Server\Response())->withStatus($status);
        }
    }
}
