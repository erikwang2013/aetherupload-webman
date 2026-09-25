<?php

namespace AetherUpload\Adapter\Hyperf;

use AetherUpload\Contract\PathsInterface;

class HyperfPaths implements PathsInterface
{
    /** 资源发布目标（示例页与 Install 的分发路径都依赖这个位置） */
    const ASSET_RELATIVE = '/public/vendor/aetherupload/js';

    /** 语言文件发布目标；内核会在其下追加 aetherupload/ 再找 <locale>/messages.php */
    const TRANSLATIONS_RELATIVE = '/storage/translations';

    /** 本包的安装根目录（发布配置/语言文件/前端资源时的源） */
    public static function packagePath(): string
    {
        return dirname(__DIR__, 3);
    }

    public function basePath(): string
    {
        // 超全局常量由 bin/hyperf.php 定义；测试进程里可能没有，回落到 cwd 而不是报错
        if ( defined('BASE_PATH') ) {
            return rtrim(BASE_PATH, '/\\');
        }

        return rtrim((string)getcwd(), '/\\');
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
