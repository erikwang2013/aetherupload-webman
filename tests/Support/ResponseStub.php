<?php

namespace AetherUpload\Tests\Support;

/**
 * Minimal webman-compatible response object returned by the response()/json() stubs.
 */
class ResponseStub
{
    public int $status = 200;
    /** @var array<string,string> */
    public array $headers = [];
    public string $body = '';
    /** @var 'raw'|'json'|'file'|'download' */
    public string $type = 'raw';
    public string $filePath = '';
    public string $fileName = '';

    public function __construct(string $body = '', int $status = 200, array $headers = [])
    {
        $this->body = $body;
        $this->status = $status;
        $this->headers = $headers;
    }

    public static function file(string $path): self
    {
        $r = new self('', 200);
        $r->type = 'file';
        $r->filePath = $path;
        return $r;
    }

    public static function download(string $path, string $name): self
    {
        $r = new self('', 200);
        $r->type = 'download';
        $r->filePath = $path;
        $r->fileName = $name;
        return $r;
    }

    public function withHeader(string $name, string $value): self
    {
        $this->headers[$name] = $value;
        return $this;
    }

    public function getStatusCode(): int
    {
        return $this->status;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    public function getHeaderLine(string $name): string
    {
        return $this->headers[$name] ?? '';
    }
}
