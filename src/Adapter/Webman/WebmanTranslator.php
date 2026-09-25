<?php

namespace AetherUpload\Adapter\Webman;

use AetherUpload\Contract\TranslatorInterface;
use support\Translation;

class WebmanTranslator implements TranslatorInterface
{
    public function trans(string $key): string
    {
        $result = trans($key);

        // 兜住契约：未命中必须返回 key 本身（webman 的 trans() 在未命中时已返回 id，
        // 这里再判一次是为了防止宿主自定义翻译器后返回空串或数组）
        return is_string($result) && $result !== '' ? $result : $key;
    }

    public function setLocale(string $locale): void
    {
        locale($locale);
    }

    public function getLocale(): string
    {
        $current = locale();

        return is_string($current) ? $current : 'en';
    }

    /**
     * 登记 {directory}/{locale}/messages.php。
     *
     * 注意：此处**不做**进程级幂等去重。触发点目前仍在 UploadController 的构造函数里
     * （逐请求登记），若在此处去重，依赖「构造一次 ⇒ 恰好登记 2 条」的既有断言会变成
     * 顺序相关的偶发失败。待触发点迁到适配器启动点后再实现幂等。
     */
    public function loadMessages(string $directory, string $locale): void
    {
        Translation::addResource('phpfile', $directory . '/' . $locale . '/messages.php', $locale);
    }
}
