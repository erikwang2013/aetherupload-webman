<?php

namespace AetherUpload\Contract;

interface EventDispatcherInterface
{
    /**
     * 同步派发上传生命周期事件（aetherupload.before_upload_complete / upload_complete）。
     *
     * 硬性语义：**监听器抛出的异常绝不允许冒泡到上传响应**。
     * webman 的实现会吞掉并记日志；Laravel、Symfony、Yii、ThinkPHP、Hyperf 默认都会冒泡，
     * 因此各适配器必须在派发处 catch 后交给宿主的 logger。
     *
     * @param string $name    事件名
     * @param mixed  $payload 载荷（PartialResource 或 Resource 实例）
     */
    public function emit(string $name, $payload): void;
}
