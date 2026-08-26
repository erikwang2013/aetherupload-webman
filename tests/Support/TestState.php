<?php

namespace AetherUpload\Tests\Support;

/**
 * Central mutable state backing the global helper stubs (config/trans/base_path/request/response).
 * Tests mutate this state directly; helpers read it.
 */
final class TestState
{
    /** @var array full nested config tree, keyed by dotted paths */
    public static array $config = [];

    /** @var object|null current request returned by request() */
    public static ?object $request = null;

    /** @var string directory returned by base_path() */
    public static string $basePath = '';

    /** @var array [hashKey => [field => value]] in-memory Redis store */
    public static array $redisHash = [];

    /** @var array [hashKey => seconds] recorded expire() calls */
    public static array $redisExpireCalls = [];

    /** @var array [['name' => string, 'data' => mixed], ...] emitted events */
    public static array $events = [];

    /** @var array recorded support\Translation::addResource calls */
    public static array $translationResources = [];

    /** @var string last locale passed to locale() */
    public static string $locale = '';

    public static function reset(): void
    {
        self::$config = self::defaultConfig();
        self::$request = null;
        self::$basePath = sys_get_temp_dir() . '/aetherupload-tests-' . bin2hex(random_bytes(4));
        self::$redisHash = [];
        self::$redisExpireCalls = [];
        self::$events = [];
        self::$translationResources = [];
        self::$locale = '';
    }

    public static function get(string $key, $default = null)
    {
        $segments = explode('.', $key);
        $node = self::$config;
        foreach ( $segments as $segment ) {
            if ( is_array($node) && array_key_exists($segment, $node) ) {
                $node = $node[$segment];
            } else {
                return $default;
            }
        }
        return $node;
    }

    public static function set(string $key, $value): void
    {
        $segments = explode('.', $key);
        $node = &self::$config;
        foreach ( $segments as $segment ) {
            if ( ! is_array($node) ) {
                $node = [];
            }
            $node = &$node[$segment];
        }
        $node = $value;
    }

    public static function resetRedis(): void
    {
        self::$redisHash = [];
        self::$redisExpireCalls = [];
    }

    public static function defaultConfig(): array
    {
        $app = [
            'instant_completion' => false,
            'resource_redis_expire' => 604800,
            'root_dir' => 'storage/app/aetherupload',
            'chunk_size' => 1000000,
            'resource_subdir_rule' => 'month',
            'forbidden_extensions' => ['php', 'part', 'html', 'shtml', 'htm', 'shtm', 'xhtml', 'xml', 'js', 'jsp', 'asp', 'java', 'py', 'sh', 'bat', 'exe', 'dll', 'cgi', 'htaccess', 'reg', 'aspx', 'vbs'],
            'extra_mime_types' => [],
            'middleware_preprocess' => [],
            'middleware_uploading' => [],
            'middleware_display' => [],
            'middleware_download' => [],
            'route_preprocess' => '/aetherupload/preprocess',
            'route_uploading' => '/aetherupload/uploading',
            'route_display' => '/aetherupload/display',
            'route_download' => '/aetherupload/download',
            'lax_mode' => false,
            'groups' => [
                'file' => [
                    'group_dir' => 'file',
                    'resource_maxsize' => 104857600,
                    'resource_extensions' => ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg', 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'zip', 'rar', '7z', 'mp4', 'mp3', 'wav'],
                    'event_before_upload_complete' => false,
                    'event_upload_complete' => false,
                ],
            ],
        ];
        return [
            'app' => ['debug' => true],
            'translation' => ['path' => sys_get_temp_dir() . '/aetherupload-translations'],
            'plugin' => ['erikwang2013' => ['aetherupload-webman' => ['app' => $app]]],
        ];
    }
}
