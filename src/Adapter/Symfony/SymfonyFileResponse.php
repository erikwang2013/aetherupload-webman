<?php

namespace AetherUpload\Adapter\Symfony;

use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * 文件响应：BinaryFileResponse 自带 304 / Range / ETag，展示与下载都用它。
 *
 * 继承而非包装：控制器必须返回 Response 实例，包装类过不了 HttpKernel 的类型检查。
 */
class SymfonyFileResponse extends BinaryFileResponse
{
    public function withHeader(string $name, string $value): self
    {
        $this->headers->set($name, $value);

        return $this;
    }
}
