<?php

namespace AetherUpload\Adapter\Yii;

use AetherUpload\Contract\EventDispatcherInterface;
use Throwable;
use Yii;
use yii\base\Event;

class YiiEvents implements EventDispatcherInterface
{
    /** 异常落到 Yii 日志里的分类名 */
    const LOG_CATEGORY = 'aetherupload';

    /**
     * @param mixed $payload
     */
    public function emit(string $name, $payload): void
    {
        try {
            // 传对象而不是类名字符串：payload 会成为事件的 sender，监听器照常写
            // function ($event) { $event->sender ... } 即可。
            // 不用 Event 的 data 字段传递 payload —— Event::trigger() 会用 $handler[1]
            // 覆盖 data，一句 $event->data = $payload 在多个监听器下必然是错的。
            Event::trigger($payload, $name);
        } catch ( Throwable $e ) {
            // 契约的硬性语义：监听器抛出的异常绝不允许冒泡到上传响应
            // （webman 的 Event::emit 内部会 catch，Yii 的 Event::trigger 是裸调用，必须在这里兜）。
            Yii::error('aetherupload 事件监听器抛出异常（已忽略，不影响上传响应）：' . $e->getMessage(), self::LOG_CATEGORY);
        }
    }
}
