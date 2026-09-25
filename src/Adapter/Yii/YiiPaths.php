<?php

namespace AetherUpload\Adapter\Yii;

use AetherUpload\Contract\PathsInterface;
use RuntimeException;
use Yii;

/**
 * 路径端口：三处路径全部锚在 Yii 的项目根（@app）上。
 *
 * 内核其余位置拼出来的相对路径（root_dir 等）都会再经 Runtime::basePath() 接上前缀，
 * 因此这里必须返回**绝对**路径，且与宿主实际部署目录一致。
 */
class YiiPaths implements PathsInterface
{
    /**
     * 前端资源发布目标：**宿主文档根**下的 vendor/aetherupload/js。
     *
     * 路径锚在文档根而不是项目根，是因为示例页引用的是绝对路径
     * /vendor/aetherupload/js/aetherupload-all.js —— 与 webman 的 public/vendor/aetherupload/js
     * 是同一个语义（webman 的文档根就是 public/，Yii 的是 web/）。
     */
    const ASSET_RELATIVE = '/vendor/aetherupload/js';

    /** 翻译根目录：沿用 Yii 的 messages 惯例，插件语言文件落在其下的 aetherupload/ */
    const TRANSLATIONS_RELATIVE = '/messages';

    public function basePath(): string
    {
        return rtrim($this->app()->getBasePath(), '/\\');
    }

    /**
     * 语言文件根目录。
     *
     * 内核会再拼上 /aetherupload，因此最终目录是 <app>/messages/aetherupload ——
     * 正是 aetherupload:publish 与 YiiTranslator::loadMessages() 约定的位置。
     */
    public function translationsPath(): string
    {
        return $this->basePath() . self::TRANSLATIONS_RELATIVE;
    }

    public function assetPath(): string
    {
        return $this->webRoot() . self::ASSET_RELATIVE;
    }

    /**
     * 文档根：优先用宿主声明的 @webroot（Yii 的 web 应用会自动从入口脚本路径推导出来，
     * 文档根叫 public/ 之类的项目也会被它覆盖到）；控制台应用没有这一步，
     * 退回 Yii 的默认约定 <app>/web —— 文档根非 web 的项目请在 console 配置里补 @webroot 别名。
     */
    private function webRoot(): string
    {
        $webroot = Yii::getAlias('@webroot', false);

        return is_string($webroot) && $webroot !== ''
            ? rtrim($webroot, '/\\')
            : $this->basePath() . '/web';
    }

    /**
     * Yii 应用的 basePath 只在应用实例上，没有全局函数可退。
     *
     * 正常路径不会走到异常分支：适配器的绑定点是应用 bootstrap，那一刻 Yii::$app 一定已就位。
     * 走到这里说明有人在应用创建前就用了内核（例如自己写脚本 require 了 src/），
     * 给出可执行的提示比抛出「Call to a member function on null」有用。
     */
    private function app(): \yii\base\Application
    {
        if ( Yii::$app === null ) {
            throw new RuntimeException(
                'AetherUpload: Yii 应用尚未创建。请在应用配置的 bootstrap 数组里注册 '
                . 'AetherUpload\\Adapter\\Yii\\Bootstrap（它负责把 Runtime 绑到本适配器）。'
            );
        }

        return Yii::$app;
    }
}
