<?php

namespace AetherUpload\Adapter\Native;

use AetherUpload\Contract\UploadedFileInterface;
use RuntimeException;

/**
 * `$_FILES` 里的一项 → 内核上传端口。
 *
 * 原生 PHP 是六个宿主的参照实现本身：PHP 把上传落到一个真实的临时文件（`tmp_name`），
 * `getRealPath()` 直接把那个路径交出去，**不需要任何拷贝** —— PSR-7 那套「流可能没有本地 uri」
 * 的问题在这里根本不存在。
 *
 * 生命周期由 PHP 自己管：请求结束时 PHP 会删掉 tmp_name 指向的文件，适配器不登记、不清理。
 */
class NativeUploadedFile implements UploadedFileInterface
{
    /** @var int UPLOAD_ERR_* */
    private $error;

    /** @var string */
    private $tmpName;

    public function __construct(int $error, string $tmpName)
    {
        $this->error = $error;
        $this->tmpName = $tmpName;
    }

    public function isValid(): bool
    {
        return $this->error === UPLOAD_ERR_OK;
    }

    /**
     * 服务端临时文件路径（PHP 已经保证它是个真实文件，除非 error != UPLOAD_ERR_OK）。
     */
    public function getRealPath(): string
    {
        if ( $this->tmpName === '' ) {
            throw new RuntimeException('AetherUpload: 上载项没有可用的临时文件路径');
        }

        return $this->tmpName;
    }
}
