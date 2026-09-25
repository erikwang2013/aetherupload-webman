<?php

namespace AetherUpload\Tests\Integration\Yii;

use AetherUpload\Adapter\Yii\YiiResponse;

/**
 * 只记录、不外发。
 *
 * 父类的 send() 会 header()/setcookie()/echo —— 在 PHPUnit 进程里那是**真的**往外发 HTTP 头，
 * 轻则污染输出，重则因 headers already sent 抛异常。这里把三步换成「prepare + 收集」：
 * sendHeaders()/sendCookies() 一概不调，正文用输出缓冲抓。
 *
 * 复用父类的 sendContent() 而不是自己写读取循环：Range/文件流那套逻辑仍是 Yii 自己的那份，
 * 测出来的字节与真实响应一致（sendContent() 是 echo 型的，文件流也走 echo）。
 */
class RecordingResponse extends YiiResponse
{
    /** @var array{status:int, body:string, headers:string[]}|null 上一次 send() 的结果 */
    public $recorded;

    public function send()
    {
        if ( $this->isSent ) {
            return;
        }

        $this->prepare();

        $lines = [];

        foreach ( $this->getHeaders() as $name => $values ) {
            foreach ( $values as $value ) {
                $lines[] = $name . ': ' . $value;
            }
        }

        ob_start();
        $this->sendContent();
        $body = (string)ob_get_clean();

        $this->recorded = [
            'status'  => $this->getStatusCode(),
            'body'    => $body,
            'headers' => $lines,
        ];
        $this->isSent = true;
    }
}
