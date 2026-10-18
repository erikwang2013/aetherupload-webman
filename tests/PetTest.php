<?php

namespace AetherUpload\Tests;

use AetherUpload\Pet;
use PHPUnit\Framework\TestCase;

class PetTest extends TestCase
{
    public function testExpressionsMatchShippedArtwork()
    {
        $this->assertSame([
            'smile',
            'joy',
            'cry',
            'depressed',
            'terrified',
            'angry',
            'coquettish',
            'cute',
            'pleasing',
            'knocking',
            'facepalm',
            'tongue',
        ], Pet::names());

        foreach ( Pet::names() as $name ) {
            $this->assertFileExists(__DIR__ . '/../docs/js/pet-expressions/' . $name . '.svg');
        }
    }

    public function testUrlReturnsPublishedAssetPath()
    {
        $this->assertSame(
            '/vendor/aetherupload/js/pet-expressions/smile.svg',
            Pet::url('smile')
        );
    }

    public function testUrlRejectsUnknownExpression()
    {
        $this->expectException(\InvalidArgumentException::class);

        Pet::url('unknown');
    }

    public function testUploadStatesMapToKnownExpressions()
    {
        $states = Pet::stateExpressions();

        $this->assertSame('smile', $states['idle']);
        $this->assertSame('cute', $states['begin']);
        $this->assertSame('pleasing', $states['hashing']);
        $this->assertSame('knocking', $states['uploading']);
        $this->assertSame('terrified', $states['retry']);
        $this->assertSame('joy', $states['instant']);
        $this->assertSame('joy', $states['success']);
        $this->assertSame('cry', $states['error']);

        foreach ( $states as $expression ) {
            $this->assertContains($expression, Pet::names());
        }
    }

    public function testUnknownStateFallsBackToSmile()
    {
        $this->assertSame('smile', Pet::expressionForState('unknown'));
    }

    public function testExpressionLabelsCoverAllForms()
    {
        $labels = Pet::expressionLabels();

        $this->assertCount(12, $labels);
        $this->assertSame('吐舌头', $labels['tongue']);
    }
}
