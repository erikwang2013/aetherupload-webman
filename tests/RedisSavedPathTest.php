<?php

namespace AetherUpload\Tests;

use AetherUpload\RedisSavedPath;
use AetherUpload\Tests\Support\TestState;
use PHPUnit\Framework\TestCase;

class RedisSavedPathTest extends TestCase
{
    private const HASH_KEY = 'aetherupload_resource';
    private const PREFIX = 'plugin.erikwang2013.aetherupload-webman.app.';

    protected function setUp(): void
    {
        TestState::reset();
    }

    public function testTtlUsesDefaultExpireSeconds(): void
    {
        $this->assertSame(604800, RedisSavedPath::ttl());
    }

    public function testTtlReadsConfigOverride(): void
    {
        TestState::set(self::PREFIX . 'resource_redis_expire', 3600);

        $this->assertSame(3600, RedisSavedPath::ttl());
    }

    public function testTtlFallsBackToConstantWhenConfigKeyAbsent(): void
    {
        // 移除整个 plugin 配置树后 config() 取不到该键，回落常量
        unset(TestState::$config['plugin']);

        $this->assertSame(604800, RedisSavedPath::ttl());
    }

    public function testGetKeyJoinsGroupAndHash(): void
    {
        $this->assertSame('file_abc123', RedisSavedPath::getKey('file', 'abc123'));
    }

    public function testGetKeyRejectsMalformedHash(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('invalid_operation');

        RedisSavedPath::getKey('file', 'bad hash');
    }

    public function testExistsReturnsTrueForStoredField(): void
    {
        TestState::$redisHash[self::HASH_KEY]['file_hash1'] = 'storage/app/aetherupload/file/202608/a.bin';

        $this->assertTrue(RedisSavedPath::exists('file_hash1'));
    }

    public function testExistsReturnsFalseForMissingField(): void
    {
        $this->assertFalse(RedisSavedPath::exists('file_hash1'));
    }

    public function testGetReturnsStoredPath(): void
    {
        TestState::$redisHash[self::HASH_KEY]['file_hash1'] = 'storage/app/aetherupload/file/202608/a.bin';

        $this->assertSame('storage/app/aetherupload/file/202608/a.bin', RedisSavedPath::get('file_hash1'));
    }

    public function testGetMissingFieldThrowsReadError(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('read error');

        RedisSavedPath::get('file_hash1');
    }

    public function testSetStoresPathAndAppliesExpire(): void
    {
        $this->assertTrue(RedisSavedPath::set('file_hash1', 'storage/app/aetherupload/file/202608/a.bin'));

        $this->assertSame('storage/app/aetherupload/file/202608/a.bin', TestState::$redisHash[self::HASH_KEY]['file_hash1']);
        $this->assertSame([self::HASH_KEY => 604800], TestState::$redisExpireCalls);
    }

    public function testSetOverwritesExistingAndRenewsExpire(): void
    {
        RedisSavedPath::set('file_hash1', 'old-path');
        TestState::$redisExpireCalls = [];
        TestState::set(self::PREFIX . 'resource_redis_expire', 7200);

        $this->assertTrue(RedisSavedPath::set('file_hash1', 'new-path'));

        $this->assertSame('new-path', TestState::$redisHash[self::HASH_KEY]['file_hash1']);
        $this->assertSame([self::HASH_KEY => 7200], TestState::$redisExpireCalls);
    }

    public function testSetMultiStoresAllAndAppliesExpire(): void
    {
        $this->assertTrue(RedisSavedPath::setMulti([
            'file_hash1' => 'storage/app/aetherupload/file/202608/a.bin',
            'file_hash2' => 'storage/app/aetherupload/file/202608/b.bin',
        ]));

        $this->assertSame('storage/app/aetherupload/file/202608/a.bin', TestState::$redisHash[self::HASH_KEY]['file_hash1']);
        $this->assertSame('storage/app/aetherupload/file/202608/b.bin', TestState::$redisHash[self::HASH_KEY]['file_hash2']);
        $this->assertSame([self::HASH_KEY => 604800], TestState::$redisExpireCalls);
    }

    public function testDeleteRemovesExistingField(): void
    {
        TestState::$redisHash[self::HASH_KEY]['file_hash1'] = 'path';

        $this->assertTrue(RedisSavedPath::delete('file_hash1'));

        $this->assertArrayNotHasKey('file_hash1', TestState::$redisHash[self::HASH_KEY]);
    }

    public function testDeleteMissingFieldThrowsDeleteError(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('delete error');

        RedisSavedPath::delete('file_hash1');
    }

    public function testDeleteAllClearsWholeHash(): void
    {
        TestState::$redisHash[self::HASH_KEY]['file_hash1'] = 'path';

        $this->assertTrue(RedisSavedPath::deleteAll());

        $this->assertArrayNotHasKey(self::HASH_KEY, TestState::$redisHash);
    }
}
