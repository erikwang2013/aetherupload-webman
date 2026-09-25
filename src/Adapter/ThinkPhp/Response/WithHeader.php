<?php

namespace AetherUpload\Adapter\ThinkPhp\Response;

/**
 * think\Response 只有合并式的 header(array)，内核要的是 `->withHeader(name, value)`。
 * 这个 trait 给四种响应子类补上同一个方法，返回 $this 以支持链式调用。
 */
trait WithHeader
{
    public function withHeader(string $name, string $value)
    {
        $this->header([$name => $value]);

        return $this;
    }
}
