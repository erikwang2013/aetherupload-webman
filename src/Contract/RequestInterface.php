<?php

namespace AetherUpload\Contract;

interface RequestInterface
{
    /**
     * 取输入字段。
     *
     * 硬性语义：**返回原始值，严禁类型转换或过滤**。
     * UploadController 的类型守卫（is_string/is_scalar/ctype_digit）依赖它拿到原样的
     * 数组、整数或字符串，据此区分「攻击性输入」与「合法 JSON 数值」。
     * ThinkPHP 的 input() 默认会过滤、需显式关闭；Symfony 用 Request::get()。
     *
     * @param string $key
     * @param mixed  $default
     * @return mixed
     */
    public function input(string $key, $default = null);

    /** 取上传文件；缺失返回 null。只需 isValid() 与 getRealPath() 两个能力 */
    public function file(string $key): ?UploadedFileInterface;

    /** 全部输入字段（示例页回显用） */
    public function all(): array;
}
