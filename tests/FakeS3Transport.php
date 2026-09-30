<?php

namespace AetherUpload\Tests;

/**
 * S3Client 的替身传输：脚本化响应队列 + 原样记录请求（逐字节断言用）。
 *
 * 传输契约（冻结，见 docs/superpowers/specs/2026-10-01-s3-storage-design.md §3.1）：
 *   $transport(array $request): array
 *   request  = ['method','url','headers','body','bodyFile','bodyOffset','bodySize']
 *   response = ['status'=>int, 'headers'=>array(小写名=>值), 'body'=>string]；网络错误抛 \Exception
 *
 * 队列耗尽还有调用进来 → 抛异常，把「实现多发了一个请求」变成显式红。
 */
class FakeS3Transport
{
    /** @var array<int,array> 收到的请求，按调用顺序原样记录 */
    public array $requests = [];

    /** @var array<int,array|\Throwable> 脚本化响应：数组 = 返回，\Throwable = 抛出（模拟网络错误） */
    private array $queue = [];

    public function respond(int $status, array $headers = [], string $body = ''): void
    {
        $this->queue[] = ['status' => $status, 'headers' => $headers, 'body' => $body];
    }

    public function failWith(\Throwable $error): void
    {
        $this->queue[] = $error;
    }

    public function __invoke(array $request): array
    {
        $this->requests[] = $request;

        if ( $this->queue === [] ) {
            throw new \Exception('FakeS3Transport：第 ' . count($this->requests) . ' 次调用没有对应的脚本响应（实现发出了未预期的请求）');
        }

        $next = array_shift($this->queue);

        if ( $next instanceof \Throwable ) {
            throw $next;
        }

        return $next;
    }

    /** @return array<int,string> 各次调用的 HTTP 方法 */
    public function methods(): array
    {
        return array_map(static function (array $request): string {
            return $request['method'];
        }, $this->requests);
    }

    /** @return array<int,string> 各次调用的 URL */
    public function urls(): array
    {
        return array_map(static function (array $request): string {
            return $request['url'];
        }, $this->requests);
    }

    /** 还剩多少条未被消费的脚本响应（应恒为 0，否则说明实现少发了请求） */
    public function remaining(): int
    {
        return count($this->queue);
    }
}
