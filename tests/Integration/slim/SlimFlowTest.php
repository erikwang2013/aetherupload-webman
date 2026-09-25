<?php

namespace AetherUpload\Tests\Integration\Slim;

use AetherUpload\Tests\Integration\FlowAssertions;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\UploadedFile;

/**
 * Slim 真实端到端：真装 slim/slim 骨架 + 真跑 Dispatcher 与中间件栈（`$app->handle($request)`）
 * + 真文件系统。应用构建代码与 public/index.php 共用（骨架里的 app.php）。
 *
 * 这一层才是「Slim 可用」的证据；tests/ 下的单测用的是替身，只覆盖内核逻辑。
 * 断言清单见 FlowAssertions（8 条），本类只实现 Slim 特有的收发方式。
 */
class SlimFlowTest extends TestCase
{
    use FlowAssertions;

    /** @var string[] 本次请求造的临时上传文件（请求结束即删） */
    private $tempUploads = [];

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
     * 发一个请求：构造真 PSR-7 请求交给 $app->handle()，走完整 Dispatcher + 中间件栈。
     *
     * $files 形如 ['resource_chunk' => ['name' => 'chunk.gif', 'content' => $bytes]]；
     * 上传对象由**真实临时文件**构造（与真机上 $_FILES.tmp_name 同形），
     * 因此适配器走的是「流自带本地 uri」那条路，不产生拷贝（SlimAdapterTest 另测无 uri 的回落路）。
     */
    protected function send(string $method, string $path, array $post = [], array $files = [], array $headers = []): array
    {
        $request = (new ServerRequestFactory())->createServerRequest($method, 'http://127.0.0.1' . $path);

        if ( $post !== [] ) {
            $request = $request->withParsedBody($post);
        }

        $this->tempUploads = [];

        if ( $files !== [] ) {
            $request = $request->withUploadedFiles($this->uploadedFiles($files));
        }

        foreach ( $headers as $name => $value ) {
            $request = $request->withHeader($name, $value);
        }

        try {
            $response = App::app()->handle($request);

            $lines = [];
            foreach ( $response->getHeaders() as $name => $values ) {
                foreach ( $values as $value ) {
                    $lines[] = $name . ': ' . $value;
                }
            }

            return [
                'status'  => $response->getStatusCode(),
                'body'    => (string)$response->getBody(),
                'headers' => $lines,
            ];
        } finally {
            foreach ( $this->tempUploads as $temp ) {
                @unlink($temp);
            }

            $this->tempUploads = [];
        }
    }

    /** 把测试夹具的字节落成临时文件并包成 PSR-7 上传对象 */
    private function uploadedFiles(array $files): array
    {
        $uploaded = [];

        foreach ( $files as $field => $file ) {
            $temp = tempnam(sys_get_temp_dir(), 'aetherupload-e2e-');

            if ( $temp === false ) {
                $this->fail('无法创建临时上传文件');
            }

            file_put_contents($temp, $file['content']);
            $this->tempUploads[] = $temp;

            $uploaded[$field] = new UploadedFile($temp, (string)$file['name'], 'application/octet-stream', strlen($file['content']), UPLOAD_ERR_OK, false);
        }

        return $uploaded;
    }
}
