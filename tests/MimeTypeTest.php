<?php

namespace AetherUpload\Tests;

use AetherUpload\ConfigMapper;
use AetherUpload\MimeType;
use AetherUpload\Tests\Support\TestState;
use PHPUnit\Framework\TestCase;

class MimeTypeTest extends TestCase
{
    protected function setUp(): void
    {
        TestState::reset();
        ConfigMapper::set('extra_mime_types', []);
    }

    public function testFromReturnsMimeByFileExtension(): void
    {
        $this->assertSame('image/jpeg', MimeType::from('photo.jpg'));
        $this->assertSame('application/pdf', MimeType::from('document.pdf'));
    }

    public function testFromIsCaseSensitiveOnExtension(): void
    {
        $this->assertSame('application/octet-stream', MimeType::from('photo.JPG'));
    }

    public function testFromReturnsOctetStreamForUnknownExtension(): void
    {
        $this->assertSame('application/octet-stream', MimeType::from('archive.unknown-ext'));
    }

    public function testFromReturnsOctetStreamForFileWithoutExtension(): void
    {
        $this->assertSame('application/octet-stream', MimeType::from('README'));
    }

    public function testFromReturnsOctetStreamForPhpScript(): void
    {
        $this->assertSame('application/octet-stream', MimeType::from('shell.php'));
    }

    public function testGetReturnsMimeForKnownExtension(): void
    {
        $this->assertSame('image/png', MimeType::get('png'));
        $this->assertSame('audio/mpeg', MimeType::get('mp3'));
    }

    public function testGetReturnsOctetStreamForUnknownExtension(): void
    {
        $this->assertSame('application/octet-stream', MimeType::get('zzz'));
    }

    public function testGetWithoutArgumentReturnsAllMimes(): void
    {
        $all = MimeType::get();
        $this->assertIsArray($all);
        $this->assertArrayHasKey('jpg', $all);
        $this->assertArrayHasKey('jpeg', $all);
        $this->assertSame('image/jpeg', $all['jpg']);
    }

    public function testSearchReturnsFirstMatchingExtensionKey(): void
    {
        // 'jpg' appears before 'jpeg' in the mimes map, both map to image/jpeg
        $this->assertSame('jpg', MimeType::search('image/jpeg'));
    }

    public function testSearchReturnsNullForUnknownMime(): void
    {
        $this->assertNull(MimeType::search('application/definitely-unknown'));
    }

    public function testSearchMergesExtraMimeTypesFromConfig(): void
    {
        ConfigMapper::set('extra_mime_types', ['apk' => 'application/vnd.android.package-archive']);
        $this->assertSame('apk', MimeType::search('application/vnd.android.package-archive'));
    }

    public function testSearchPrefersBuiltInKeyOverExtraMimeType(): void
    {
        ConfigMapper::set('extra_mime_types', ['jpeg' => 'image/jpeg']);
        $this->assertSame('jpg', MimeType::search('image/jpeg'));
    }
}
