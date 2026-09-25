<?php

namespace AetherUpload\Adapter\Laravel;

use AetherUpload\Contract\TranslatorInterface;

class LaravelTranslator implements TranslatorInterface
{
    /** 语言文件在 Laravel 翻译器里注册的命名空间 */
    const NAMESPACE_NAME = 'aetherupload';

    /** 语言文件名（<dir>/<locale>/messages.php） */
    const GROUP = 'messages';

    public function trans(string $key): string
    {
        $fullKey = self::NAMESPACE_NAME . '::' . self::GROUP . '.' . $key;

        $result = trans($fullKey);

        // Laravel 未命中时返回的是**完整 id**（aetherupload::messages.upload_error），
        // 而内核 UploadController::fail() 的白名单判定依赖「未命中 == 逻辑 key」，
        // 因此这里必须把完整 id 回退成 $key 本身。
        return is_string($result) && $result !== '' && $result !== $fullKey ? $result : $key;
    }

    public function setLocale(string $locale): void
    {
        app()->setLocale($locale);
    }

    public function getLocale(): string
    {
        $locale = app()->getLocale();

        return is_string($locale) && $locale !== '' ? $locale : 'en';
    }

    /**
     * 把 <directory>/<locale>/messages.php 注册为 aetherupload::messages。
     *
     * Laravel 的 FileLoader 按 <hint>/<locale>/<group>.php 惰性查找，注册命名空间即完成登记；
     * addNamespace 是键值覆盖，重复调用天然幂等（webman 常驻进程下逐请求调用不会重复读盘）。
     */
    public function loadMessages(string $directory, string $locale): void
    {
        // $locale 刻意不参与注册：Laravel 按当前语种自行挑选 <locale> 子目录，
        // 登记一次命名空间即可覆盖所有语种，setLocale() 之后无需重新登记。
        app('translator')->addNamespace(self::NAMESPACE_NAME, $directory);
    }
}
