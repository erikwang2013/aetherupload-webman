<?php

namespace AetherUpload\Contract;

interface TranslatorInterface
{
    /**
     * 取翻译文本。
     *
     * 硬性语义：**未命中时必须返回 $key 本身**。
     * UploadController::fail() 依据「异常消息 == 翻译 key」判定错误是否可对外暴露，
     * 宿主翻译器若在未命中时返回空串或完整 id（Laravel 的 domain 形式），这条判定会静默失效。
     */
    public function trans(string $key): string;

    /** 设置本次请求/协程的语种，不得跨请求泄漏 */
    public function setLocale(string $locale): void;

    public function getLocale(): string;

    /**
     * 登记 $directory/$locale/messages.php 这份语言文件。
     * 同一进程内重复调用必须幂等（webman 是常驻进程，构造函数里的重复登记会造成无谓 I/O）。
     */
    public function loadMessages(string $directory, string $locale): void;
}
