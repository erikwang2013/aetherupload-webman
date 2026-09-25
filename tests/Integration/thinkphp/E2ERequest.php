<?php

namespace AetherUpload\Tests\Integration\ThinkPhp;

use think\File;
use think\Request;

/**
 * 进程内构造的请求，只改一处：think\Request::dealUploadFile() 无条件按 $_FILES 的数组形状
 * 取 `$file['name']`（framework 8.1.4 的 Request.php:1122），拿 think\File 实例直接炸
 * 「Cannot use object of type think\file\UploadedFile as array」。
 *
 * 真上传时 `$this->file` 来自 $_FILES，是数组形状，走父类分支；测试进程内没有 $_FILES，
 * 只能塞 File 实例（而且必须 $test=true，因为合成文件过不了 is_uploaded_file()），
 * 于是这里把 File 实例原样透传，其余形状仍交给父类处理。
 */
class E2ERequest extends Request
{
    protected function dealUploadFile(array $files, string $name): array
    {
        $array = [];

        foreach ( $files as $key => $file ) {
            if ( $file instanceof File ) {
                $array[$key] = $file;
            } else {
                $array += parent::dealUploadFile([$key => $file], $name);
            }
        }

        return $array;
    }
}
