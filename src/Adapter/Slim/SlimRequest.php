<?php

namespace AetherUpload\Adapter\Slim;

use AetherUpload\Contract\UploadedFileInterface;
use AetherUpload\Kernel\Psr7Request;
use Psr\Http\Message\UploadedFileInterface as Psr7UploadedFileInterface;

/**
 * 当前 PSR-7 请求 → 内核请求端口（Slim 这一份）。
 *
 * 字段读取、临时副本清理、上下文身份的理由都在 `AetherUpload\Kernel\Psr7Request`：
 * Yii3 适配器也是「宿主没有 request() 全局函数，请求由适配器显式持有」，两边共用一份实现。
 *
 * 保留本类名的实际原因是上传对象类型：Slim 侧必须有 `SlimUploadedFile` 这个可被既有用例引用的类，
 * 所以这里覆写工厂方法，把它的实例交出去（其余行为一字未改）。
 *
 * @see Psr7Request
 */
class SlimRequest extends Psr7Request
{
    protected function createUploadedFile(Psr7UploadedFileInterface $file, callable $onTempFile): UploadedFileInterface
    {
        return new SlimUploadedFile($file, $onTempFile);
    }
}
