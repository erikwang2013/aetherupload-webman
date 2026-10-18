<?php

namespace AetherUpload\Tests;

use AetherUpload\Tests\Support\TestState;
use PHPUnit\Framework\TestCase;
use Webman\Http\Request;

class ExamplePageTraitTest extends TestCase
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

    public function testGetExamplePageIntegratesPetExpressions()
    {
        $html = $this->page()->getExamplePage();

        $this->assertStringContainsString('/vendor/aetherupload/js/pet-expressions/smile.svg', $html);
        $this->assertStringContainsString('id="aetherupload-pet"', $html);
        $this->assertStringContainsString('aetherupload:state', $html);
        $this->assertStringContainsString('"uploading":"knocking"', $html);
    }

    public function testExamplePageSourceShowsPetIntegration()
    {
        $source = $this->page()->examplePageSource();

        $this->assertStringContainsString('aetherupload-pet', $source);
        $this->assertStringContainsString('aetherupload:state', $source);
    }
}
