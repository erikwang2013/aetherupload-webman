<?php

namespace AetherUpload\Tests;

use AetherUpload\Install;
use AetherUpload\Tests\Support\TestState;
use PHPUnit\Framework\TestCase;

/**
 * Install::install() 曾经硬编码 storage/app/aetherupload/file 与 _header：
 * 改 root_dir 或新增分组后建的是错目录，与 aetherupload:groups 命令行为不一致。
 * 本文件钉死「按配置建目录 + 配置缺失回落默认值」这两条新行为。
 */
class InstallRootDirTest extends TestCase
{
    private const PREFIX = 'plugin.erikwang2013.aetherupload-webman.app';

    protected function setUp(): void
    {
        TestState::reset();
        TestState::normalizeConfig();
    }

    protected function tearDown(): void
    {
        remove_dir(TestState::$basePath);
    }

    public function testInstallCreatesDirectoriesFromConfiguredRootDirAndGroups()
    {
        TestState::set(self::PREFIX . '.root_dir', 'storage/custom-uploads');
        TestState::set(self::PREFIX . '.groups', [
            'file'  => ['group_dir' => 'file'],
            'video' => ['group_dir' => 'videos'],
        ]);

        Install::install();
        Install::install(); // 幂等：重复安装不得报错

        $base = TestState::$basePath;
        $this->assertDirectoryExists($base . '/storage/custom-uploads');
        $this->assertDirectoryExists($base . '/storage/custom-uploads/_header');
        $this->assertDirectoryExists($base . '/storage/custom-uploads/file');
        $this->assertDirectoryExists($base . '/storage/custom-uploads/videos');

        // 旧实现硬编码的路径必须不再出现
        $this->assertDirectoryDoesNotExist($base . '/storage/app/aetherupload');
    }

    public function testInstallSkipsGroupsWithoutGroupDir()
    {
        TestState::set(self::PREFIX . '.root_dir', 'storage/custom-uploads');
        TestState::set(self::PREFIX . '.groups', [
            'file'   => ['group_dir' => 'file'],
            'broken' => ['resource_maxsize' => 1024], // 缺 group_dir
            'empty'  => ['group_dir' => ''],          // group_dir 为空串
            'scalar' => 'oops',                       // 分组值不是数组
        ]);

        Install::install();

        $this->assertSame(
            ['_header', 'file'],
            $this->entries(TestState::$basePath . '/storage/custom-uploads')
        );
    }

    public function testInstallFallsBackToDefaultRootDirWhenConfigKeysAreMissing()
    {
        unset(
            TestState::$config['plugin']['erikwang2013']['aetherupload-webman']['app']['root_dir'],
            TestState::$config['plugin']['erikwang2013']['aetherupload-webman']['app']['groups']
        );

        Install::install(); // 必须不抛异常

        $base = TestState::$basePath;
        $this->assertDirectoryExists($base . '/storage/app/aetherupload');
        $this->assertDirectoryExists($base . '/storage/app/aetherupload/_header');
        // 宿主配置里没有 groups 时，回落到插件自带的默认配置，把默认分组目录一并建出来。
        // 这一条很关键：全新安装时宿主的 config() 里必然还没有本插件的配置（配置文件正是本次安装才
        // 复制进去的），若不回落，默认分组目录永远不会被创建，此后每次 preprocess 都会失败
        // ——createGroupSubDir() 是非递归 mkdir，父目录缺失直接返回 false，而错误又被塌缩成通用 upload_error。
        // 见 tests/InstallFreshAppTest.php（含「只修一半」的红态验证）。
        $this->assertSame(['_header', 'file'], $this->entries($base . '/storage/app/aetherupload'));
    }

    public function testInstallFallsBackToDefaultRootDirWhenRootDirIsNull()
    {
        TestState::set(self::PREFIX . '.root_dir', null);
        TestState::set(self::PREFIX . '.groups', null);

        Install::install(); // 必须不抛异常

        $base = TestState::$basePath;
        $this->assertDirectoryExists($base . '/storage/app/aetherupload/_header');
        // 同上：显式 null 也回落到插件自带的默认配置（root_dir 与默认分组目录）
        $this->assertSame(['_header', 'file'], $this->entries($base . '/storage/app/aetherupload'));
        // null 不得被当成空路径，把目录建到项目根下
        $this->assertDirectoryDoesNotExist($base . '/_header');
    }

    /** @return string[] 目录下的条目名（已排序） */
    private function entries(string $dir): array
    {
        $entries = array_values(array_diff(scandir($dir), ['.', '..']));
        sort($entries);

        return $entries;
    }
}
