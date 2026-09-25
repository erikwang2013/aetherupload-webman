<?php

namespace AetherUpload;

/**
 * 单次请求 / 单个协程的可变状态。
 *
 * 这里存放的状态**绝不能**挂在进程级的 Runtime 或适配器上：
 * webman 常驻进程与 Hyperf 协程会在同一进程内交错处理多个请求，
 * 任何进程级可变状态都会被下一个请求覆盖（ConfigMapper 的旧实现正是栽在这里）。
 */
final class RequestContext
{
    /** 本次上下文的配置快照（applyGroupConfig 的结果落在这里，不落回进程级单例） */
    public $configMapper;

    /** @var array<string,bool> 已登记的语言文件，键为 "目录|语种" */
    public $loadedMessages = [];

    /** 本次上下文的语种 */
    public $locale = 'en';
}
