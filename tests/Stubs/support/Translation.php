<?php

namespace support;

use AetherUpload\Tests\Support\TestState;

/**
 * Records addResource calls instead of loading translation files.
 */
class Translation
{
    public static function addResource(...$args): void
    {
        TestState::$translationResources[] = $args;
    }
}
