<?php

namespace AetherUpload\Tests;

use AetherUpload\Storage\LocalStorage;
use AetherUpload\Storage\S3Storage;
use AetherUpload\Storage\Storage;
use AetherUpload\Tests\Support\TestState;
use PHPUnit\Framework\TestCase;

/**
 * Storage 门面：按 storage.driver 现造驱动（不缓存单例），unknown 值 fail-fast，inject/reset 仅供测试。
 */
class StorageTest extends TestCase
{
    private const PREFIX = 'plugin.erikwang2013.aetherupload-webman.app';

    protected function setUp(): void
    {
        TestState::reset();
        TestState::normalizeConfig();
        TestState::resetConfigMapper();
        Storage::reset();
    }

    protected function tearDown(): void
    {
        // 静态注入位必须还原，否则会串到其它测试类（既有 Controller/Resource 用例都走真实 driver）
        Storage::reset();
    }

    private function setConfig(string $key, $value): void
    {
        TestState::set(self::PREFIX . '.' . $key, $value);
        TestState::resetConfigMapper();
    }

    private function capture(callable $fn): ?\Throwable
    {
        try {
            $fn();
        } catch ( \Throwable $e ) {
            return $e;
        }

        return null;
    }

    public function testDriverDefaultsToLocalStorageWhenStorageConfigMissing(): void
    {
        $this->assertFalse(
            array_key_exists('storage', TestState::get(self::PREFIX)),
            '本用例的前提：默认配置里没有 storage 块（未配置 -> local）'
        );
        $this->assertInstanceOf(LocalStorage::class, Storage::driver());
    }

    public function testDriverBuildsS3StorageFromConfigWithoutNetwork(): void
    {
        $this->setConfig('storage.driver', 's3');
        $this->setConfig('storage.s3', [
            'endpoint' => 'http://127.0.0.1:9000',
            'region' => 'us-east-1',
            'bucket' => 'b',
            'access_key' => 'k',
            'secret_key' => 's',
            'path_style' => true,
            'prefix' => '',
            'multipart_threshold' => 104857600,
        ]);

        $this->assertInstanceOf(S3Storage::class, Storage::driver(), '构造不得发起网络调用（本用例无 fake transport 也不得失败）');
    }

    public function testUnknownDriverIsRejected(): void
    {
        $this->setConfig('storage.driver', 'bogus');

        $error = $this->capture(function () {
            Storage::driver();
        });

        $this->assertNotNull($error, '未知 driver 必须抛异常，不得静默回落到 local');
        $this->assertStringContainsString("Unknown storage driver 'bogus'", $error->getMessage());
    }

    public function testInjectOverridesDriverAndResetRestores(): void
    {
        $fake = new FakeStorageDriver();

        Storage::inject($fake);
        $this->assertSame($fake, Storage::driver());

        Storage::reset();
        $this->assertInstanceOf(LocalStorage::class, Storage::driver());
    }
}
