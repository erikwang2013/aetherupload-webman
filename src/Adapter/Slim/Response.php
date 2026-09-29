<?php

namespace AetherUpload\Adapter\Slim;

use AetherUpload\Kernel\Psr7Response;

/**
 * 内核响应的可变 shim（Slim 这一份）。
 *
 * 实现、可变语义的理由、以及**唯一的转换点**（`Bootstrap::create()` 注册四条路由时包的那层闭包）
 * 都在 `AetherUpload\Kernel\Psr7Response`：Yii3 适配器同样「宿主响应是不可变 PSR-7」，
 * 两边共用一份实现，免得「丢弃 withHeader 返回值」这条契约有两份会漂移的代码。
 *
 * 保留本类名（而不是直接用 Kernel 的类）有两个实际原因：
 *  - `SlimResponseFactory` 与既有用例都按这个名字调静态构造（`Response::json()` 等）；
 *  - 父类的静态构造用 `static::`，所以这里拿到的仍是本类实例，`Bootstrap::convert()` 里的
 *    `instanceof Response` 判定照旧成立。
 *
 * @see Psr7Response
 */
class Response extends Psr7Response
{
}
