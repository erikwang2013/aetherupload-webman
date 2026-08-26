<?php

namespace Webman\Http;

/**
 * Minimal stand-in for webman's Request, backing request() in tests.
 */
class Request
{
    /** @var array<string,mixed> */
    public array $inputs;

    /** @var array<string,object> uploaded file objects (isValid/getRealPath) */
    public array $files;

    public function __construct(array $inputs = [], array $files = [])
    {
        $this->inputs = $inputs;
        $this->files = $files;
    }

    public function input(string $name, $default = null)
    {
        return array_key_exists($name, $this->inputs) ? $this->inputs[$name] : $default;
    }

    public function file(string $name)
    {
        return $this->files[$name] ?? null;
    }

    public function all(): array
    {
        return $this->inputs;
    }
}
