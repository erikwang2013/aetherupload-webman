<?php

namespace AetherUpload\Adapter\Yii;

use AetherUpload\Contract\TranslatorInterface;
use AetherUpload\Runtime;
use Yii;
use yii\i18n\PhpMessageSource;

/**
 * 翻译端口：把内核的「逻辑 key」映射到 Yii 的 message category `aetherupload`。
 *
 * 语种存在 Yii::$app->language 上（Yii 每个请求一个应用实例，天然不跨请求泄漏），
 * 宿主自己调 Yii::t('aetherupload', ...) 时拿到的也是同一份译文。
 */
class YiiTranslator implements TranslatorInterface
{
    /** 消息分类：与 messages.php 的文件名对应（见 loadMessages 的 fileMap） */
    const CATEGORY = 'aetherupload';

    /** 分类的匹配模式，支持 aetherupload 与 aetherupload* 两种写法 */
    const CATEGORY_PATTERN = 'aetherupload*';

    /** 源语言：messages.php 里的文案是英文，目录名也是 en */
    const SOURCE_LANGUAGE = 'en';

    public function trans(string $key): string
    {
        $result = Yii::t(self::CATEGORY, $key);

        // 兜住契约：未命中必须返回 key 本身。PhpMessageSource 找不到对应语种时返回 false、
        // I18N 随即回落成 $message（即 key），这里再判一次是防止宿主自定义 message source 后返回空串
        return is_string($result) && $result !== '' ? $result : $key;
    }

    public function setLocale(string $locale): void
    {
        if ( Yii::$app !== null && $locale !== '' ) {
            Yii::$app->language = $locale;
        }
    }

    public function getLocale(): string
    {
        $language = Yii::$app === null ? null : Yii::$app->language;

        return is_string($language) && $language !== '' ? $language : 'en';
    }

    /**
     * 登记 {directory}/{locale}/messages.php。
     *
     * 参数来自内核：translationsPath() . '/aetherupload'，正好可以作为 PhpMessageSource 的
     * basePath（它按 basePath/{language}/{fileMap[category]} 查找）。
     *
     * 幂等：目录已登记过就不再动，避免逐请求重建 message source（Yii 常驻进程下同样的开销）
     * 以及覆盖宿主自己的同名配置。宿主若已配好 aetherupload* 分类，这里一律不碰。
     */
    public function loadMessages(string $directory, string $locale): void
    {
        $context = Runtime::context();
        $id = $directory . '|' . $locale;

        if ( isset($context->loadedMessages[$id]) ) {
            return;
        }

        $context->loadedMessages[$id] = true;

        $i18n = Yii::$app === null ? null : Yii::$app->getI18n();

        if ( $i18n === null || $this->hostConfiguresCategory($i18n) ) {
            return;
        }

        $i18n->translations[self::CATEGORY_PATTERN] = [
            'class'          => PhpMessageSource::class,
            'basePath'       => $directory,
            'sourceLanguage' => self::SOURCE_LANGUAGE,
            'fileMap'        => [self::CATEGORY => 'messages.php'],
            // 必须开着：MessageSource::translate() 在 $language === sourceLanguage 时直接返回 false
            // 连语言文件都不读，而 Yii 应用的默认 language 就是英文系（'en-US'）。关掉它，
            // 语言为 'en' 或 'en-US' 时英文文案会整片退化成 key（第 5 条断言就会红）。
            // 开着之后由「文件在不在」决定：en/en-US 命中 en/messages.php，
            // 未发布的语种（如测试用的 zz）查不到文件 → 返回 false → 上游回落到 key 本身。
            'forceTranslation' => true,
        ];
    }

    /**
     * 宿主是否已经为 aetherupload 分类配过 message source。
     *
     * 用与 I18N::getMessageSource() 相同的匹配规则（前缀匹配，不是通配符匹配）：
     * 配过就让宿主说了算，避免把使用者的自定义翻译目录顶掉。
     */
    private function hostConfiguresCategory($i18n): bool
    {
        foreach ( array_keys((array)$i18n->translations) as $pattern ) {
            $pattern = (string)$pattern;

            if ( $pattern === self::CATEGORY || $pattern === '*' ) {
                return true;
            }

            if ( strpos($pattern, '*') > 0 && strpos(self::CATEGORY, rtrim($pattern, '*')) === 0 ) {
                return true;
            }
        }

        return false;
    }
}
