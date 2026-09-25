<?php

namespace AetherUpload\Adapter\Symfony;

use AetherUpload\Contract\TranslatorInterface;
use Symfony\Contracts\Translation\LocaleAwareInterface;
use Symfony\Contracts\Translation\TranslatorInterface as SymfonyTranslatorInterface;
use WeakMap;

/**
 * Symfony 翻译端口。
 *
 * 未命中时 Symfony 的 Translator 会**原样返回 id**，与内核契约天然一致，
 * 因此这里不需要像 Laravel 那样做回退处理（Laravel 的 domain 形式会返回 aetherupload::messages.x）。
 */
class SymfonyTranslator implements TranslatorInterface
{
    /** 语言文件的域：translations/<locale>/messages.php */
    const DOMAIN = 'messages';

    /**
     * 已登记过的语言文件，按 translator 实例隔离（键是实例，值是 "文件|语种" 集合）。
     *
     * 不能只按「文件|语种」做进程级幂等：Symfony 的 translator 是服务，容器每重建一次
     * （长驻 worker 换请求、测试客户端 reboot、php-fpm 换进程）就换一个新实例，而新实例的
     * resources/catalogues 是空的 —— 沿用旧实例的登记记录会让第二个请求的翻译直接失效
     * （未命中返回 key，错误消息变成 upload_error 这种字面键）。
     * WeakMap 按对象身份判断，实例被回收时记录一起消失，也不会有 id 复用问题。
     */
    private static $registered;

    /** @var SymfonyTranslatorInterface|null */
    private $translator;

    public function __construct(?SymfonyTranslatorInterface $translator)
    {
        $this->translator = $translator;
    }

    public function trans(string $key): string
    {
        if ( $this->translator === null ) {
            return $key;
        }

        // 先问「当前语种的 catalogue 自己有没有这个 key」，命中才去取译文。
        // 不能只看 trans() 的返回值：framework.translator.fallbacks 给空数组是**无效**的
        // （FrameworkExtension 里写的是 `$config['fallbacks'] ?: [$defaultLocale]`），
        // Symfony 永远有默认语种兜底 —— 未登记的语种会拿到 en 的译文，而内核契约要求未命中返回 key 本身。
        // MessageCatalogue::defines() 只看本 catalogue（不含 fallback 链），has() 才会带上 fallback。
        //
        // 用 method_exists 而不是 instanceof TranslatorBagInterface：这个接口在
        // Symfony\Component\Translation 下（contracts 包里没有），而 debug 下的
        // DataCollectorTranslator / LoggingTranslator 装饰器不一定实现它，但它们都能转发 getCatalogue。
        if ( method_exists($this->translator, 'getCatalogue') ) {
            $catalogue = $this->translator->getCatalogue($this->getLocale());

            if ( ! $catalogue->defines($key, self::DOMAIN) ) {
                return $key;
            }
        }

        $result = $this->translator->trans($key, [], self::DOMAIN);

        // 再兜一次：宿主换成自定义翻译器后可能返回空串
        return is_string($result) && $result !== '' ? $result : $key;
    }

    public function setLocale(string $locale): void
    {
        if ( $this->translator instanceof LocaleAwareInterface ) {
            $this->translator->setLocale($locale);
        }
    }

    public function getLocale(): string
    {
        if ( $this->translator instanceof LocaleAwareInterface ) {
            $current = $this->translator->getLocale();

            if ( is_string($current) && $current !== '' ) {
                return $current;
            }
        }

        return 'en';
    }

    /**
     * 登记 {directory}/{locale}/messages.php。
     *
     * 同一 translator 实例内幂等（boot() 登记一次，UploadController 构造函数每请求再登记一次不会重复）。
     *
     * debug 环境下 `translator` 服务会被 DataCollectorTranslator / LoggingTranslator 包一层，
     * 这两个装饰器用 __call 转发 addResource，因此这里直接用方法调用即可。
     */
    public function loadMessages(string $directory, string $locale): void
    {
        if ( $this->translator === null ) {
            return;
        }

        $file = rtrim($directory, '/\\') . DIRECTORY_SEPARATOR . $locale . DIRECTORY_SEPARATOR . self::DOMAIN . '.php';

        if ( ! is_file($file) ) {
            return;
        }

        if ( self::$registered === null ) {
            self::$registered = new WeakMap();
        }

        $marker = $file . '|' . $locale;
        $done = self::$registered[$this->translator] ?? [];

        if ( isset($done[$marker]) ) {
            return;
        }

        if ( ! method_exists($this->translator, 'addResource') && ! method_exists($this->translator, '__call') ) {
            return; // 宿主翻译器不支持登记（如 IdentityTranslator）：trans() 会退化为返回 key
        }

        $this->translator->addResource('php', $file, $locale, self::DOMAIN);

        $done[$marker] = true;
        self::$registered[$this->translator] = $done;
    }
}
