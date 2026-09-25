<?php

namespace AetherUpload\Tests;

use AetherUpload\ConfigMapper;
use AetherUpload\PartialResource;
use AetherUpload\Tests\Support\TestState;
use PHPUnit\Framework\TestCase;

/**
 * checkSize() 必须基于 append 之后的真实最终大小判定。
 * 部分资源是同一请求内 create()+append() 拼起来的，而 PHP 的 stat 缓存会让 filesize()
 * 返回上一次 stat 的旧值（append 走的是 fopen/fwrite，不会清缓存）。
 */
class PartialResourceCheckSizeTest extends TestCase
{
    private const PREFIX  = 'plugin.erikwang2013.aetherupload-webman.app';
    private const SUB_DIR = '202609';
    private const MAXSIZE = 10;

    private string $base = '';

    protected function setUp(): void
    {
        TestState::reset();
        TestState::normalizeConfig();
        // 上限调小，便于用几十字节的文件跨越边界
        TestState::set(self::PREFIX . '.groups.file.resource_maxsize', self::MAXSIZE);
        TestState::resetConfigMapper();
        // PartialResource 构造时快照 ConfigMapper 单例，先把组配置灌进去
        ConfigMapper::applyGroupConfig('file');
        $this->base = TestState::$basePath;
        mkdir($this->base . '/storage/app/aetherupload/file/' . self::SUB_DIR, 0777, true);
    }

    protected function tearDown(): void
    {
        remove_dir(TestState::$basePath);
    }

    private function makePartial(): PartialResource
    {
        return new PartialResource('tmpname', 'gif', self::SUB_DIR);
    }

    private function tempFile(string $content): string
    {
        $path = tempnam($this->base, 'chunk');
        file_put_contents($path, $content);

        return $path;
    }

    /** @return \Throwable|null */
    private function captured(callable $fn): ?\Throwable
    {
        try {
            $fn();
        } catch ( \Exception $e ) {
            return $e;
        }

        return null;
    }

    private function assertMaxSizeIs(int $expected): void
    {
        $this->assertSame($expected, ConfigMapper::get('resource_maxsize'), '前置条件：resource_maxsize 未按预期生效');
    }

    /**
     * 守护 bug 5（P1）：append 之后 checkSize() 必须读到真实最终大小。
     * 修复前 filesize() 命中 append 之前的 stat 缓存（这里被控制器的增量校验先填成 0），
     * 合法文件会被当成 0 字节直接判 invalid_resource_size。
     */
    public function testCheckSizeDoesNotRejectValidFileBecauseOfStaleEmptyStat(): void
    {
        $this->assertMaxSizeIs(self::MAXSIZE);

        $partial = $this->makePartial();
        $partial->create();

        // 控制器在 append 前会先 filesize(part) 做增量校验（UploadController::saveChunk），
        // 这一步就把 stat 缓存填成了 0
        $this->assertSame(0, filesize($partial->realPath));

        $partial->append($this->tempFile('12345'));   // 最终 5 字节，未超过上限

        $thrown = $this->captured(fn() => $partial->checkSize());

        $this->assertNull($thrown, 'checkSize() 读到了 append 之前的旧大小，把合法文件(5B)判成非法: '
            . ($thrown ? $thrown->getMessage() : ''));
    }

    /**
     * 守护 bug 5（P1）：反向——append 之后的最终大小确实超过 resource_maxsize 时必须抛错。
     * 修复前读到的是旧大小(5)，超限不会被发现。
     */
    public function testCheckSizeThrowsWhenAppendPushesFileOverMaxSize(): void
    {
        $this->assertMaxSizeIs(self::MAXSIZE);

        $partial = $this->makePartial();
        $partial->create();

        $partial->append($this->tempFile('12345'));
        // 与控制器一致：append 前先读一次大小（此时 5B，在上限内，读不到旧值就发现不了后面的超限）
        clearstatcache(true, $partial->realPath);
        $this->assertSame(5, filesize($partial->realPath));

        $partial->append($this->tempFile(str_repeat('x', 25)));   // 最终 30 字节 > 10

        $thrown = $this->captured(fn() => $partial->checkSize());

        $this->assertNotNull($thrown, 'append 之后的最终大小(30B)超过上限，checkSize() 必须抛错');
        $this->assertSame('invalid_resource_size', $thrown->getMessage());
    }

    /**
     * 反向断言：没有 stat 缓存干扰时，checkSize() 对合法大小与超限大小都按实际判定。
     */
    public function testCheckSizeJudgesFreshFilesByRealSize(): void
    {
        $this->assertMaxSizeIs(self::MAXSIZE);

        clearstatcache();

        $ok = $this->makePartial();
        $ok->create();
        $ok->append($this->tempFile('12345'));

        $this->assertNull($this->captured(fn() => $ok->checkSize()));

        $tooBig = new PartialResource('othername', 'gif', self::SUB_DIR);
        $tooBig->create();
        $tooBig->append($this->tempFile(str_repeat('y', 25)));

        $thrown = $this->captured(fn() => $tooBig->checkSize());

        $this->assertSame('invalid_resource_size', $thrown ? $thrown->getMessage() : 'no exception');
    }
}
