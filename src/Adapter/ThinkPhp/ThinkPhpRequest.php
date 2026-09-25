<?php

namespace AetherUpload\Adapter\ThinkPhp;

use AetherUpload\Contract\RequestInterface;
use AetherUpload\Contract\UploadedFileInterface;
use AetherUpload\Kernel\UploadedFile;
use think\App;
use think\Request;

class ThinkPhpRequest implements RequestInterface
{
    /** @var App|null */
    private $app;

    public function __construct(?App $app = null)
    {
        $this->app = $app;
    }

    /**
     * 取当前请求对象；没有绑定过 request 时返回 null（CLI、composer 脚本、安装期）。
     *
     * 刻意用「容器里有没有」而不是 make('request') 兜底：think\Request::__make 会从
     * $_SERVER/$_FILES 现造一个，那样每次调用都是新对象，contextToken() 会跟着乱跳。
     */
    public function currentRequest(): ?Request
    {
        if ( $this->app === null || ! $this->app->exists('request') ) {
            return null;
        }

        $request = $this->app->make('request');

        return $request instanceof Request ? $request : null;
    }

    /**
     * @param mixed $default
     * @return mixed
     */
    public function input(string $key, $default = null)
    {
        $request = $this->currentRequest();

        if ( $request === null ) {
            return $default;
        }

        // 第三个参数必须传 null，不能传 ''：think\Request::getFilter() 里
        //     is_null($filter) ? [] : ($filter ?: $this->filter)
        // 空串是假值，会**回落到请求级过滤器**（宿主中间件里一句 $request->filter('htmlspecialchars')
        // 就会让输入变成 &lt;b&gt;…、数组被 array_walk_recursive 逐叶转成字符串）；
        // 只有 null 走的是「过滤器为空数组」这条分支，拿到的一定是原样的数组/整数/字符串。
        // 内核的类型守卫（is_string/is_scalar/ctype_digit）正是靠原值区分「畸形输入」与「合法 JSON 数值」。
        return $request->param($key, $default, null);
    }

    public function file(string $key): ?UploadedFileInterface
    {
        $request = $this->currentRequest();

        if ( $request === null ) {
            return null;
        }

        $file = $request->file($key);

        // 同名字段带 [] 时 ThinkPHP 返回数组：这里回 null（内核据此报 upload_error），不炸 500
        return is_object($file) ? new UploadedFile($file) : null;
    }

    public function all(): array
    {
        $request = $this->currentRequest();

        // 与 input() 同理：第三个参数传 null 才绕开请求级过滤器
        return $request === null ? [] : (array)$request->param('', null, null);
    }
}
