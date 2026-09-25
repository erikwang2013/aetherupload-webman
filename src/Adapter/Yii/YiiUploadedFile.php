<?php

namespace AetherUpload\Adapter\Yii;

use AetherUpload\Contract\UploadedFileInterface;
use yii\web\UploadedFile;

/**
 * yii\web\UploadedFile → 内核上传端口。
 *
 * 不能直接套 Kernel\UploadedFile（那个包装器要求宿主对象有 isValid()/getRealPath()）：
 * Yii 2.0 的 UploadedFile 两个方法都没有，只有公开属性 name/tempName/type/size/error
 * 与 getHasError()/saveAs()。这里按内核的两条需求自己取：
 *
 *   isValid()     → error === UPLOAD_ERR_OK（Yii 只在 error 上判断，不查 is_uploaded_file）
 *   getRealPath() → tempName，即 PHP 落好的临时文件绝对路径，无需再拷贝一份
 */
class YiiUploadedFile implements UploadedFileInterface
{
    /** @var UploadedFile */
    private $file;

    public function __construct(UploadedFile $file)
    {
        $this->file = $file;
    }

    public function isValid(): bool
    {
        return (int)$this->file->error === UPLOAD_ERR_OK;
    }

    public function getRealPath(): string
    {
        return (string)$this->file->tempName;
    }

    /** 取回宿主原始对象（宿主需要读 Yii 特有属性时用） */
    public function unwrap(): UploadedFile
    {
        return $this->file;
    }
}
