<?php

namespace AetherUpload\Adapter\Laravel;

use AetherUpload\Contract\PathsInterface;

class LaravelPaths implements PathsInterface
{
    /** 资源发布目标（ServiceProvider 的 publishes 键与示例页 URL 都依赖这个位置） */
    const ASSET_RELATIVE = '/public/vendor/aetherupload/js';

    public function basePath(): string
    {
        return rtrim(base_path(), '/\\');
    }

    /**
     * 翻译根目录。内核会在其后拼上 aetherupload/，最终形如 <lang>/aetherupload/<locale>/messages.php，
     * 与 ServiceProvider 里 publishes 的落点一致（vendor:publish --tag=aetherupload-translations）。
     */
    public function translationsPath(): string
    {
        return rtrim(lang_path(), '/\\');
    }

    public function assetPath(): string
    {
        return $this->basePath() . self::ASSET_RELATIVE;
    }
}
