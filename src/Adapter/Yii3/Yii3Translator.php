<?php

namespace AetherUpload\Adapter\Yii3;

use AetherUpload\Kernel\PhpFileTranslator;

/**
 * 翻译端口：直读 `{目录}/{语种}/messages.php`（内核给的目录是 `translationsPath()/aetherupload`）。
 *
 * 为什么不接 `yiisoft/translator`：那要求宿主在自己的配置里为本包登记 category 与 message source
 * （多一处必须配对成功的配置），而本包的语言文件是随 `aetherupload:publish` 发到应用里的静态文件 ——
 * 直读零配置、且「未命中返回 key 本身」这条内核依赖的契约只有一份实现（Slim / 原生 PHP 同此）。
 * 宿主若想统一走 yiisoft/translator，把本类换成自己的实现即可（契约就是 Contract\TranslatorInterface）。
 *
 * @see PhpFileTranslator
 */
class Yii3Translator extends PhpFileTranslator
{
}
