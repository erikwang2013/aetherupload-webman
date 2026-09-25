<?php

namespace AetherUpload\Kernel;

use AetherUpload\Contract\UploadedFileInterface;

/**
 * 把宿主的上传文件对象包装成内核端口对象。
 *
 * 需要它是因为宿主对象不必实现本内核的接口：webman 的 UploadFile、ThinkPHP 的 think\File、
 * Symfony 的 UploadedFile 恰好都有 isValid()/getRealPath()，但都不是本接口的实现类；
 * 而 Slim/PSR-7 的上传对象连 getRealPath() 都没有，走适配器自己的实现。
 */
class UploadedFile implements UploadedFileInterface
{
    /** @var object */
    private $file;

    public function __construct(object $file)
    {
        $this->file = $file;
    }

    public function isValid(): bool
    {
        return (bool)$this->file->isValid();
    }

    public function getRealPath(): string
    {
        return (string)$this->file->getRealPath();
    }

    /** 取回宿主原始对象（适配器需要读宿主特有属性时用） */
    public function unwrap(): object
    {
        return $this->file;
    }
}
