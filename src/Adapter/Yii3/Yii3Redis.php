<?php

namespace AetherUpload\Adapter\Yii3;

use AetherUpload\Kernel\ClientRedis;

/**
 * Redis 端口：把命令原样转给宿主给的客户端（phpredis 的 \Redis 或 Predis\Client）。
 *
 * Yii3 内核里没有 redis 组件（缓存/队列都在 yiisoft/cache-redis 这类单独包里，且不与本包同代），
 * 所以客户端由使用者交进来 —— `'redis' => static fn () => $container->get(...)`。
 *
 * 实现与「返回值一律不归一化」的理由都在 `AetherUpload\Kernel\ClientRedis`（Slim 与原生 PHP 同此）。
 *
 * @see ClientRedis
 */
class Yii3Redis extends ClientRedis
{
}
