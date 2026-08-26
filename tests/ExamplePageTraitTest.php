<?php

namespace AetherUpload\Tests;

use AetherUpload\ConfigMapper;
use AetherUpload\Tests\Support\TestState;
use PHPUnit\Framework\TestCase;
use Webman\Http\Request;

class ExamplePageTraitTest extends TestCase
{
    protected function setUp(): void
    {
        TestState::reset();
        $this->normalizeConfig();
        $this->resetConfigMapper();
    }

    protected function tearDown(): void
    {
        remove_dir(TestState::$basePath);
    }

    /**
     * TestState::set() stores the plugin config as a nested tree under 'plugin',
     * while defaultConfig uses one flat dotted key that get() cannot resolve.
     * Move the flat key into the nested shape so config()/TestState::get() work.
     */
    private function normalizeConfig(): void
    {
        $flat = 'plugin.erikwang2013.aetherupload-webman.app';
        if ( ! isset(TestState::$config[$flat]) ) {
            return;
        }
        $node = &TestState::$config;
        foreach ( explode('.', $flat) as $segment ) {
            if ( ! is_array($node) ) {
                $node = [];
            }
            $node = &$node[$segment];
        }
        $node = TestState::$config[$flat];
        unset(TestState::$config[$flat]);
    }

    private function resetConfigMapper(): void
    {
        $ref = new \ReflectionClass(ConfigMapper::class);
        // setAccessible() required on PHP 8.0 for non-public properties (no-op on 8.1+)
        $property = $ref->getProperty('_instance');
        $property->setAccessible(true);
        $property->setValue(null, null);
    }

    private function page(): object
    {
        return new class {
            use \AetherUpload\ExamplePageTrait;
        };
    }

    public function testPostExamplePageReturnsHtmlContainingFile1AndRoutes()
    {
        TestState::$request = new Request(['file1' => 'file_202608_abc.gif']);

        $html = $this->page()->postExamplePage();

        $this->assertStringContainsString('file1', $html);
        $this->assertStringContainsString('/aetherupload/display', $html);
        $this->assertStringContainsString('/aetherupload/download', $html);
        $this->assertStringContainsString('file_202608_abc.gif', $html);
    }

    public function testExamplePageSourceReadsRealViewFile()
    {
        $source = $this->page()->examplePageSource();

        $this->assertNotEmpty($source);
        $this->assertStringContainsString('example.blade.php', $source);
        $this->assertStringContainsString('aetherupload-wrapper', $source);
    }

    public function testGetExamplePageContainsScriptReference()
    {
        $html = $this->page()->getExamplePage();

        $this->assertStringContainsString('aetherupload-all.js', $html);
        $this->assertStringContainsString('aetherupload-resource', $html);
        $this->assertStringContainsString('setPreprocessRoute', $html);
    }
}
