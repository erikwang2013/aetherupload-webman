<?php

namespace AetherUpload\Adapter\Slim;

use AetherUpload\Contract\PathsInterface;

/**
 * 路径端口。Slim 没有约定目录，基路径由使用者在 Bootstrap 里显式传入（`__DIR__` 或 `getcwd()`）。
 *
 * 默认的翻译/资源落点与 webman 侧保持一致，安装分发（把包的 translations/ 与 docs/js/ 拷过来）
 * 的脚本在两种宿主下可以写同一份。
 */
class SlimPaths implements PathsInterface
{
    /** 语言文件相对于基路径的落点（与 webman 的 Install 关系一致） */
    const TRANSLATIONS_RELATIVE = '/resource/translations';

    /** 前端资源相对于基路径的落点（Slim 的 web 根目录惯例是 public/） */
    const ASSET_RELATIVE = '/public/vendor/aetherupload/js';

    /** @var string */
    private $basePath;

    /** @var string|null 覆盖默认落点（Bootstrap 的 translations_path / asset_path 选项） */
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
