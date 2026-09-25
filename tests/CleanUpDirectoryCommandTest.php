<?php

namespace AetherUpload\Tests;

use AetherUpload\Tests\Support\TestState;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * aetherupload:clean 的过期判定回归测试。
 *
 * 旧实现拿文件名前 10 个字符与 strtotime('-N day') 比较，可临时文件名早已是随机串
 * （Util::generateTempName() 产 16 位十六进制），于是"刚创建的文件"会被当成过期文件删掉，
 * 只有首字符排在 '1' 之后的名字侥幸留下——实测约 9% 的新文件被误删。判定必须基于真实 mtime。
 */
class CleanUpDirectoryCommandTest extends TestCase
{
    private const ROOT_DIR      = 'storage/app/aetherupload';
    private const COMMAND_FILE  = '/../commands/AetherUploadCleanUpDirectory.php';
    private const COMMAND_CLASS = 'app\\command\\AetherUploadCleanUpDirectory';

    /** 文件名前 10 字符：小于当前时间戳，旧实现会把带此前缀的新旧文件一并删掉 */
    private const STALE_PREFIX = '1234567890';

    protected function setUp(): void
    {
        TestState::reset();
        TestState::normalizeConfig();
        TestState::resetConfigMapper();
        mkdir(TestState::$basePath, 0777, true);
    }

    protected function tearDown(): void
    {
        remove_dir(TestState::$basePath);
    }

    /**
     * 命令类必须能在当前依赖下加载：Symfony\Console\Command::execute() 在 7.x 上声明了 ": int"
     * 返回类型，子类签名缺少它会在 require 时抛编译期 fatal（不可捕获，会直接杀掉整个测试进程）。
     * 这里用子进程实测，避免把 fatal 带进本进程。
     */
    private function commandClassLoads(): bool
    {
        $code = 'require ' . var_export(__DIR__ . '/../vendor/autoload.php', true) . ';'
            . 'require_once ' . var_export(__DIR__ . self::COMMAND_FILE, true) . ';'
            . 'echo "loaded";';

        $output = shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code) . ' 2>&1');

        return is_string($output) && str_contains($output, 'loaded');
    }

    private function runClean(int $days): int
    {
        $class = self::COMMAND_CLASS;

        if ( ! class_exists($class) ) {
            require_once __DIR__ . self::COMMAND_FILE;
        }

        /** @var Command $command */
        $command = new $class();

        return $command->run(new ArrayInput(['days' => $days]), new BufferedOutput());
    }

    /**
     * 守护 bug 6 的前置条件：命令类在当前 symfony/console 下必须可加载。
     * 修复方式：给 commands/AetherUploadCleanUpDirectory.php 的 execute() 补上 ": int" 返回类型。
     */
    public function testCommandClassIsLoadableWithInstalledSymfonyConsole(): void
    {
        $this->assertTrue(
            $this->commandClassLoads(),
            '命令类无法加载：Symfony\Console\Command::execute() 要求 ": int" 返回类型，'
            . 'commands/AetherUploadCleanUpDirectory.php 的 execute() 缺少该返回类型'
        );
    }

    /**
     * 守护 bug 6（P0）：刚创建的 .part 与 header 不得被删，超过 days 的旧文件才删。
     */
    public function testCleanKeepsFreshFilesAndDeletesExpiredOnes(): void
    {
        if ( ! $this->commandClassLoads() ) {
            $this->markTestSkipped('命令类在当前 symfony/console 下无法加载，先看 testCommandClassIsLoadableWithInstalledSymfonyConsole');
        }

        $root = TestState::$basePath . '/' . self::ROOT_DIR;
        mkdir($root . '/_header', 0777, true);
        mkdir($root . '/file/202609', 0755, true);

        // 四个文件名字前 10 字符完全相同，只有 mtime 不同——判定依据只能是 mtime
        $freshHeader = $root . '/_header/' . self::STALE_PREFIX . 'newheader';
        $freshPart   = $root . '/file/202609/' . self::STALE_PREFIX . 'newpart.gif.part';
        $oldHeader   = $root . '/_header/' . self::STALE_PREFIX . 'oldheader';
        $oldPart     = $root . '/file/202609/' . self::STALE_PREFIX . 'oldpart.gif.part';

        foreach ( [$freshHeader, $freshPart, $oldHeader, $oldPart] as $file ) {
            file_put_contents($file, 'x');
        }

        touch($oldHeader, time() - 3 * 86400);
        touch($oldPart, time() - 3 * 86400);

        $exitCode = $this->runClean(2);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertFileExists($freshHeader, '刚创建的 header 被当成过期文件删了');
        $this->assertFileExists($freshPart, '刚创建的 .part 被当成过期文件删了');
        $this->assertFileDoesNotExist($oldHeader, '超过 days 的 header 必须删除');
        $this->assertFileDoesNotExist($oldPart, '超过 days 的 .part 必须删除');
    }
}
