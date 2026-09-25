<?php

namespace AetherUpload\Adapter\Symfony;

use AetherUpload\Contract\PathsInterface;

class SymfonyPaths implements PathsInterface
{
    /** 资源发布目标（示例页的 <script> 引用与 aetherupload:publish 都落在这里） */
    const ASSET_RELATIVE = '/public/vendor/aetherupload/js';

    /** Symfony 约定的翻译目录 */
    const TRANSLATIONS_RELATIVE = '/translations';

    /** @var string */
    private $projectDir;

    public function __construct(string $projectDir)
    {
        $this->projectDir = $projectDir;
    }

    public function basePath(): string
    {
        return rtrim($this->projectDir, '/\\');
    }

    /** 插件自己的语言文件在其下的 aetherupload/ 子目录：translations/aetherupload/<locale>/messages.php */
    public function translationsPath(): string
    {
        return $this->basePath() . self::TRANSLATIONS_RELATIVE;
    }

    public function assetPath(): string
    {
        return $this->basePath() . self::ASSET_RELATIVE;
    }
}
