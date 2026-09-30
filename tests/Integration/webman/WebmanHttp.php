<?php

namespace AetherUpload\Tests\Integration\Webman;

use CURLFile;

/**
 * 打真实 HTTP 请求的公共实现（local 套件与 S3 套件共用）。
 *
 * 单独抽出来是因为 FlowAssertions 只声明 abstract send()，而 webman 有两条测试类：
 * WebmanFlowTest（local）与 S3FlowTest（storage.driver=s3）—— 请求形态完全一样，只有断言不同。
 */
trait WebmanHttp
{
    /**
     * 发一个真实 HTTP 请求。
     *
     * $files 形如 ['resource_chunk' => ['name' => 'chunk.gif', 'content' => $bytes]]；
     * 有文件时走 multipart/form-data，纯字段时也走 multipart（与前端 aetherupload 的提交形态一致）。
     */
    protected function send(string $method, string $path, array $post = [], array $files = [], array $headers = []): array
    {
        $tempFiles = [];
        $fields = $post;

        foreach ( $files as $field => $file ) {
            $temp = tempnam(sys_get_temp_dir(), 'aetherupload-e2e-');
            file_put_contents($temp, $file['content']);
            $tempFiles[] = $temp;
            $fields[$field] = new CURLFile($temp, 'application/octet-stream', $file['name']);
        }

        $responseHeaders = [];
        $ch = curl_init(App::baseUrl() . $path);

        // 空 Expect：避免 curl 对大文件先发 100-continue
        $headers[] = 'Expect:';

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HEADERFUNCTION => static function ($handle, $line) use (&$responseHeaders) {
                $responseHeaders[] = rtrim($line, "\r\n");

                return strlen($line);
            },
        ]);

        if ( $fields !== [] ) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $fields);
        }

        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        // 不用 curl_close：PHP 8.0 起它就是空操作，8.5 起还会报 deprecation

        foreach ( $tempFiles as $temp ) {
            @unlink($temp);
        }

        if ( $body === false ) {
            $this->fail("curl 请求失败：{$error}（{$method} {$path}）");
        }

        return ['status' => $status, 'body' => (string)$body, 'headers' => $responseHeaders];
    }
}
