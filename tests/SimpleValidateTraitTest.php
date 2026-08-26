<?php

namespace AetherUpload\Tests;

use AetherUpload\Tests\Support\TestState;
use PHPUnit\Framework\TestCase;
use Webman\Http\Request;

class SimpleValidateStub
{
    use \AetherUpload\SimpleValidateTrait;
}

class SimpleValidateTraitTest extends TestCase
{
    protected function setUp(): void
    {
        TestState::reset();
    }

    private function validate(array $inputs, array $rules): bool
    {
        return (new SimpleValidateStub())->validatedWithError(new Request($inputs), $rules);
    }

    public function testRequiredReportsErrorWhenKeyMissing(): void
    {
        $this->assertTrue($this->validate([], ['name' => 'required']));
    }

    public function testRequiredReportsErrorWhenValueIsEmptyString(): void
    {
        $this->assertTrue($this->validate(['name' => ''], ['name' => 'required']));
    }

    public function testRequiredReportsErrorWhenValueIsNull(): void
    {
        $this->assertTrue($this->validate(['name' => null], ['name' => 'required']));
    }

    public function testRequiredPassesWhenValuePresent(): void
    {
        $this->assertFalse($this->validate(['name' => 'x'], ['name' => 'required']));
    }

    public function testPresentReportsErrorWhenKeyMissing(): void
    {
        $this->assertTrue($this->validate([], ['name' => 'present']));
    }

    public function testPresentPassesWithEmptyStringValue(): void
    {
        $this->assertFalse($this->validate(['name' => ''], ['name' => 'present']));
    }

    public function testPresentReportsErrorWhenValueIsNull(): void
    {
        // explicit null is indistinguishable from a missing key via isset()
        $this->assertTrue($this->validate(['name' => null], ['name' => 'present']));
    }

    public function testReportsErrorWhenAnyRuleInListFails(): void
    {
        $this->assertTrue($this->validate(
            ['a' => 'ok'],
            ['a' => 'required', 'b' => 'present']
        ));
    }

    public function testPassesWhenAllRulesSatisfied(): void
    {
        $this->assertFalse($this->validate(
            ['a' => 'ok', 'b' => ''],
            ['a' => 'required', 'b' => 'present']
        ));
    }
}
