<?php

namespace AetherUpload\Adapter\ThinkPhp\Response;

use think\Response;

/**
 * JSON 响应，flags 与另外五个适配器逐字节一致：
 * JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR。
 *
 * 不复用 think\response\Json，因为它写死了 JSON_UNESCAPED_UNICODE、
 * 且构造函数要求注入 Cookie（真跑在 HTTP 下没问题，但内核只需要一个能 withHeader 的对象）。
 */
class JsonResponse extends Response
{
    use WithHeader;

    /** 六个适配器共用的编码 flag，改这里等于改全部 */
    const ENCODE_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;

    protected $contentType = 'application/json';

    public function __construct($data = '', int $code = 200)
    {
        $this->init($data, $code);
    }

    protected function output($data): string
    {
        // json_encode 抛 JsonException（\Exception 的子类），会被内核的 catch (\Exception) 收住
        return json_encode($data, self::ENCODE_FLAGS);
    }
}
