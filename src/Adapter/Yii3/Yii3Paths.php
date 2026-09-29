<?php

namespace AetherUpload\Adapter\Yii3;

use AetherUpload\Kernel\BasePaths;

/**
 * 路径端口。Yii3 没有目录约定（`@root` 别名只是可选约定，控制器/命令都不依赖它），
 * 基路径由使用者在 Bootstrap::bind() 里显式传入（传 Yii3 的 `$root = dirname(__DIR__)` 最稳）。
 *
 * 实现与落点常量都在 `AetherUpload\Kernel\BasePaths`（原生 PHP 与 Slim 同样是「无目录约定」，三家共用一份）。
 *
 * 默认落点与 webman 侧一致：
 *   translations → {base}/resource/translations/aetherupload
 *   assets       → {base}/public/vendor/aetherupload/js（Yii3 应用模板的文档根就是 public/，
 *                  示例页引用的 /vendor/aetherupload/js/… 正好落在它下面）
 *
 * @see BasePaths
 */
class Yii3Paths extends BasePaths
{
}
