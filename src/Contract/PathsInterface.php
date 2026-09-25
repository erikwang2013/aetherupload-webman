<?php

namespace AetherUpload\Contract;

/**
 * 路径端口。
 *
 * 内核里 16 处 base_path() 全部指向同一个项目根，因此塌缩成一个方法即可；
 * translationsPath/assetPath 保留给安装分发与语言文件定位。
 */
interface PathsInterface
{
    /** 项目根目录绝对路径，无尾部分隔符 */
    public function basePath(): string;

    /** 语言文件目录（安装时把 translations 分发到这里） */
    public function translationsPath(): string;

    /** 前端资源目录（安装时把 docs/js 分发到这里） */
    public function assetPath(): string;
}
