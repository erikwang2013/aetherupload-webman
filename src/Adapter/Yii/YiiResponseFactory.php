<?php

namespace AetherUpload\Adapter\Yii;

use AetherUpload\Contract\ResponseFactoryInterface;
use Throwable;
use Yii;

/**
 * 响应构造：三种形态都返回 YiiResponse（或宿主在 response 组件里配置的子类）。
 *
 * text/json 直接用内核给定的字节，不走 Yii 的 formatter —— 六个适配器的 JSON 必须字节一致，
 * 交给 JsonResponseFormatter 就会掺进宿主的 encodeOptions 差异。
 * format 置为 raw 是为了让 Response::prepare() 原样保留 content。
 */
class YiiResponseFactory implements ResponseFactoryInterface
{
    public function text(string $body, int $status = 200): object
    {
        $response = $this->newResponse();
        $response->format = YiiResponse::FORMAT_RAW;
        $response->setStatusCode($status);
        $response->getHeaders()->set('Content-Type', 'text/plain; charset=UTF-8');
        $response->content = $body;

        return $response;
    }

    /**
     * @param mixed $data
     */
    public function json($data, int $status = 200): object
    {
        $body = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        $response = $this->newResponse();
        $response->format = YiiResponse::FORMAT_RAW;
        $response->setStatusCode($status);
        $response->getHeaders()->set('Content-Type', 'application/json; charset=UTF-8');
        $response->content = $body;

        return $response;
    }

    public function file(string $path): object
    {
        // 第三个参数 inline：展示场景不强制下载（浏览器可直接渲染图片）
        return $this->newResponse()->sendFile($path, basename($path), ['inline' => true]);
    }

    public function download(string $path, string $name): object
    {
        return $this->newResponse()->sendFile($path, $name);
    }

    /**
     * 复用宿主的 response 组件（若它就是本适配器的 YiiResponse 或其子类）：
     * 这样宿主为 response 配置的选项照旧生效，测试也能用子类接管 send()；
     * 宿主没配（拿到的是原生 yii\web\Response，没有 withHeader）或当前没有应用时，
     * 退回自建实例。
     */
    private function newResponse(): YiiResponse
    {
        $app = Yii::$app;

        if ( $app !== null ) {
            try {
                $response = $app->getResponse();
            } catch ( Throwable $e ) {
                // 控制台应用没有 response 组件：不是错误路径
                return new YiiResponse();
            }

            if ( $response instanceof YiiResponse ) {
                $response->clear();

                return $response;
            }
        }

        return new YiiResponse();
    }
}
