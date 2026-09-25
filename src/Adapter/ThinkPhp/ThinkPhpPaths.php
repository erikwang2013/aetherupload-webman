<?php

namespace AetherUpload\Adapter\ThinkPhp;

use AetherUpload\Contract\PathsInterface;
use think\App;

class ThinkPhpPaths implements PathsInterface
{
    /** 资源发布目标（public/ 就是 ThinkPHP 的 web 根目录） */
    const ASSET_RELATIVE = '/public/vendor/aetherupload/js';

    /**
     * 语言文件根目录：内核在其后拼 aetherupload/，最终 app/lang/aetherupload/<locale>/messages.php。
     *
     * 取 ThinkPHP 单应用模式的语言目录 app/lang（Laravel 侧同理落在 lang/aetherupload/），
     * 而不是 webman 的 resource/translations —— 那个只对 webman 有意义。
     * 落点由 aetherupload:publish 写，两边必须一致。
     */
    const TRANSLATIONS_RELATIVE = '/app/lang';

    /** @var App|null */
    private $app;

    public function __construct(?App $app = null)
    {
        $this->app = $app;
    }

    public function basePath(): string
    {
        // think\App 的 rootPath 自带尾部分隔符；没有 app（纯 CLI 安装脚本）时回落到 cwd
        $root = $this->app !== null ? $this->app->getRootPath() : getcwd();

        return rtrim((string)$root, '/\\');
    }

    public function translationsPath(): string
    {
        return $this->basePath() . self::TRANSLATIONS_RELATIVE;
    }

    public function assetPath(): string
    {
        return $this->basePath() . self::ASSET_RELATIVE;
    }
}
