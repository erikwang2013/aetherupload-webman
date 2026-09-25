<?php

namespace AetherUpload\Adapter\Laravel;

use Illuminate\Http\Response;

/**
 * 给 Laravel 的普通响应补上 withHeader()。
 *
 * 内核只依赖这一个方法（ResourceController::display 用它串 X-Content-Type-Options），
 * 而 Laravel / Symfony 的响应是不可变的：只有改自身的 header()，没有返回新实例的 withHeader()。
 * 这里就地改写并返回 $this —— 与内核「$response = $response->withHeader(...)」的用法等价。
 */
class LaravelResponse extends Response
{
    public function withHeader(string $name, string $value): self
    {
        $this->headers->set($name, $value);

        return $this;
    }
}
