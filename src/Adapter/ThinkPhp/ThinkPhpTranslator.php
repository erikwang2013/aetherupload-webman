<?php

namespace AetherUpload\Adapter\ThinkPhp;

use AetherUpload\Contract\TranslatorInterface;
use think\facade\Lang;

class ThinkPhpTranslator implements TranslatorInterface
{
    public function trans(string $key): string
    {
        // think\Lang::get() 未命中时本身就返回 $name（即 key），这里再判一次是为了兜住
        // 宿主把 lang 换成别的实现、或命中值恰好是空串/非标量的情况
        $result = Lang::get($key);

        return is_string($result) && $result !== '' ? $result : $key;
    }

    public function setLocale(string $locale): void
    {
        // 只切作用域，不触发 switchLangSet 的语言包扫描：内核的 34 处 trans 只需要
        // 我们经 loadMessages 登记过的 messages.php，多扫一遍磁盘没有意义
        Lang::setLangSet($locale);
    }

    public function getLocale(): string
    {
        return Lang::getLangSet();
    }

    /**
     * 登记 {directory}/{locale}/messages.php。
     *
     * 不做进程级去重，与 WebmanTranslator 保持一致：触发点在 UploadController 的构造函数里，
     * 每请求登记两次；think\Lang::load() 的写入是 `$lang + $this->lang[$range]`（已存在的键优先），
     * 重复调用在结果上幂等，只是多 include 一次（有 opcache 兜底）。契约要求的「幂等」由此满足。
     */
    public function loadMessages(string $directory, string $locale): void
    {
        Lang::load($directory . '/' . $locale . '/messages.php', $locale);
    }
}
