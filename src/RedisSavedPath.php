<?php

namespace AetherUpload;

use support\Redis;

class RedisSavedPath
{

    // 秒传记录过期时间（秒，7 天），可用 config 键 resource_redis_expire 覆盖
    const EXPIRE_SECONDS = 604800;

    // 每条记录独立一个 key：aetherupload:resource:<group>_<hash>，TTL 各自独立
    const KEY_PREFIX = 'aetherupload:resource:';

    // 旧版把所有记录放在单个 hash 里，现已废弃，仅用于回退读取与清理
    const LEGACY_HASH_KEY = 'aetherupload_resource';

    public static function ttl()
    {
        return (int)config(ConfigMapper::PREFIX.'resource_redis_expire', self::EXPIRE_SECONDS);
    }

    public static function exists($key)
    {
        if ( Redis::exists(self::KEY_PREFIX.$key) ) {
            return true;
        }

        $result = Redis::hexists(self::LEGACY_HASH_KEY, $key);

        // predis 返回 0/1，phpredis 返回 bool
        if ( $result === 1 || $result === true ) {
            return true;
        } elseif ( $result === 0 || $result === false ) {
            return false;
        } else {
            throw new \Exception('exists error');
        }
    }

    public static function get($key)
    {
        $result = Redis::get(self::KEY_PREFIX.$key);

        // predis 未命中返回 null，phpredis 返回 false
        if ( $result !== null && $result !== false ) {
            return $result;
        }

        // 回退旧 hash，未执行 aetherupload:build 的历史数据仍可读到
        $result = Redis::hget(self::LEGACY_HASH_KEY, $key);

        if ( $result === null || $result === false ) {
            throw new \Exception('read error');
        }

        return $result;
    }

    public static function set($key, $savedPath)
    {
        Redis::setex(self::KEY_PREFIX.$key, self::ttl(), $savedPath);

        return true;
    }

    public static function setMulti($keyArr)
    {
        $ttl = self::ttl();

        foreach ( $keyArr as $key => $savedPath ) {
            Redis::setex(self::KEY_PREFIX.$key, $ttl, $savedPath);
        }

        return true;
    }

    public static function delete($key)
    {
        Redis::del(self::KEY_PREFIX.$key);

        Redis::hdel(self::LEGACY_HASH_KEY, $key);

        // 幂等：记录不存在也视为删除成功
        return true;
    }

    public static function deleteAll()
    {
        Redis::del(self::LEGACY_HASH_KEY);

        // ponytail: KEYS 会一次性阻塞 Redis，换取全客户端可用（scan() 在 predis 与 phpredis 上的调用形态不一致）。
        // 这里只在每日执行的 aetherupload:build 命令里调用，记录数在百万级以内可接受；真到那个量级再换 SCAN + Lua 批量删除。
        foreach ( Redis::keys(self::KEY_PREFIX.'*') as $key ) {
            Redis::del($key);
        }

        return true;
    }

    public static function getKey($group, $hash)
    {
        if ( Util::isSafePathComponent($hash, false, 64) === false ) {
            throw new \Exception(trans('invalid_operation'));
        }

        return $group . '_' . $hash;
    }


}
