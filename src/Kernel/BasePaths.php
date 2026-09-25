<?php

namespace AetherUpload\Kernel;

use AetherUpload\Contract\PathsInterface;

/**
 * 「宿主没有目录约定」时的路径端口：基路径显式传进来，两个落点按惯例拼、也可单独覆盖。
 *
 * 默认落点与 webman 侧保持一致，安装分发（把包的 translations/ 与 docs/js/ 拷过来）的脚本
 * 在几种宿主下可以写同一份。
 *
 * 用在 Slim 与原生 PHP 上；有约定的适配器各写各的（webman 从 base_path() 取、
 * Yii 取 @webroot 别名、ThinkPHP 走 app 目录）。
 */
class BasePaths implements PathsInterface
{
    /** 语言文件相对于基路径的落点（与 webman 的 Install 关系一致） */
    const TRANSLATIONS_RELATIVE = '/resource/translations';

    /** 前端资源相对于基路径的落点 */
    const ASSET_RELATIVE = '/public/vendor/aetherupload/js';

    /** @var string */
    private $basePath;

    /** @var string|null 覆盖默认落点 */
    private $translationsPath;

    /** @var string|null */
    private $assetPath;

    public function __construct(string $basePath, ?string $translationsPath = null, ?string $assetPath = null)
    {
        $this->basePath = rtrim($basePath, '/\\');
        $this->translationsPath = $translationsPath;
        $this->assetPath = $assetPath;
    }

    public function basePath(): string
    {
        return $this->basePath;
    }

    public function translationsPath(): string
    {
        return rtrim($this->translationsPath !== null ? $this->translationsPath : $this->basePath . self::TRANSLATIONS_RELATIVE, '/\\');
    }

    public function assetPath(): string
    {
        return rtrim($this->assetPath !== null ? $this->assetPath : $this->basePath . self::ASSET_RELATIVE, '/\\');
    }
}
