<?php

namespace AetherUpload\Adapter\Yii;

use AetherUpload\Contract\RequestInterface;
use AetherUpload\Contract\UploadedFileInterface;
use Yii;
// 别名不能叫 YiiUploadedFile：import 的名字优先于同命名空间的类，那样 new YiiUploadedFile() 拿到的
// 仍是宿主的类（就撞上「返回类型不对」的 TypeError）。宿主那个叫 HostUploadedFile，自己那个不用限定名。
use yii\web\UploadedFile as HostUploadedFile;

/**
 * 请求端口。
 *
 * input() 走 getBodyParams()/getQueryParams() 这两个**原始**数组而不是 post()/get()：
 * 内核的类型守卫要看见客户端原样发来的数组/整数/字符串，任何一层过滤都会让守卫失效。
 * 两者的差别只在「显式 null」上——post() 用 ??: 会把 null 当缺失，这里保留 null 原值。
 */
class YiiRequest implements RequestInterface
{
    /**
     * @param mixed $default
     * @return mixed
     */
    public function input(string $key, $default = null)
    {
        $request = Yii::$app === null ? null : Yii::$app->getRequest();

        if ( $request === null ) {
            return $default;
        }

        $body = $request->getBodyParams();

        if ( is_array($body) && array_key_exists($key, $body) ) {
            return $body[$key];
        }

        $query = $request->getQueryParams();

        if ( is_array($query) && array_key_exists($key, $query) ) {
            return $query[$key];
        }

        return $default;
    }

    public function file(string $key): ?UploadedFileInterface
    {
        $request = Yii::$app === null ? null : Yii::$app->getRequest();

        if ( $request === null ) {
            return null;
        }

        // UploadedFile 把 $_FILES 归一化后缓存在静态属性里，常驻进程（RoadRunner/Swoole 下的 Yii）
        // 下第二个请求会读到第一个请求的文件。每次取之前重置，代价只是重解析一遍 $_FILES。
        HostUploadedFile::reset();

        $file = HostUploadedFile::getInstanceByName($key);

        // 用适配器自己的端口实现，不是 Kernel\UploadedFile：Yii 的上传对象没有
        // isValid()/getRealPath()（见 YiiUploadedFile 的类注释）。
        return $file === null ? null : new YiiUploadedFile($file);
    }

    public function all(): array
    {
        $request = Yii::$app === null ? null : Yii::$app->getRequest();

        if ( $request === null ) {
            return [];
        }

        return array_merge($request->getQueryParams(), $request->getBodyParams());
    }
}
