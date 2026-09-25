<?php

namespace AetherUpload\Adapter\ThinkPhp\Response;

use think\response\File;

/**
 * 直接复用 think\response\File 的 MIME 探测与 Content-Disposition 拼装
 * （它按 urlencode 处理中文名），只补一个内核要的 withHeader()。
 */
class FileResponse extends File
{
    use WithHeader;
}
