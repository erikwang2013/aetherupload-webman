<?php

namespace AetherUpload\Tests;

use AetherUpload\ConfigMapper;
use AetherUpload\Tests\Support\TestState;
use PHPUnit\Framework\TestCase;

class HelpersTest extends TestCase
{
    protected function setUp(): void
    {
        TestState::reset();
        // ConfigMapper caches config() at first instantiation; push defaults into the singleton
        ConfigMapper::set('route_display', '/aetherupload/display');
        ConfigMapper::set('route_download', '/aetherupload/download');
    }

    public function testDisplayLinkDelegatesToUtilWithDefaultRoute(): void
    {
        $this->assertSame(
            '/aetherupload/display/file_201701_abc.jpg',
            aetherupload_display_link('file_201701_abc.jpg')
        );
    }

    public function testDisplayLinkFollowsConfiguredRoute(): void
    {
        ConfigMapper::set('route_display', '/changed/display');
        $this->assertSame('/changed/display/a_b', aetherupload_display_link('a_b'));
    }

    public function testDownloadLinkDelegatesToUtilWithDefaultRoute(): void
    {
        $this->assertSame(
            '/aetherupload/download/file_201701_abc.jpg/new.jpg',
            aetherupload_download_link('file_201701_abc.jpg', 'new.jpg')
        );
    }

    public function testDownloadLinkFollowsConfiguredRoute(): void
    {
        ConfigMapper::set('route_download', '/changed/download');
        $this->assertSame('/changed/download/a_b/c.jpg', aetherupload_download_link('a_b', 'c.jpg'));
    }
}
