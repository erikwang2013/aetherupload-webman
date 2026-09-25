<?php

namespace AetherUpload\Adapter\Slim;

use AetherUpload\Contract\RequestInterface;
use AetherUpload\Contract\UploadedFileInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface as Psr7UploadedFileInterface;

/**
 * 当前 PSR-7 请求 → 内核请求端口。
 *
 * 「当前请求」由 Bootstrap::create() 注册的中间件在每请求开始时写入（Slim 没有 webman 那样的
 * request() 全局函数，请求只能由适配器显式持有）。字段读取严格返回原始值：内核的类型守卫
 * 靠它在「攻击性输入（数组）」与「合法 JSON 数值」之间做判定，任何过滤或类型转换都会让它失效。
 */
class SlimRequest implements RequestInterface
{
    /** @var ServerRequestInterface|null */
    private $current;

    /** @var array<string,bool> 本次请求自己落盘的上传临时副本，请求结束时删除 */
    private $tempFiles = [];

    /** @var array<string,SlimUploadedFile> 字段名 → 端口对象（同一请求内复用，见 file()） */
    private $files = [];

    public function setCurrent(?ServerRequestInterface $request): void
    {
        $this->current = $request;
        $this->files = [];
    }

    /** 当前请求；CLI（无请求）时为 null */
    public function current(): ?ServerRequestInterface
    {
        return $this->current;
    }

    /**
     * @param mixed $default
     * @return mixed
     */
    public function input(string $key, $default = null)
    {
        if ( $this->current === null ) {
            return $default;
        }

        // 与 webman 的 Request::input() 同序：表单/JSON 体优先，其次查询串。
        // getParsedBody() 不保证是数组（JSON 标量、空体都是合法的），先判类型再取键
        $body = $this->current->getParsedBody();

        if ( is_array($body) && array_key_exists($key, $body) ) {
            return $body[$key];
        }

        $query = $this->current->getQueryParams();

        return array_key_exists($key, $query) ? $query[$key] : $default;
    }

    public function file(string $key): ?UploadedFileInterface
    {
        if ( $this->current === null ) {
            return null;
        }

        $files = $this->current->getUploadedFiles();
        $file = isset($files[$key]) ? $files[$key] : null;

        // 同名多文件（resource_chunk[]=...）在 PSR-7 里是数组，内核只认单文件，按缺失处理
        if ( ! $file instanceof Psr7UploadedFileInterface ) {
            return null;
        }

        // 同一请求内同名字段只包一次：端口对象的 getRealPath() 会缓存解析结果，
        // 每次都新建的话，无本地 uri 的流会被重复复制（第二次读到的还是 EOF）且副本无人清理。
        if ( isset($this->files[$key]) ) {
            return $this->files[$key];
        }

        return $this->files[$key] = new SlimUploadedFile($file, function (string $path): void {
            $this->tempFiles[$path] = true;
        });
    }

    public function all(): array
    {
        if ( $this->current === null ) {
            return [];
        }

        return array_merge($this->current->getQueryParams(), (array)$this->current->getParsedBody());
    }

    /** 删除本次请求落盘的临时副本（由适配器在请求结束时调用） */
    public function cleanupTempFiles(): void
    {
        foreach ( array_keys($this->tempFiles) as $path ) {
            @unlink($path);
        }

        $this->tempFiles = [];
        $this->files = [];
    }
}
