<?php

namespace AetherUpload\Adapter\Yii3;

use AetherUpload\Kernel\Psr7Response;

/**
 * 内核响应的可变 shim（Yii3 这一份）。
 *
 * 实现与「为什么不能直接给真 PSR-7」（内核按 webman 的可变响应写，丢弃 `withHeader()` 返回值是常态）
 * 都在 `AetherUpload\Kernel\Psr7Response`。**唯一的转换点**是
 * `AetherUploadController::convert()` —— 必须在那里转：Yii3 的
 * yiisoft/middleware-dispatcher 只接受 `ResponseInterface` 作为 action 的返回值，
 * shim 一旦从 action 返回就会抛 InvalidMiddlewareDefinitionException。
 *
 * @see Psr7Response
 */
class Response extends Psr7Response
{
}
