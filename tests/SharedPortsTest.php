<?php

namespace AetherUpload\Tests;

use AetherUpload\Kernel\BasePaths;
use AetherUpload\Kernel\ClientRedis;
use AetherUpload\Kernel\PhpFileTranslator;
use AetherUpload\Tests\Support\TestState;
use Webman\Http\Request;
use PHPUnit\Framework\TestCase;

/**
 * Kernel 里那几个「宿主什么都没有」时用的共享端口。
 *
 * 它们不属于任何单一适配器（Slim 与原生 PHP 都在用），单元套件因此必须自己盖住 ——
 * 光靠某个框架的端到端套件守，改坏了要等那条 CI job 才暴露，而且两个宿主一起坏。
 */
class SharedPortsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        TestState::reset();
    }

    // ------------------------------------------------------- PhpFileTranslator

    /** 真装载包内语言文件：命中的给译文，未命中的给 key（同一门语言的两种结果） */
    public function testTranslatorLoadsPackMessages(): void
    {
        $translator = new PhpFileTranslator();
        $translator->setLocale('en');
        $translator->loadMessages(dirname(__DIR__) . '/translations', 'en');

        $this->assertSame('Error: error occurs during upload', $translator->trans('upload_error'), '已装载的语种必须给译文');
        $this->assertSame('definitely_missing_key', $translator->trans('definitely_missing_key'), '未命中必须返回 key 本身');
    }

    /** 没有该语种（目录不存在）时装载空表：trans() 一律返回 key，不抛异常 */
    public function testTranslatorWithoutMessagesFallsBackToKey(): void
    {
        $translator = new PhpFileTranslator();
        $translator->setLocale('zz');
        $translator->loadMessages(dirname(__DIR__) . '/translations', 'zz');

        $this->assertSame('upload_error', $translator->trans('upload_error'));
    }

    /** 语种存在 RequestContext 里：上下文一换就复位，不跨请求泄漏 */
    public function testLocaleIsScopedToContext(): void
    {
        $translator = new PhpFileTranslator();

        $translator->setLocale('zh');
        $this->assertSame('zh', $translator->getLocale());

        // 「换了一个请求」＝ Runtime::context() 认的执行上下文身份变了。
        // 测试里绑的是 webman 适配器，它的 contextToken() 就是当前请求对象（TestState::$request），
        // 所以换一个请求实例即可触发上下文切换 —— 常驻进程/协程下发生的就是这件事。
        TestState::$request = new Request();

        $this->assertSame('en', $translator->getLocale(), '换了请求之后语种必须复位，不能跨请求泄漏');
    }

    /** loadMessages 幂等：同一份文件重复登记不重新解析，且不会把已装载的其它语种顶掉 */
    public function testLoadMessagesIsIdempotent(): void
    {
        $translator = new PhpFileTranslator();
        $translator->setLocale('en');

        $translator->loadMessages(dirname(__DIR__) . '/translations', 'en');
        $translator->loadMessages(dirname(__DIR__) . '/translations', 'en');

        $this->assertSame('Error: error occurs during upload', $translator->trans('upload_error'));

        $translator->setLocale('zh');
        $translator->loadMessages(dirname(__DIR__) . '/translations', 'zh');

        $this->assertNotSame('upload_error', $translator->trans('upload_error'), '中文语言文件同样已装载');
    }

    // ------------------------------------------------------------- ClientRedis

    /** 8 个命令原样转发，**不做归一化**（predis 与 phpredis 的返回类型本就不同） */
    public function testClientRedisForwardsVerbatim(): void
    {
        $fake = new class {
            public $calls = [];

            public function exists($key) { $this->calls[] = ['exists', $key]; return 1; }
            public function hexists($key, $field) { $this->calls[] = ['hexists', $key, $field]; return false; }
            public function get($key) { $this->calls[] = ['get', $key]; return null; }
            public function hget($key, $field) { $this->calls[] = ['hget', $key, $field]; return false; }
            public function setex($key, $seconds, $value) { $this->calls[] = ['setex', $key, $seconds, $value]; return true; }
            public function del($key) { $this->calls[] = ['del', $key]; return 1; }
            public function hdel($key, $field) { $this->calls[] = ['hdel', $key, $field]; return 2; }
            public function keys($pattern) { $this->calls[] = ['keys', $pattern]; return false; }
        };

        $redis = new ClientRedis($fake);

        // 返回值原样透传：null/false 不许被"归一化"成 0 或空数组（内核的分支同时兼容两种客户端）
        $this->assertSame(1, $redis->exists('k'));
        $this->assertFalse($redis->hexists('k', 'f'));
        $this->assertNull($redis->get('k'));
        $this->assertSame(true, $redis->setex('k', 60, 'v'));
        $this->assertSame(2, $redis->hdel('k', 'f'));

        // 唯一一处例外：keys() 契约要求数组，phpredis 无匹配时给 false
        $this->assertSame([], $redis->keys('aetherupload*'));

        $this->assertSame(
            ['exists', 'hexists', 'get', 'setex', 'hdel', 'keys'],
            array_map(static function (array $call) { return $call[0]; }, $fake->calls),
            '8 个命令中用到的那几个必须原样落到客户端上'
        );
    }

    // --------------------------------------------------------------- BasePaths

    /** 默认落点与 webman 侧一致：/resource/translations 与 /public/vendor/aetherupload/js */
    public function testBasePathsDefaults(): void
    {
        $paths = new BasePaths('/srv/app/');

        $this->assertSame('/srv/app', $paths->basePath(), '基路径去掉尾部分隔符');
        $this->assertSame('/srv/app/resource/translations', $paths->translationsPath());
        $this->assertSame('/srv/app/public/vendor/aetherupload/js', $paths->assetPath());
    }

    /** 两个落点都可被显式覆盖（宿主把 js 放在别处时用） */
    public function testBasePathsOverrides(): void
    {
        $paths = new BasePaths('/srv/app', '/data/translations/', '/data/assets/');

        $this->assertSame('/data/translations', $paths->translationsPath());
        $this->assertSame('/data/assets', $paths->assetPath());
    }
}
