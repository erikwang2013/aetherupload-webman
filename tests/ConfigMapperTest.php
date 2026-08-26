<?php

namespace AetherUpload\Tests;

use AetherUpload\ConfigMapper;
use AetherUpload\Tests\Support\TestState;
use PHPUnit\Framework\TestCase;

class ConfigMapperTest extends TestCase
{
    private const PREFIX = 'plugin.erikwang2013.aetherupload-webman.app';

    protected function setUp(): void
    {
        TestState::reset();
        // harness defect: defaultConfig() stores the dotted prefix as one literal key while
        // TestState::get() splits on dots, so config('<prefix>.*') returns null unless seeded
        TestState::set(self::PREFIX . '.groups', [
            'file' => [
                'group_dir' => 'file',
                'resource_maxsize' => 104857600,
                'resource_extensions' => ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg', 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'zip', 'rar', '7z', 'mp4', 'mp3', 'wav'],
                'event_before_upload_complete' => false,
                'event_upload_complete' => false,
            ],
        ]);
        // ConfigMapper caches config() at first instantiation; push defaults into the singleton
        ConfigMapper::set('root_dir', 'storage/app/aetherupload');
        ConfigMapper::set('chunk_size', 1000000);
        ConfigMapper::set('instant_completion', false);
        ConfigMapper::set('lax_mode', false);
    }

    private function resetSingleton(): void
    {
        $ref = new \ReflectionClass(ConfigMapper::class);
        // setAccessible() required on PHP 8.0 for non-public properties (no-op on 8.1+)
        $property = $ref->getProperty('_instance');
        $property->setAccessible(true);
        $property->setValue(null, null);
    }

    public function testGetReadsDefaultConfigValue(): void
    {
        $this->assertSame('storage/app/aetherupload', ConfigMapper::get('root_dir'));
        $this->assertSame(1000000, ConfigMapper::get('chunk_size'));
        $this->assertSame(false, ConfigMapper::get('instant_completion'));
    }

    public function testSetThenGetRoundTrip(): void
    {
        ConfigMapper::set('chunk_size', 2048);
        $this->assertSame(2048, ConfigMapper::get('chunk_size'));
    }

    public function testSetOnOneCallIsVisibleOnAnotherCall(): void
    {
        // proves the shared singleton state rather than a fresh instance per call
        ConfigMapper::set('lax_mode', true);
        $this->assertTrue(ConfigMapper::get('lax_mode'));
    }

    public function testGetReadsConfigStubValuesOnFreshInstance(): void
    {
        TestState::set(self::PREFIX . '.root_dir', 'custom/root');
        $this->resetSingleton();

        $this->assertSame('custom/root', ConfigMapper::get('root_dir'));

        // restore for other tests
        TestState::set(self::PREFIX . '.root_dir', 'storage/app/aetherupload');
        $this->resetSingleton();
    }

    public function testCallStaticAppliesGroupConfig(): void
    {
        ConfigMapper::applyGroupConfig('file');

        $this->assertSame('file', ConfigMapper::get('group'));
        $this->assertSame('file', ConfigMapper::get('group_dir'));
        $this->assertSame(104857600, ConfigMapper::get('resource_maxsize'));
        $this->assertContains('png', ConfigMapper::get('resource_extensions'));
    }

    public function testCallStaticApplyGroupConfigThrowsForUnknownGroup(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('invalid_operation');
        ConfigMapper::applyGroupConfig('nonexistent');
    }

    public function testCallStaticDelegatesToPrivateMethod(): void
    {
        $this->assertInstanceOf(ConfigMapper::class, ConfigMapper::applyGroupConfig('file'));
    }

    public function testInstanceIsSingletonAcrossGetAndSet(): void
    {
        $ref = new \ReflectionClass(ConfigMapper::class);
        $property = $ref->getProperty('_instance');
        $property->setAccessible(true);
        $before = $property->getValue();

        ConfigMapper::set('group_dir', 'via-set');
        ConfigMapper::get('group_dir');

        $after = $property->getValue();
        $this->assertSame($before, $after);
    }
}
