<?php

namespace AetherUpload\Tests;

use AetherUpload\RedisSavedPath;
use AetherUpload\Tests\Support\TestState;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * aetherupload:build（README 推荐每天跑的 cron）重建秒传索引的回归测试。
 *
 * 旧实现先 RedisSavedPath::deleteAll() 清空索引，再逐个文件把文件名当 hash 建记录；
 * 目录里只要有一个非本插件命名的文件（.DS_Store、.htaccess、'my file.bin'），
 * pathinfo(..., PATHINFO_FILENAME) 会得到空串或带空格的名字，getKey() 抛 invalid_operation 中断整个命令——
 * 此时索引已被清空、一条都没重建、exit 1，秒传静默失效（每天一次的定时任务会把故障常态化）。
 * 正确行为：跳过无法识别的文件，其余资源照常重建。
 */
class BuildRedisHashesCommandTest extends TestCase
{
    private const ROOT_DIR      = 'storage/app/aetherupload';
    private const COMMAND_FILE  = '/../commands/AetherUploadBuildRedisHashes.php';
    private const COMMAND_CLASS = 'app\\command\\AetherUploadBuildRedisHashes';

    /** 资源文件名就是它的 hash（Util::getFileName($hash, $ext)） */
    private const RESOURCE_HASH = '5d41402abc4b2a76b9719d911017c592';
    private const SUB_DIR       = '202609';

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

    private function runBuild(): array
    {
        $class = self::COMMAND_CLASS;

        if ( ! class_exists($class) ) {
            require_once __DIR__ . self::COMMAND_FILE;
        }

        /** @var Command $command */
        $command = new $class();
        $output = new BufferedOutput();

        return [$command->run(new ArrayInput([]), $output), $output->fetch()];
    }

    /**
     * 守护"build 不得因垃圾文件清空索引"（评审发现的数据丢失）：目录里混入 .DS_Store / .htaccess /
     * 带空格文件名 / .part 时，命令必须 exit SUCCESS 并把真实资源重建进索引，垃圾文件被跳过。
     */
    public function testBuildSkipsUnrecognizedFilesInsteadOfWipingTheIndex(): void
    {
        if ( ! $this->commandClassLoads() ) {
            $this->markTestSkipped('命令类在当前 symfony/console 下无法加载，见 tests/CleanUpDirectoryCommandTest.php 同类前置用例');
        }

        $groupDir = TestState::$basePath . '/' . self::ROOT_DIR . '/file';
        mkdir($groupDir . '/' . self::SUB_DIR, 0777, true);

        // 一个真实资源文件 + 四种目录里常见的非资源文件
        $resourceName = self::RESOURCE_HASH . '.gif';
        $resourcePath = $groupDir . '/' . self::SUB_DIR . '/' . $resourceName;
        file_put_contents($resourcePath, 'GIF89a');
        file_put_contents($groupDir . '/' . self::SUB_DIR . '/.DS_Store', 'junk');
        file_put_contents($groupDir . '/' . self::SUB_DIR . '/.htaccess', 'junk');
        file_put_contents($groupDir . '/' . self::SUB_DIR . '/my file.bin', 'junk');
        file_put_contents($groupDir . '/' . self::SUB_DIR . '/x.gif.part', 'incomplete');

        // 预置一条陈旧索引：重建成功后它应当消失，且真实资源应当被重新写回
        RedisSavedPath::set('file_stalehash', 'file_' . self::SUB_DIR . '_stalehash.gif');

        [$exitCode, $output] = $this->runBuild();

        $this->assertSame(Command::SUCCESS, $exitCode, '目录里的垃圾文件不得让重建命令失败：' . $output);
        $this->assertStringContainsString('1 items have been set in Redis.', $output);
        $this->assertStringContainsString('Done.', $output);

        $key = RedisSavedPath::getKey('file', self::RESOURCE_HASH);
        $this->assertTrue(
            RedisSavedPath::exists($key),
            '索引被清空却没有重建：deleteAll() 之后命令因垃圾文件中断了'
        );
        $this->assertSame('file_' . self::SUB_DIR . '_' . $resourceName, RedisSavedPath::get($key));

        $this->assertFalse(RedisSavedPath::exists('file_stalehash'), '陈旧索引应被清掉');
        // 索引里只该有那一个真实资源，垃圾文件不得各留一条脏记录
        $this->assertCount(1, TestState::$redisStrings, '存在由垃圾文件名派生的脏记录：' . implode(', ', array_keys(TestState::$redisStrings)));
    }

    /**
     * 配置文件是用户手改的，可以绕过上传阶段的分组名校验直接写进含下划线的分组。
     * 这类分组的 savedPath 永远解码不回来（见 ConfigMapperGroupNameTest 的往返证据），
     * build 必须跳过并告警，而不是把注定 404 的记录塞进索引、还报成功。
     */
    public function testBuildSkipsGroupNamesWithUnderscore(): void
    {
        if ( ! $this->commandClassLoads() ) {
            $this->markTestSkipped('命令类在当前 symfony/console 下无法加载，见同类前置用例');
        }

        // 合法分组 file：一个真实资源
        $validDir = TestState::$basePath . '/' . self::ROOT_DIR . '/file/' . self::SUB_DIR;
        mkdir($validDir, 0777, true);
        file_put_contents($validDir . '/' . self::RESOURCE_HASH . '.gif', 'GIF89a');

        // 非法分组 bad_group：同样放一个 md5 资源文件
        TestState::set(TestState::PREFIX . '.groups.bad_group', [
            'group_dir'                    => 'bad',
            'resource_maxsize'             => 104857600,
            'resource_extensions'          => ['gif'],
            'event_before_upload_complete' => false,
            'event_upload_complete'        => false,
        ]);
        TestState::resetConfigMapper();

        $badDir = TestState::$basePath . '/' . self::ROOT_DIR . '/bad/' . self::SUB_DIR;
        mkdir($badDir, 0777, true);
        file_put_contents($badDir . '/' . self::RESOURCE_HASH . '.gif', 'GIF89a');

        [$exitCode, $output] = $this->runBuild();

        $this->assertSame(Command::SUCCESS, $exitCode, '非法分组应被跳过而不是让命令失败：' . $output);
        $this->assertStringContainsString('Invalid group name "bad_group"', $output);
        $this->assertStringContainsString('skipped', $output);
        $this->assertStringContainsString('1 items have been set in Redis.', $output);

        $this->assertTrue(RedisSavedPath::exists(RedisSavedPath::getKey('file', self::RESOURCE_HASH)), '合法分组的资源应照常重建');
        $this->assertFalse(
            RedisSavedPath::exists('bad_group_' . self::RESOURCE_HASH),
            '含下划线分组的记录解码后指向不存在的路径，不得进入索引'
        );
        $this->assertCount(1, TestState::$redisStrings, '索引里混进了非法分组的记录：' . implode(', ', array_keys(TestState::$redisStrings)));
    }
}
