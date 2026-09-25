<?php

namespace AetherUpload\Kernel;

use AetherUpload\Contract\TranslatorInterface;
use AetherUpload\Runtime;

/**
 * 「宿主没有翻译组件」时的翻译端口实现：直接读 `{目录}/{语种}/messages.php`
 * （就是本包 translations/ 的结构），不引入任何第三方翻译引擎。
 *
 * 用在两个适配器上：Slim（无翻译组件）与 Native（无宿主）。其余框架各有自己的翻译器实现，
 * 因为它们的语义差别恰恰是内核依赖的那两点（未命中返回什么、语种存哪）。
 *
 * 两条硬性语义：
 *  - **未命中返回 key 本身**。UploadController::fail() 靠「异常消息 == 翻译 key」判定错误能否对外暴露；
 *    这里刻意**不做 fallback**（不回落 en）：命中不了就是未命中，集成套件的 [6] 号断言靠这一点成立。
 *  - **setLocale 不得跨请求泄漏**：语种存在 RequestContext 里，执行上下文（请求）一换就复位。
 */
class PhpFileTranslator implements TranslatorInterface
{
    /**
     * 进程级的文件解析缓存：键是 `{目录}/{语种}/messages.php` 的绝对路径。
     * 同一文件只 require 一次，于是 loadMessages() 在常驻进程下重复调用是廉价的幂等操作。
     *
     * @var array<string,array<string,string>>
     */
    private static $files = [];

    /** @var array<string,array<string,string>> 本次上下文已装载的消息表：语种 => 键值 */
    private $messages = [];

    public function trans(string $key): string
    {
        $locale = $this->getLocale();

        $message = isset($this->messages[$locale][$key]) ? $this->messages[$locale][$key] : null;

        return is_string($message) && $message !== '' ? $message : $key;
    }

    public function setLocale(string $locale): void
    {
        // 语种是「本次请求/协程」的状态，必须落在 RequestContext（常驻进程与协程宿主下
        // 挂在适配器上会被下一个请求覆盖）
        Runtime::context()->locale = $locale;
    }

    public function getLocale(): string
    {
        $locale = Runtime::context()->locale;

        return is_string($locale) && $locale !== '' ? $locale : 'en';
    }

    /**
     * 登记 {directory}/{locale}/messages.php。
     *
     * 幂等：同一文件在进程内只解析一次（self::$files），重复调用只是把消息表重新指向缓存。
     * 语种目录不存在时装载空表（trans() 于是返回 key），不抛异常 —— 缺翻译文件不该打断上传。
     */
    public function loadMessages(string $directory, string $locale): void
    {
        $file = rtrim($directory, '/\\') . '/' . $locale . '/messages.php';

        if ( ! isset(self::$files[$file]) ) {
            $messages = is_file($file) ? require $file : [];

            self::$files[$file] = is_array($messages) ? $messages : [];
        }

        $this->messages[$locale] = self::$files[$file];
    }
}
