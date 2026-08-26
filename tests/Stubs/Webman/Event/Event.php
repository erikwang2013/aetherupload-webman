<?php

namespace Webman\Event;

use AetherUpload\Tests\Support\TestState;

/**
 * Records emitted events into TestState instead of dispatching.
 */
class Event
{
    public static function emit(string $name, $data = null): void
    {
        TestState::$events[] = ['name' => $name, 'data' => $data];
    }
}
