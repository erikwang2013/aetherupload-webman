<?php

namespace AetherUpload\Contract;

/**
 * 一个宿主框架对应一个适配器实现。
 *
 * 注册方式：宿主启动点显式调用 Runtime::bind()（不做自动探测 ——
 * function_exists('config') 在 webman 与 Laravel 下同为真，探测必然误判）。
 */
interface AdapterInterface
{
    /** 适配器名，用于诊断输出（webman / laravel / hyperf / thinkphp / yii / slim / symfony） */
    public function name(): string;

    public function config(): ConfigInterface;

    public function translator(): TranslatorInterface;

    public function request(): RequestInterface;

    public function response(): ResponseFactoryInterface;

    public function events(): EventDispatcherInterface;

    public function redis(): RedisInterface;

    public function paths(): PathsInterface;

    public function filesystem(): FilesystemInterface;

    public function contextStore(): ContextStoreInterface;

    /**
     * 当前执行上下文的身份值，仅用于身份比较（判断请求/协程是否已切换），
     * 绝不读取其内容。CLI 场景返回 null，表示退化为进程级静态上下文。
     *
     * @return mixed
     */
    public function contextToken();
}
