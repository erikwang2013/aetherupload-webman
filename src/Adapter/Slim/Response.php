<?php

namespace AetherUpload\Adapter\Slim;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use RuntimeException;

/**
 * 内核响应的**可变 shim**（不是 PSR-7 响应）。
 *
 * 为什么需要它：内核（ResourceController / Responser）是按 webman 的可变响应写的 ——
 * `withHeader()` 就地修改自己并返回 `$this`。Slim 的 PSR-7 响应是不可变的（`withHeader()`
 * 返回新实例），若适配器直接给出真 PSR-7，「丢弃了返回值的 withHeader 调用」会在 Slim 下
 * 静默丢头，六个框架行为就不一致了。
 *
 * 转换点（**全案唯一**，维护者按这条找）：
 *   内核产物只在本类的 toPsr7() 里落成真 PSR-7，调用者是 Bootstrap::create() 注册四条路由时
 *   包的那层闭包 —— 它在把结果交回 Slim 之前转换一次。必须在那里转，不能放进中间件：
 *   Slim 的 Route::run()/Route::handle() 声明了 `: ResponseInterface`（strict_types），
 *   shim 绝无可能穿过 Slim 的路由调用链。用户若自己写路由复用内核控制器，也要做同样的转换。
 */
class Response
{
    /** 与 webman 的 json() 完全一致的编码选项：六个适配器必须字节一致 */
    const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;

    /** @var string */
    private $body = '';

    /** @var int */
    private $status = 200;

    /** @var array<string,array{0:string,1:string}> 小写名 => [原始名, 值]（withHeader 是替换语义） */
    private $headers = [];

    /** @var string|null 就地发送的文件（file / download），转换时才打开，不经过内存 */
    private $file;

    /** @var string|null 附件名；非 null 表示 download */
    private $downloadName;

    private function __construct(string $body = '', int $status = 200)
    {
        $this->body = $body;
        $this->status = $status;
    }

    public static function text(string $body, int $status = 200): self
    {
        return new self($body, $status);
    }

    /**
     * @param mixed $data
     */
    public static function json($data, int $status = 200): self
    {
        return (new self(json_encode($data, self::JSON_FLAGS), $status))
            ->withHeader('Content-Type', 'application/json');
    }

    public static function file(string $path): self
    {
        $response = new self();
        $response->file = $path;

        return $response;
    }

    public static function download(string $path, string $name): self
    {
        $response = self::file($path);
        $response->downloadName = $name;

        return $response;
    }

    /**
     * 就地设置响应头并返回 $this —— 刻意不是 PSR-7 的不可变语义，见类注释。
     *
     * @param string $name  原始大小写，落成 PSR-7 时原样交给 withHeader()
     * @param string $value
     */
    public function withHeader(string $name, string $value): self
    {
        $this->headers[strtolower($name)] = [$name, $value];

        return $this;
    }

    /**
     * 落成真 PSR-7 响应（唯一转换点，见类注释）。
     *
     * @param ResponseFactoryInterface      $responseFactory 宿主的 PSR-17 响应工厂（Slim\App::getResponseFactory()）
     * @param StreamFactoryInterface|null   $streamFactory   有它就把文件流直接交给 PSR-7（零拷贝）；
     *                                                       没有就分块拷进响应体，不整份进内存
     */
    public function toPsr7(ResponseFactoryInterface $responseFactory, ?StreamFactoryInterface $streamFactory = null): ResponseInterface
    {
        $response = $responseFactory->createResponse($this->status);

        foreach ( $this->headers as $header ) {
            $response = $response->withHeader($header[0], $header[1]);
        }

        if ( $this->file === null ) {
            $response->getBody()->write($this->body);

            return $response;
        }

        if ( $this->downloadName !== null ) {
            $response = $response->withHeader('Content-Disposition', 'attachment; filename="' . $this->downloadName . '"');
        }

        // 浏览器不会为未知类型渲染内容，而内核会给展示/下载响应加 nosniff，
        // 所以 Content-Type 必须由这里补 —— 按内容嗅探（与 PartialResource::checkMimeType 同一套判定）
        if ( $response->hasHeader('Content-Type') === false && function_exists('mime_content_type') ) {
            $mime = @mime_content_type($this->file);

            if ( is_string($mime) && $mime !== '' ) {
                $response = $response->withHeader('Content-Type', $mime);
            }
        }

        if ( $streamFactory !== null ) {
            return $response->withBody($streamFactory->createStreamFromFile($this->file, 'rb'));
        }

        $handle = @fopen($this->file, 'rb');

        if ( $handle === false ) {
            throw new RuntimeException('AetherUpload: 无法读取待发送的文件 ' . $this->file);
        }

        try {
            while ( ! feof($handle) ) {
                $chunk = fread($handle, 262144);

                if ( $chunk === false ) {
                    break;
                }

                $response->getBody()->write($chunk);
            }
        } finally {
            fclose($handle);
        }

        return $response;
    }
}
