<?php

namespace AetherUpload\Tests;

use AetherUpload\Install;
use AetherUpload\Runtime;
use AetherUpload\Tests\Support\TestState;
use PHPUnit\Framework\TestCase;

/**
 * 全新安装场景。
 *
 * `Install::install()` 由 webman 的 composer 脚本调用（post-package-install →
 * support\Plugin::install），那一刻宿主应用还没启动：route.php 没被加载、没人调用过
 * Runtime::bind()，而且宿主的 config() 里也还没有本插件的配置——配置文件正是本次安装
 * 才被复制进去的。
 *
 * 这两点都曾让「装插件」这件事本身失败或半途而废，所以单独守护。
 */
class InstallFreshAppTest extends TestCase
{
    protected function setUp(): void
    {
        TestState::reset();
        TestState::normalizeConfig();
        TestState::resetConfigMapper();
    }

    protected function tearDown(): void
    {
        remove_dir(TestState::$basePath);
        Runtime::reset();
        \AetherUpload\Runtime::bind(new \AetherUpload\Adapter\Webman\WebmanAdapter());
    }

    /** 守护：composer 装包时 Runtime 尚未绑定，install() 必须自己兜底而不是抛异常 */
    public function testInstallWorksWhenRuntimeIsNotBoundYet(): void
    {
        Runtime::reset();
        $this->assertFalse(Runtime::isBound(), '前置条件：本用例模拟的是宿主尚未绑定适配器的时刻');

        Install::install();

        $this->assertTrue(Runtime::isBound(), 'install() 之后应已绑定 webman 适配器');
        $this->assertDirectoryExists(TestState::$basePath . '/storage/app/aetherupload/_header');
    }

    /**
     * 守护：composer 卸载包时宿主同样没启动（post-package-uninstall → support\Plugin::uninstall
     * → \AetherUpload\Install::uninstall()），uninstall() 必须和 install() 一样自己兜底绑定。
     */
    public function testUninstallWorksWhenRuntimeIsNotBoundYet(): void
    {
        Install::install(); // 先装一次：待删的目录正是这么来的（真实卸载时它们已经存在）

        Runtime::reset(); // 模拟卸载那一刻：webman 未启动、route.php 未被加载，没人 bind
        $this->assertFalse(Runtime::isBound(), '前置条件：本用例模拟的是宿主尚未绑定适配器的时刻');

        Install::uninstall();

        $this->assertTrue(Runtime::isBound(), 'uninstall() 之后应已绑定 webman 适配器');
        $base = TestState::$basePath;
        $this->assertDirectoryDoesNotExist($base . '/config/plugin/erikwang2013/aetherupload-webman');
        $this->assertDirectoryDoesNotExist($base . '/public/vendor/aetherupload/js');
        $this->assertDirectoryDoesNotExist($base . '/app/command');
        $this->assertDirectoryDoesNotExist($base . '/resource/translations/aetherupload');
    }

    /** 守护：宿主配置尚未加载时，仍要按插件自带默认配置把分组目录建出来 */
    public function testInstallCreatesGroupDirsWhenHostConfigIsNotLoadedYet(): void
    {
        Runtime::reset();
        unset(TestState::$config['plugin']); // 模拟「宿主的 config() 里还没有本插件配置」

        Install::install();

        $root = TestState::$basePath . '/storage/app/aetherupload';
        $this->assertDirectoryExists($root);
        $this->assertDirectoryExists($root . '/_header');
        $this->assertDirectoryExists(
            $root . '/file',
            '分组目录没建出来 —— preprocess 会因为 createGroupSubDir() 的非递归 mkdir 失败，且错误被塌缩成通用 upload_error'
        );
    }

    /** 守护：宿主配置可用时优先用它（自定义 root_dir 与分组） */
    public function testInstallPrefersHostConfigWhenAvailable(): void
    {
        TestState::set(TestState::PREFIX . '.root_dir', 'storage/custom');
        TestState::set(TestState::PREFIX . '.groups', [
            'file' => ['group_dir' => 'file', 'resource_maxsize' => 0, 'resource_extensions' => [], 'event_before_upload_complete' => false, 'event_upload_complete' => false],
            'videos' => ['group_dir' => 'videos', 'resource_maxsize' => 0, 'resource_extensions' => [], 'event_before_upload_complete' => false, 'event_upload_complete' => false],
        ]);
        TestState::resetConfigMapper();

        Install::install();

        $this->assertDirectoryExists(TestState::$basePath . '/storage/custom/file');
        $this->assertDirectoryExists(TestState::$basePath . '/storage/custom/videos');
        $this->assertDirectoryDoesNotExist(TestState::$basePath . '/storage/app/aetherupload');
    }
}
