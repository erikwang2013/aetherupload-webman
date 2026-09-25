<?php

namespace AetherUpload\Adapter\Yii;

/**
 * 带 withHeader() 的 Yii 响应。
 *
 * 内核只依赖「响应能用 withHeader() 串起来」，而 yii\web\Response 没有这个方法
 * （Yii 用 $response->getHeaders()->set(...)），因此适配器统一返回本子类。
 *
 * 承载文件流的必须是**独立对象**并交还给路由：yii\web\Response::sendFile() 只是把
 * stream 挂到响应上，真正输出发生在 send() —— 在控制器里就地 send 就会绕过 Yii 的
 * 响应生命周期（也会让后续 withHeader 全部失效）。
 */
class YiiResponse extends \yii\web\Response
{
    /**
     * 设置响应头并返回自身（与 PSR-7 的不可变风格不同，这里是可变的，便于内核链式调用）。
     *
     * @param string $name
     * @param string $value
     * @return $this
     */
    public function withHeader(string $name, string $value)
    {
        $this->getHeaders()->set($name, $value);

        return $this;
    }
}
