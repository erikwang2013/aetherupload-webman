<?php

namespace AetherUpload\Adapter\Slim;

use AetherUpload\Kernel\BasePaths;

/**
 * 路径端口。Slim 没有约定目录，基路径由使用者在 Bootstrap 里显式传入（`__DIR__` 或 `getcwd()`）。
 *
 * 实现与落点常量都在 `AetherUpload\Kernel\BasePaths`（原生 PHP 适配器同样是「无目录约定」，
 * 两者共用一份）。保留本类名是为不破坏已有引用与 Slim 侧文档。
 *
 * @see BasePaths
 */
class SlimPaths extends BasePaths
{
}
