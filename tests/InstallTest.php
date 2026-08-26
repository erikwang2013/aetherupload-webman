<?php

namespace AetherUpload\Tests;

use AetherUpload\Install;
use AetherUpload\Tests\Support\TestState;
use PHPUnit\Framework\TestCase;

class InstallTest extends TestCase
{
    protected function setUp(): void
    {
        TestState::reset();
    }

    protected function tearDown(): void
    {
        remove_dir(TestState::$basePath);
    }

    public function testInstallCreatesStorageDirectoriesAndCopiesAssets()
    {
        Install::install();

        $base = TestState::$basePath;
        $this->assertDirectoryExists($base . '/storage/app/aetherupload/file');
        $this->assertDirectoryExists($base . '/storage/app/aetherupload/_header');

        $this->assertDirectoryExists($base . '/config/plugin/erikwang2013/aetherupload-webman');
        $this->assertFileExists($base . '/config/plugin/erikwang2013/aetherupload-webman/route.php');

        $this->assertDirectoryExists($base . '/public/vendor/aetherupload/js');
        $this->assertFileExists($base . '/public/vendor/aetherupload/js/aetherupload-all.js');

        $this->assertDirectoryExists($base . '/app/command');
        $this->assertFileExists($base . '/app/command/AetherUploadCleanUpDirectory.php');

        $this->assertDirectoryExists($base . '/resource/translations/aetherupload');
        $this->assertFileExists($base . '/resource/translations/aetherupload/zh/messages.php');
    }

    public function testInstallIsIdempotent()
    {
        Install::install();
        Install::install();

        $base = TestState::$basePath;
        $this->assertDirectoryExists($base . '/storage/app/aetherupload/file');
        $this->assertFileExists($base . '/config/plugin/erikwang2013/aetherupload-webman/route.php');
    }

    public function testUninstallRemovesCopiedDirectories()
    {
        Install::install();
        Install::uninstall();

        $base = TestState::$basePath;
        $this->assertDirectoryDoesNotExist($base . '/config/plugin/erikwang2013/aetherupload-webman');
        $this->assertDirectoryDoesNotExist($base . '/public/vendor/aetherupload/js');
        $this->assertDirectoryDoesNotExist($base . '/app/command');
        $this->assertDirectoryDoesNotExist($base . '/resource/translations/aetherupload');

        // uninstall must not touch storage directories
        $this->assertDirectoryExists($base . '/storage/app/aetherupload/file');
    }
}
