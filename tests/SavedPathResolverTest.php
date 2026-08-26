<?php

namespace AetherUpload\Tests;

use AetherUpload\SavedPathResolver;
use AetherUpload\Tests\Support\TestState;
use PHPUnit\Framework\TestCase;

class SavedPathResolverTest extends TestCase
{
    protected function setUp(): void
    {
        TestState::reset();
    }

    public function testEncodeJoinsGroupSubdirAndNameWithUnderscores(): void
    {
        $this->assertSame('file_201701_abc.jpg', SavedPathResolver::encode('file', '201701', 'abc.jpg'));
    }

    public function testDecodeSplitsEncodedPathIntoParts(): void
    {
        $parts = SavedPathResolver::decode('file_201701_abc.jpg');

        $this->assertSame('file', $parts->group);
        $this->assertSame('201701', $parts->groupSubDir);
        $this->assertSame('abc.jpg', $parts->resourceName);
    }

    public function testDecodeKeepsUnderscoresInLastSegment(): void
    {
        // explode limit 3: the remainder becomes the resource name even if it contains '_'
        $parts = SavedPathResolver::decode('file_201701_abc_def.jpg');

        $this->assertSame('file', $parts->group);
        $this->assertSame('201701', $parts->groupSubDir);
        $this->assertSame('abc_def.jpg', $parts->resourceName);
    }

    public function testDecodeThrowsWhenFewerThanThreeSegments(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('invalid_operation');
        SavedPathResolver::decode('file_201701');
    }

    public function testDecodeThrowsOnEmptySegment(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('invalid_operation');
        SavedPathResolver::decode('file__abc.jpg');
    }

    public function testDecodeThrowsOnPathTraversalSegment(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('invalid_operation');
        SavedPathResolver::decode('file_.._abc.jpg');
    }

    public function testDecodeThrowsOnSlashSegment(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('invalid_operation');
        SavedPathResolver::decode('file_2017/01_abc.jpg');
    }

    public function testDecodeThrowsOnPercentSegment(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('invalid_operation');
        SavedPathResolver::decode('file_20%01_abc.jpg');
    }

    public function testDecodeThrowsOnNonAsciiSegment(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('invalid_operation');
        SavedPathResolver::decode('file_文件_abc.jpg');
    }

    public function testDecodeAllowsDotsInsideSegmentAfterFirstChar(): void
    {
        $parts = SavedPathResolver::decode('file_2017.01_abc.jpg');

        $this->assertSame('2017.01', $parts->groupSubDir);
        $this->assertSame('abc.jpg', $parts->resourceName);
    }

    public function testDecodeAllowsDashInsideSegment(): void
    {
        $parts = SavedPathResolver::decode('my-group_2017-01_abc.jpg');

        $this->assertSame('my-group', $parts->group);
        $this->assertSame('2017-01', $parts->groupSubDir);
    }
}
