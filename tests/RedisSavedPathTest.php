<?php

namespace AetherUpload\Tests;

use AetherUpload\RedisSavedPath;
use AetherUpload\Tests\Support\TestState;
use PHPUnit\Framework\TestCase;

class RedisSavedPathTest extends TestCase
{
    // 旧版单 hash 存储，仅回退读取与清理用
    private const HASH_KEY = 'aetherupload_resource';
    // 新版每条记录一个 key
    private const KEY_PREFIX = 'aetherupload:resource:';
    private const PREFIX = 'plugin.erikwang2013.aetherupload-webman.app.';
    private const PATH = 'storage/app/aetherupload/file/202608/a.bin';

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

    // ---------- set：一条记录一个 key ----------

    public function testSetStoresRecordUnderItsOwnKey(): void
    {
        $this->assertTrue(RedisSavedPath::set('file_hash1', self::PATH));

        // key 格式是公开契约，用字面量锁定
        $this->assertSame(self::PATH, TestState::$redisStrings['aetherupload:resource:file_hash1']);
        $this->assertSame([], TestState::$redisExpireCalls);
    }

    public function testSetAppliesTtlToItsOwnKey(): void
    {
        RedisSavedPath::set('file_hash1', self::PATH);

        $this->assertSame([self::KEY_PREFIX . 'file_hash1' => 604800], TestState::$redisStringExpire);
    }

    public function testEachRecordKeepsItsOwnTtl(): void
    {
        RedisSavedPath::set('file_hash1', self::PATH);
        TestState::set(self::PREFIX . 'resource_redis_expire', 7200);
        RedisSavedPath::set('file_hash2', 'storage/app/aetherupload/file/202608/b.bin');

        // 后写入的记录不会改写先前记录的 TTL
        $this->assertSame(604800, TestState::$redisStringExpire[self::KEY_PREFIX . 'file_hash1']);
        $this->assertSame(7200, TestState::$redisStringExpire[self::KEY_PREFIX . 'file_hash2']);
    }

    public function testSetOverwritesExistingRecordAndRenewsTtl(): void
    {
        RedisSavedPath::set('file_hash1', 'old-path');
        TestState::set(self::PREFIX . 'resource_redis_expire', 7200);

        $this->assertTrue(RedisSavedPath::set('file_hash1', 'new-path'));

        $this->assertSame('new-path', TestState::$redisStrings[self::KEY_PREFIX . 'file_hash1']);
        $this->assertSame(7200, TestState::$redisStringExpire[self::KEY_PREFIX . 'file_hash1']);
    }

    public function testSetMultiStoresEveryRecordUnderItsOwnKey(): void
    {
        $this->assertTrue(RedisSavedPath::setMulti([
            'file_hash1' => self::PATH,
            'file_hash2' => 'storage/app/aetherupload/file/202608/b.bin',
        ]));

        $this->assertSame(self::PATH, TestState::$redisStrings[self::KEY_PREFIX . 'file_hash1']);
        $this->assertSame('storage/app/aetherupload/file/202608/b.bin', TestState::$redisStrings[self::KEY_PREFIX . 'file_hash2']);
        $this->assertSame([
            self::KEY_PREFIX . 'file_hash1' => 604800,
            self::KEY_PREFIX . 'file_hash2' => 604800,
        ], TestState::$redisStringExpire);
    }

    // ---------- get：新 key 优先，回退旧 hash ----------

    public function testGetReturnsStoredRecord(): void
    {
        RedisSavedPath::set('file_hash1', self::PATH);

        $this->assertSame(self::PATH, RedisSavedPath::get('file_hash1'));
    }

    public function testGetFallsBackToLegacyHashField(): void
    {
        TestState::$redisHash[self::HASH_KEY]['file_hash1'] = self::PATH;

        $this->assertSame(self::PATH, RedisSavedPath::get('file_hash1'));
    }

    public function testGetPrefersNewKeyOverLegacyHashField(): void
    {
        TestState::$redisHash[self::HASH_KEY]['file_hash1'] = 'legacy-path';
        RedisSavedPath::set('file_hash1', self::PATH);

        $this->assertSame(self::PATH, RedisSavedPath::get('file_hash1'));
    }

    public function testGetMissingRecordThrowsReadError(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('read error');

        RedisSavedPath::get('file_hash1');
    }

    // ---------- exists：两种存储都认 ----------

    public function testExistsReturnsTrueForStoredRecord(): void
    {
        RedisSavedPath::set('file_hash1', self::PATH);

        $this->assertTrue(RedisSavedPath::exists('file_hash1'));
    }

    public function testExistsReturnsTrueForLegacyHashField(): void
    {
        TestState::$redisHash[self::HASH_KEY]['file_hash1'] = self::PATH;

        $this->assertTrue(RedisSavedPath::exists('file_hash1'));
    }

    public function testExistsReturnsFalseForMissingRecord(): void
    {
        $this->assertFalse(RedisSavedPath::exists('file_hash1'));
    }

    // ---------- delete：幂等，两种存储都清 ----------

    public function testDeleteRemovesRecordAndLegacyHashField(): void
    {
        RedisSavedPath::set('file_hash1', self::PATH);
        TestState::$redisHash[self::HASH_KEY]['file_hash1'] = self::PATH;

        $this->assertTrue(RedisSavedPath::delete('file_hash1'));

        $this->assertArrayNotHasKey(self::KEY_PREFIX . 'file_hash1', TestState::$redisStrings);
        $this->assertArrayNotHasKey('file_hash1', TestState::$redisHash[self::HASH_KEY]);
    }

    public function testDeleteIsIdempotentForMissingRecord(): void
    {
        $this->assertTrue(RedisSavedPath::delete('file_hash1'));
        $this->assertTrue(RedisSavedPath::delete('file_hash1'));
    }

    // ---------- deleteAll：清空两种存储 ----------

    public function testDeleteAllClearsBothStorages(): void
    {
        RedisSavedPath::set('file_hash1', self::PATH);
        RedisSavedPath::set('file_hash2', self::PATH);
        TestState::$redisHash[self::HASH_KEY]['file_hash1'] = self::PATH;
        TestState::$redisStrings['unrelated:key'] = 'keep-me';

        $this->assertTrue(RedisSavedPath::deleteAll());

        $this->assertArrayNotHasKey(self::HASH_KEY, TestState::$redisHash);
        $this->assertArrayNotHasKey(self::KEY_PREFIX . 'file_hash1', TestState::$redisStrings);
        $this->assertArrayNotHasKey(self::KEY_PREFIX . 'file_hash2', TestState::$redisStrings);
        $this->assertArrayNotHasKey(self::KEY_PREFIX . 'file_hash1', TestState::$redisStringExpire);
        // 前缀之外的数据不受影响
        $this->assertSame('keep-me', TestState::$redisStrings['unrelated:key']);
        $this->assertFalse(RedisSavedPath::exists('file_hash1'));
    }
}
