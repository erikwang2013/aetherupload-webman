<?php

namespace AetherUpload\Tests;

use AetherUpload\Responser;
use AetherUpload\Tests\Support\ResponseStub;
use AetherUpload\Tests\Support\TestState;
use PHPUnit\Framework\TestCase;

class ResponserTest extends TestCase
{
    protected function setUp(): void
    {
        TestState::reset();
    }

    public function testReturnResultReturnsJsonResponseWithResult(): void
    {
        $response = Responser::returnResult(['result' => 'success']);

        $this->assertInstanceOf(ResponseStub::class, $response);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('{"result":"success"}', $response->getBody());
    }

    public function testReportErrorAppendsErrorFieldToResult(): void
    {
        $response = Responser::reportError(['result' => 'fail'], 'invalid_operation');

        $this->assertInstanceOf(ResponseStub::class, $response);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('{"result":"fail","error":"invalid_operation"}', $response->getBody());
    }

    public function testReportErrorKeepsOriginalFields(): void
    {
        $response = Responser::reportError(['a' => 1, 'b' => 'two'], 'oops');

        $body = json_decode($response->getBody(), true);
        $this->assertSame(1, $body['a']);
        $this->assertSame('two', $body['b']);
        $this->assertSame('oops', $body['error']);
    }

    public function testReportErrorOverwritesExistingErrorKey(): void
    {
        $response = Responser::reportError(['error' => 'old'], 'new');

        $body = json_decode($response->getBody(), true);
        $this->assertSame('new', $body['error']);
    }
}
