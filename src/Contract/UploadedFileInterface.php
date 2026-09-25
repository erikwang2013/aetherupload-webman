<?php

namespace AetherUpload\Contract;

/**
 * 上传文件端口 —— 刻意只保留内核真正用到的两个能力。
 *
 * 六框架里 Symfony 的 UploadedFile 与 ThinkPHP 的 think\File 天然同时具备这两个方法；
 * Slim/PSR-7 的上传对象没有 getRealPath()，由适配器自己落盘后再给出路径。
 */
interface UploadedFileInterface
{
    /** 上传是否成功（对应 UPLOAD_ERR_OK） */
    public function isValid(): bool;

    /** 服务端本地可读的绝对路径（分块内容从这里被追加进 .part） */
    public function getRealPath(): string;
}
