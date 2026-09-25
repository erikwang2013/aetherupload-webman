<?php

namespace AetherUpload\Adapter\Webman;

use AetherUpload\Contract\PathsInterface;

class WebmanPaths implements PathsInterface
{
    /** 资源发布目标（Install 的 pathRelation 与示例页 URL 都依赖这个位置） */
    const ASSET_RELATIVE = '/public/vendor/aetherupload/js';

    const TRANSLATIONS_FALLBACK = '/resource/translations';

    public function basePath(): string
    {
        return rtrim(base_path(), '/\\');
    }

    /**
     * 宿主的翻译根目录；插件自己的语言文件位于其下的 aetherupload/ 子目录。
     *
     * 宿主若把 translation.path 配成数组（webman 内部用 (array) 转换，暗示这是合法形态），
     * 这里回落到默认值，避免拼接出 "Array/zh/messages.php" 这种路径。
     */
    public function translationsPath(): string
    {
        $path = null;

        if ( function_exists('config') ) {
            $translation = config('translation');
            if ( is_array($translation) && isset($translation['path']) ) {
                $path = $translation['path'];
            }
        }

        if ( ! is_string($path) || $path === '' ) {
            $path = $this->basePath() . self::TRANSLATIONS_FALLBACK;
        }

        return rtrim($path, '/\\');
    }

    public function assetPath(): string
    {
        return $this->basePath() . self::ASSET_RELATIVE;
    }
}
