<?php

namespace AetherUpload\Adapter\Hyperf;

use AetherUpload\Contract\TranslatorInterface;
use AetherUpload\Runtime;
use Symfony\Component\Translation\Loader\PhpFileLoader;
use Symfony\Component\Translation\Translator as SymfonyTranslator;

/**
 * 翻译端口。
 *
 * 引擎用 symfony/translation（本包的硬依赖，webman 的 support\Translation 也是它），
 * 而不是 hyperf/translation：后者是 Laravel 翻译器的移植，键名走 `namespace::group.key` 形式、
 * 未命中时返回的是完整 id，与内核「未命中必须返回 key 本身」的契约相冲，适配器里再改回来
 * 反而多一层。语言文件由内核显式 loadMessages() 登记，不需要宿主扫描目录。
 */
class HyperfTranslator implements TranslatorInterface
{
    /** @var SymfonyTranslator|null */
    private $catalogue;

    /** @var array<string,bool> 已登记的语言文件（进程级幂等，键为 "文件|语种"） */
    private $loaded = [];

    public function trans(string $key): string
    {
        $translator = $this->catalogue();

        // 每次取词之前重新对齐语种：Symfony 的 Translator 是进程级单例，而语种属于
        // 单个协程（见 RequestContext）。协程交错时别的协程可能刚改过它，
        // 这里不重新对齐就会读到别人请求的语种。trans() 内部不让出协程，因此这行是有效的。
        $translator->setLocale($this->getLocale());

        $result = $translator->trans($key);

        // 兜住契约：未命中必须返回 key 本身（Symfony 本来就这样，这里再判一次是为了
        // 防止宿主替换实现的 formatter 后返回空串或数组）
        return is_string($result) && $result !== '' ? $result : $key;
    }

    public function setLocale(string $locale): void
    {
        // 语种落在 RequestContext（按请求/协程隔离），不落进程级属性
        Runtime::context()->locale = $locale === '' ? 'en' : $locale;
    }

    public function getLocale(): string
    {
        $locale = Runtime::context()->locale;

        return is_string($locale) && $locale !== '' ? $locale : 'en';
    }

    /**
     * 登记 {directory}/{locale}/messages.php。
     *
     * 进程级幂等：Hyperf 是常驻进程，UploadController 的构造函数逐请求登记，
     * 不去重的话每个请求都要重新解析一遍语言文件。
     */
    public function loadMessages(string $directory, string $locale): void
    {
        $file = $directory . DIRECTORY_SEPARATOR . $locale . DIRECTORY_SEPARATOR . 'messages.php';
        $id = $file . '|' . $locale;

        if ( isset($this->loaded[$id]) ) {
            return;
        }

        $this->loaded[$id] = true;

        if ( ! is_file($file) ) {
            // 未执行 aetherupload:publish 时语言文件不在宿主里：静默降级为「返回 key」，
            // 与宿主完全没装翻译资源时的行为一致，不抛异常打断上传
            return;
        }

        $this->catalogue()->addResource('php', $file, $locale);
    }

    private function catalogue(): SymfonyTranslator
    {
        if ( $this->catalogue === null ) {
            $translator = new SymfonyTranslator('en');

            // 裸的 Symfony Translator 一个 loader 都不注册，addResource('php', …) 只登记不加载，
            // 到第一次取词时才抛「No loader is registered for the "php" format」。
            // 宿主（webman 的 support\Translation、Laravel、Symfony 全栈）都是在容器里补这一步的。
            $translator->addLoader('php', new PhpFileLoader());

            // 未命中必须真的未命中：留了 fallback 就会拿 en 的译文冒充，第 6 条探针就测不到了
            $translator->setFallbackLocales([]);

            $this->catalogue = $translator;
        }

        return $this->catalogue;
    }
}
