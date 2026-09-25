<?php

namespace AetherUpload\Adapter\Slim;

use AetherUpload\Kernel\PhpFileTranslator;

/**
 * Slim 没有翻译组件，语义与实现都在 Kernel\PhpFileTranslator 里（直读 `{目录}/{语种}/messages.php`）。
 *
 * 本类保留为 Slim 侧的名字与扩展点：行为一字未改，只是把实现挪到了内核侧，
 * 好让同样「没有宿主翻译器」的 Native 适配器共用同一份 —— 两处各写一遍，
 * 「未命中返回 key」这条内核依赖的契约就有两个可能漂移的实现。
 *
 * @see PhpFileTranslator
 */
class SlimTranslator extends PhpFileTranslator
{
}
