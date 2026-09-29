<?php

namespace AetherUpload\Adapter\Slim;

use AetherUpload\Kernel\Psr7UploadedFile;

/**
 * PSR-7 上传对象 → 内核上传端口（Slim 这一份）。
 *
 * 两条取路径的路（流自带本地 uri 就原样用、没有就落盘成临时文件并登记清理）与实测证据都在
 * `AetherUpload\Kernel\Psr7UploadedFile`：Yii3 适配器的上传对象同样是 PSR-7，
 * 两边共用一份实现。
 *
 * 保留本类名有两个实际原因：既有用例 `new SlimUploadedFile(...)` 直接用这个名字，
 * `SlimRequest::createUploadedFile()` 也返回它。
 *
 * @see Psr7UploadedFile
 */
class SlimUploadedFile extends Psr7UploadedFile
{
}
