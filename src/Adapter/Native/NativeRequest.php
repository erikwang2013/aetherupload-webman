<?php

namespace AetherUpload\Adapter\Native;

use AetherUpload\Contract\RequestInterface;
use AetherUpload\Contract\UploadedFileInterface;

/**
 * SAPI 超全局（`$_GET` / `$_POST` / `$_FILES`）→ 内核请求端口。
 *
 * **字段读取严格返回原始值**：PHP 不会过滤 `$_POST`，内核的类型守卫正是靠它区分
 * 「攻击性输入（`resource_name[]=1` 这种数组）」与「合法 JSON 数值」，任何转换都会让它失效。
 *
 * 超全局按请求由 PHP 重新填充（FPM、`php -S`、CLI 都一样），所以本类不缓存输入；
 * 只有 `$_FILES` 的端口对象要缓存 —— 内核可能在一次请求里多次取同一个字段，
 * 每次新建对象会让「同一个上传项」在身份上不成立（Slim 侧同样的理由）。
 *
 * 上下文身份：原生 PHP 的经典模型是「一请求一进程」，但 `php -S` 与常驻 worker
 * （RoadRunner / Swoole / ReactPHP）会在同一进程里连续处理多个请求，
 * 因此仍按请求发一个新 token，让 `Runtime::context()` 换一份 RequestContext
 * （否则上一个请求的语种、分组配置快照会漏到下一个请求）。
 */
class NativeRequest implements RequestInterface
{
    /** @var array<string,NativeUploadedFile> 字段名 → 端口对象（同一请求内复用） */
    private $files = [];

    /** @var object|null 本次请求的上下文身份；CLI 下为 null（退化为进程级单上下文） */
    private $token;

    /** 每请求开始（Bootstrap::handle() 调用）：换身份、丢弃上一个请求的文件对象 */
    public function begin(): void
    {
        $this->token = new \stdClass();
        $this->files = [];
    }

    /** 每请求结束：丢掉身份，回到 CLI 的进程级上下文 */
    public function end(): void
    {
        $this->token = null;
        $this->files = [];
    }

    /**
     * 上下文身份（AdapterInterface::contextToken() 的返回值）。
     *
     * @return object|null
     */
    public function token()
    {
        return $this->token;
    }

    /**
     * @param mixed $default
     * @return mixed
     */
    public function input(string $key, $default = null)
    {
        // 与 webman 的 Request::input() 同序：表单体优先，其次查询串。
        // 用 array_key_exists 而非 isset：值为 null 也算「有」这个键
        if ( array_key_exists($key, $_POST) ) {
            return $_POST[$key];
        }

        return array_key_exists($key, $_GET) ? $_GET[$key] : $default;
    }

    public function file(string $key): ?UploadedFileInterface
    {
        if ( isset($this->files[$key]) ) {
            return $this->files[$key];
        }

        $entry = isset($_FILES[$key]) && is_array($_FILES[$key]) ? $_FILES[$key] : null;

        if ( $entry === null || ! isset($entry['error'], $entry['tmp_name']) ) {
            return null;
        }

        // 同名多文件（resource_chunk[]=…）在 $_FILES 里是并行数组，内核只认单文件，按缺失处理
        if ( is_array($entry['tmp_name']) || is_array($entry['error']) ) {
            return null;
        }

        return $this->files[$key] = new NativeUploadedFile((int)$entry['error'], (string)$entry['tmp_name']);
    }

    public function all(): array
    {
        return array_merge($_GET, $_POST);
    }
}
