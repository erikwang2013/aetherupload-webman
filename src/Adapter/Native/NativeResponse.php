<?php

namespace AetherUpload\Adapter\Native;

use RuntimeException;

/**
 * 内核响应的可变载体 + **唯一一次真正的发送**（SAPI 侧）。
 *
 * 内核（ResourceController / Responser）是按 webman 的可变响应写的 —— `withHeader()` 就地修改
 * 自己并返回 `$this`。其余框架各有把这份产物落成宿主响应的收尾步骤（Slim 是 toPsr7、
 * webman 直接就是宿主响应）；原生 PHP 没有人接管，所以由本类的 send() 用 header() + echo 直接发出去。
 *
 * 一次请求只发一次。send() 之后再改对象没有意义 —— 这一点与其它宿主一致（响应一旦落成就不再回头）。
 *
 * 不做 Range / 304：SAPI 没有这类内建行为。大文件分块下载与视频拖动按 README 的
 * `x_accel_redirect` 交给 nginx（同 webman 侧的建议）。
 */
class NativeResponse
{
    /** 与 webman 的 json() 完全一致的编码选项：各适配器必须字节一致 */
    const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;

    /** @var string */
    private $body = '';

    /** @var int */
    private $status = 200;

    /** @var array<string,array{0:string,1:string}> 小写名 => [原始名, 值]（withHeader 是替换语义） */
    private $headers = [];

    /** @var string|null 就地发送的文件（file / download），发送时才打开，不整份进内存 */
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
     * 就地设置响应头并返回 $this（可变语义，与 webman 一致）。
     */
    public function withHeader(string $name, string $value): self
    {
        $this->headers[strtolower($name)] = [$name, $value];

        return $this;
    }

    /** 已确定的状态码（Bootstrap::handle() 的返回值，供入口脚本/测试断言） */
    public function status(): int
    {
        return $this->status;
    }

    /**
     * 发送响应：状态码 → 响应头 → 响应体。返回状态码，便于 `exit(Bootstrap::handle())` 这类写法。
     */
    public function send(): int
    {
        if ( headers_sent($file, $line) ) {
            // 已经有人输出过了，再发头只会是 Warning；把事实说出来，别静默丢响应
            throw new RuntimeException(
                'AetherUpload: 响应头已由 ' . $file . ':' . $line . ' 发出，无法再发送本次响应。'
                . '入口脚本里 echo/var_dump 之类必须在 Bootstrap::handle() 之前清干净。'
            );
        }

        if ( $this->downloadName !== null ) {
            header('Content-Disposition: attachment; filename="' . $this->downloadName . '"');
        }

        // 浏览器不会为未知类型渲染内容，而内核会给展示/下载响应加 nosniff，
        // 所以 Content-Type 必须由这里补 —— 按内容嗅探（与 PartialResource::checkMimeType 同一套判定）
        if ( $this->file !== null && ! isset($this->headers['content-type']) && function_exists('mime_content_type') ) {
            $mime = @mime_content_type($this->file);

            if ( is_string($mime) && $mime !== '' ) {
                header('Content-Type: ' . $mime);
            }
        }

        http_response_code($this->status);

        foreach ( $this->headers as $header ) {
            header($header[0] . ': ' . $header[1]);
        }

        if ( $this->file === null ) {
            echo $this->body;

            return $this->status;
        }

        $handle = @fopen($this->file, 'rb');

        if ( $handle === false ) {
            // 走到这里通常是「响应头已经打算发了，文件却没了」。内核在校验阶段已确认文件存在，
            // 这里是二次确认，失败就明确报错而不是发一个空响应
            throw new RuntimeException('AetherUpload: 无法读取待发送的文件 ' . $this->file);
        }

        fpassthru($handle);
        fclose($handle);

        return $this->status;
    }
}
