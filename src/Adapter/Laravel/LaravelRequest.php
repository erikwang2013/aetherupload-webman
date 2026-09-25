<?php

namespace AetherUpload\Adapter\Laravel;

use AetherUpload\Contract\RequestInterface;
use AetherUpload\Contract\UploadedFileInterface;
use AetherUpload\Kernel\UploadedFile;
use Illuminate\Http\Request;

class LaravelRequest implements RequestInterface
{
    /**
     * @param mixed $default
     * @return mixed
     */
    public function input(string $key, $default = null)
    {
        $request = $this->current();

        // Request::input() 走 data_get()，返回原始值、不做过滤与类型转换 ——
        // 内核的类型守卫（is_string/is_scalar/ctype_digit）依赖这一点
        return $request === null ? $default : $request->input($key, $default);
    }

    public function file(string $key): ?UploadedFileInterface
    {
        $request = $this->current();

        if ( $request === null ) {
            return null;
        }

        $file = $request->file($key);

        // Illuminate\Http\UploadedFile 继承 Symfony 的 UploadedFile，自带 isValid()/getRealPath()
        return $file === null ? null : new UploadedFile($file);
    }

    public function all(): array
    {
        $request = $this->current();

        return $request === null ? [] : (array)$request->all();
    }

    private function current(): ?Request
    {
        $request = function_exists('request') ? request() : null;

        return $request instanceof Request ? $request : null;
    }
}
