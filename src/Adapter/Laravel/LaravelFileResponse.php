<?php

namespace AetherUpload\Adapter\Laravel;

use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * 文件响应版的 withHeader() —— 语义与 LaravelResponse 相同，见那里的说明。
 *
 * 单独一个类是因为 display/download 走的是 BinaryFileResponse（自带 304/Range/流式发送），
 * 换不得；它同样没有返回新实例的 withHeader()。
 */
class LaravelFileResponse extends BinaryFileResponse
{
    public function withHeader(string $name, string $value): self
    {
        $this->headers->set($name, $value);

        return $this;
    }
}
