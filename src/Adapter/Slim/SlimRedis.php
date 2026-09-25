<?php

namespace AetherUpload\Adapter\Slim;

use AetherUpload\Kernel\ClientRedis;

/**
 * Redis 端口：把命令原样转给宿主给的客户端（phpredis 的 \Redis 或 Predis\Client）。
 *
 * 实现与说明都在 `AetherUpload\Kernel\ClientRedis`（Slim 没有容器取服务的约定，
 * 原生 PHP 适配器同样是「使用者直接交客户端进来」，两者共用一份）。保留本类名是为不破坏已有引用。
 *
 * @see ClientRedis
 */
class SlimRedis extends ClientRedis
{
}
