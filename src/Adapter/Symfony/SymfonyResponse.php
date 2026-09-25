<?php

namespace AetherUpload\Adapter\Symfony;

use Symfony\Component\HttpFoundation\Response;

/**
 * 文本/JSON 响应：补上内核唯一依赖的那一个方法。
 *
 * Symfony 的 Response 头是可变对象（headers->set()），没有 withHeader()；
 * 内核（ResourceController）要把 X-Content-Type-Options 等头串在返回值上，
 * 因此这里给一个可变版 withHeader() —— 与 webman/Laravel 的链式语义一致。
 */
class SymfonyResponse extends Response
{
    public function withHeader(string $name, string $value): self
    {
        $this->headers->set($name, $value);

        return $this;
    }
}
