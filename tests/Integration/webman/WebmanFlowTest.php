<?php

namespace AetherUpload\Tests\Integration\Webman;

use AetherUpload\Tests\Integration\FlowAssertions;
use CURLFile;
use PHPUnit\Framework\TestCase;

/**
 * webman 真实端到端：真骨架（composer create-project workerman/webman）+ 真 HTTP（curl）+ 真文件系统。
 *
 * 这一层才是「webman 可用」的证据；tests/ 下的 217 个用例用的是替身，只覆盖内核逻辑。
 * 应用准备与启停见 bootstrap.php / App.php，断言清单见 FlowAssertions。
 */
class WebmanFlowTest extends TestCase
{
    use FlowAssertions;

    protected function setUp(): void
    {
        parent::setUp();

        // 每条用例从「无秒传记录」开始：残留记录会让第 7 条探针假红、第 1 条看不到 .part
        App::flushInstantCompletionKeys();
    }

    protected function appBasePath(): string
    {
        return App::root();
    }

    protected function probeLogPath(): ?string
    {
        return App::probeLog();
    }

    protected function supportsInstantCompletion(): bool
    {
        return App::redisAvailable();
    }

    protected function instantCompletionSkipReason(): string
    {
        return '本机 ' . App::redisHost() . ':' . App::redisPort() . ' 上没有可用的 redis'
            . '（装了 redis 但返回值不对也会走到这里）；第 8 条秒传未验证，其余 7 条不受影响';
    }

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
        curl_close($ch);

        foreach ( $tempFiles as $temp ) {
            @unlink($temp);
        }

        if ( $body === false ) {
            $this->fail("curl 请求失败：{$error}（{$method} {$path}）");
        }

        return ['status' => $status, 'body' => (string)$body, 'headers' => $responseHeaders];
    }
}
