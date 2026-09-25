<?php

namespace AetherUpload\Tests\Integration\Symfony;

use AetherUpload\Tests\Integration\FlowAssertions;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Response;

/**
 * Symfony 真实端到端：真应用（framework-bundle + 真 HttpKernel + 真 Router/Controller/Response）
 * + 真文件系统，落盘断言直接看磁盘。
 *
 * 这一层才是「Symfony 可用」的证据；tests/ 下的单元用例用的是替身，只覆盖内核逻辑。
 * 应用准备见 App.php / bootstrap.php，断言清单见 FlowAssertions。
 */
class SymfonyFlowTest extends WebTestCase
{
    use FlowAssertions;

    /** @var KernelBrowser|null 一个测试方法内复用同一个客户端（请求之间内核会按 Symfony 的规矩重建） */
    private $client;

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
        return App::redisUsable();
    }

    protected function instantCompletionSkipReason(): string
    {
        if ( ! App::redisAvailable() ) {
            return '本机 ' . App::redisHost() . ':' . App::redisPort() . ' 上没有可用的 redis'
                . '（装了 redis 但返回值不对也会走到这里）；第 8 条秒传未验证，其余 7 条不受影响';
        }

        return '本机有 redis，但 PHP 没装 phpredis（ext-redis）：Symfony 不自带 redis 客户端，'
            . '适配器此时会退回 NullRedis（一调用就抛），秒传无法验证。装上 ext-redis 或改用 predis 即可验证第 8 条';
    }

    /**
     * 发一个真实请求。
     *
     * $files 形如 ['resource_chunk' => ['name' => 'chunk.gif', 'content' => $bytes]]。
     * 请求交给真 HttpKernel：真 Router 选路 → 真控制器 → 真 Response 对象。
     */
    protected function send(string $method, string $path, array $post = [], array $files = [], array $headers = []): array
    {
        $uploads = [];
        $temporaries = [];

        foreach ( $files as $field => $file ) {
            $temp = tempnam(sys_get_temp_dir(), 'aetherupload-e2e-');
            file_put_contents($temp, $file['content']);
            $temporaries[] = $temp;
            // 第五个参数 $test = true：isValid() 走「上传错误码为 0」这一支。
            // 真实 HTTP 上传由 SAPI 判定 is_uploaded_file()，BrowserKit 里没有这一层，必须显式声明
            $uploads[$field] = new UploadedFile($temp, $file['name'], 'application/octet-stream', null, true);
        }

        $server = [];

        foreach ( $headers as $line ) {
            [$name, $value] = array_pad(explode(':', $line, 2), 2, '');
            $server['HTTP_' . strtoupper(str_replace('-', '_', trim($name)))] = trim($value);
        }

        try {
            $this->client()->request($method, $path, $post, $uploads, $server);
            $response = $this->client()->getResponse();

            $responseHeaders = [];

            foreach ( $response->headers->all() as $name => $values ) {
                foreach ( (array)$values as $value ) {
                    $responseHeaders[] = $name . ': ' . $value;
                }
            }

            return [
                'status'  => $response->getStatusCode(),
                'body'    => $this->body($response),
                'headers' => $responseHeaders,
            ];
        } finally {
            foreach ( $temporaries as $temp ) {
                @unlink($temp);
            }
        }
    }

    private function client(): KernelBrowser
    {
        if ( $this->client === null ) {
            $this->client = static::createClient();
        }

        return $this->client;
    }

    /**
     * 取响应体。
     *
     * BinaryFileResponse（display/download 走的就是它）的文件内容不在 getContent() 里 ——
     * getContent() 对一个文件响应永远是空串，字节只在 sendContent() 时流出去。
     * 这里用输出缓冲把它接住，拿到的就是真实客户端会收到的字节（含 prepare() 之后的 Range/304 处理）。
     */
    private function body(Response $response): string
    {
        if ( $response instanceof BinaryFileResponse ) {
            ob_start();
            $response->sendContent();

            return (string)ob_get_clean();
        }

        return (string)$response->getContent();
    }
}
