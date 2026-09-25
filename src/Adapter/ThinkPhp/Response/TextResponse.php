<?php

namespace AetherUpload\Adapter\ThinkPhp\Response;

use think\Response;

/**
 * text/plain 响应（内核 404 分支与 x_accel_redirect 的空体载体用）。
 *
 * ThinkPHP 没有内置的纯文本响应（think\response\Html 是 text/html），
 * 而内核三处 text() 分别承载 'display fail' / 'download fail' / X-Accel-Redirect 空体，
 * 拿 HTML 顶替会在浏览器里被当成文档渲染。
 */
class TextResponse extends Response
{
    use WithHeader;

    protected $contentType = 'text/plain';

    public function __construct($data = '', int $code = 200)
    {
        $this->init($data, $code);
    }
}
