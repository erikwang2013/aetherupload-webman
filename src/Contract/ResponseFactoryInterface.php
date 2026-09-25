<?php

namespace AetherUpload\Contract;

/**
 * 响应构造端口。
 *
 * 返回类型刻意写成 object 而非 PSR-7 ResponseInterface：
 * 内核只依赖「能用 withHeader() 串起来」这一件事，Slim 的不可变 PSR-7 响应由适配器
 * 在最后一步落成真 PSR-7，测试替身（ResponseStub）也能直接满足这个契约。
 */
interface ResponseFactoryInterface
{
    /** 纯文本 + 状态码（404 分支用） */
    public function text(string $body, int $status = 200): object;

    /**
     * JSON 响应。
     *
     * 六个适配器必须使用同一组 flag：
     * JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR，
     * 否则同一份错误消息在不同框架下字节不同。
     *
     * @param mixed $data
     */
    public function json($data, int $status = 200): object;

    /** 就地发送文件（展示用），保留宿主的 304 / Range 行为 */
    public function file(string $path): object;

    /** 作为附件下载，$name 为客户端可见文件名 */
    public function download(string $path, string $name): object;
}
