<?php

namespace AetherUpload\Tests\Integration\Symfony;

use AetherUpload\ResourceController;
use AetherUpload\Runtime;
use AetherUpload\UploadController;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Throwable;

/**
 * Symfony 接线的证据：真容器跑起来之后，路由、命令、配置参数、Redis 端口都得是插件要的样子。
 *
 * 与 SymfonyFlowTest 分开：那边证明「上传能跑通」，这边证明「接得对」（流程全绿也可能是接错了线）。
 */
class SymfonyWiringTest extends WebTestCase
{
    public function testBundleBootBindsRuntimeToSymfonyAdapter(): void
    {
        static::bootKernel();

        $this->assertTrue(Runtime::isBound(), 'AetherUploadBundle::boot() 必须把内核绑到适配器上');
        $this->assertSame('symfony', Runtime::adapter()->name());
    }

    public function testKernelReadsConfigThroughContainerParameters(): void
    {
        static::bootKernel();

        // 逻辑键 → 容器参数（aetherupload.*）这一层真的通了，而不是恰好读到默认值
        $this->assertSame(1000000, Runtime::config()->get('chunk_size'));
        $this->assertSame('file', Runtime::config()->get('groups.file.group_dir'));
        $this->assertSame('month', Runtime::config()->get('resource_subdir_rule'));
        $this->assertSame('/aetherupload/preprocess', Runtime::config()->get('route_preprocess'));
        $this->assertSame('en', Runtime::translator()->getLocale());
    }

    public function testPathsComeFromProjectDir(): void
    {
        static::bootKernel();

        $this->assertSame(App::root(), Runtime::paths()->basePath());
        $this->assertSame(App::root() . '/translations', Runtime::paths()->translationsPath());
        $this->assertSame(App::root() . '/public/vendor/aetherupload/js', Runtime::paths()->assetPath());
    }

    public function testFourRoutesAreRegisteredWithConfiguredPaths(): void
    {
        static::bootKernel();
        $routes = static::getContainer()->get('router')->getRouteCollection();

        $expected = [
            'aetherupload_preprocess' => ['/aetherupload/preprocess', 'POST', UploadController::class . '::preprocess'],
            'aetherupload_uploading'  => ['/aetherupload/uploading', 'POST', UploadController::class . '::saveChunk'],
            'aetherupload_display'    => ['/aetherupload/display/{uri}', 'GET', ResourceController::class . '::display'],
            'aetherupload_download'   => ['/aetherupload/download/{uri}/{newName}', 'GET', ResourceController::class . '::download'],
        ];

        foreach ( $expected as $name => $definition ) {
            $route = $routes->get($name);
            $this->assertNotNull($route, '缺少路由 ' . $name);

            [$path, $method, $controller] = $definition;
            $this->assertSame($path, $route->getPath(), $name . ' 的路径');
            $this->assertSame([$method], $route->getMethods(), $name . ' 的方法');
            $this->assertSame($controller, $route->getDefault('_controller'), $name . ' 的控制器');
        }
    }

    public function testFourConsoleCommandsAreRegistered(): void
    {
        static::bootKernel();

        $names = array_keys((new Application(static::$kernel))->all());

        foreach ( ['aetherupload:build', 'aetherupload:clean', 'aetherupload:groups', 'aetherupload:publish'] as $name ) {
            $this->assertContains($name, $names, '缺命令 ' . $name);
        }
    }

    /**
     * 前端脚本必须落到可被 Web 访问的 public/vendor/aetherupload/js（示例页与使用者接入都按这个 URL 取脚本），
     * 语言文件同理落到 translations/aetherupload/<locale>/messages.php。
     *
     * 幂等性在这里断言：publish 走 Runtime::filesystem()->copyDir()，**刻意不覆盖已存在的文件** ——
     * 使用者改过的 js / 配置再发布一次不能被抹掉。用例先写一个哨兵进去，再跑一次真命令验证它还在，
     * 最后用 --force 把真文件放回来，免得污染后续用例。
     */
    public function testPublishPlacesAssetsAndTranslationsWithoutOverwriting(): void
    {
        static::bootKernel();

        $asset = Runtime::paths()->assetPath() . '/aetherupload-core.js';
        $translation = Runtime::paths()->translationsPath() . '/aetherupload/en/messages.php';

        $this->assertFileExists($asset, 'publish 必须把包内 docs/js 放到 public/vendor/aetherupload/js');
        $this->assertFileExists($translation, 'publish 必须把语言文件放到框架约定的语言目录');

        $original = (string)file_get_contents($asset);

        $output = App::console(App::root(), 'aetherupload:publish');
        $this->assertStringContainsString('assets → ' . Runtime::paths()->assetPath(), $output);
        $this->assertStringContainsString('translations → ' . Runtime::paths()->translationsPath() . '/aetherupload', $output);

        // 使用者改过的文件 + 额外放进来的文件，重复发布都不能被动
        $sentinel = "// sentinel " . bin2hex(random_bytes(4)) . "\n";
        $extra = Runtime::paths()->assetPath() . '/host-added.js';
        file_put_contents($asset, $sentinel);
        file_put_contents($extra, $sentinel);

        App::console(App::root(), 'aetherupload:publish');

        $this->assertSame($sentinel, (string)file_get_contents($asset), '已存在的文件不能被覆盖');
        $this->assertSame($sentinel, (string)file_get_contents($extra), '已存在的同名文件不能被覆盖');
        $this->assertFileExists(Runtime::paths()->assetPath() . '/aetherupload-all.js', '未存在的文件仍要补齐');

        // 收尾：--force 重新覆盖，把真文件放回来
        App::console(App::root(), 'aetherupload:publish --force');
        $this->assertSame($original, (string)file_get_contents($asset));
        @unlink($extra);
    }

    /**
     * Redis 端口要么真的能读写（容器里有公开客户端），要么退化成 NullRedis 且**一调用就抛**。
     * 两种环境都断言一条，不存在「什么都不验证」的情况。
     */
    public function testRedisPortMatchesWhatTheContainerProvides(): void
    {
        static::bootKernel();
        $redis = Runtime::redis();

        if ( static::getContainer()->has('Redis') ) {
            $key = 'aetherupload:e2e:wiring:' . bin2hex(random_bytes(4));

            $redis->setex($key, 60, 'v');
            $this->assertSame('v', $redis->get($key), 'phpredis 的值必须原样穿过端口');
            $this->assertSame([$key], $redis->keys($key), 'keys() 契约要求返回数组');
            $redis->del($key);
            $this->assertSame([], $redis->keys($key));

            return;
        }

        // 没接客户端时必须是「一调用就抛」，而不是静默失败
        $this->expectException(Throwable::class);
        $redis->get('aetherupload:e2e:wiring');
    }

    public function testTranslationMissReturnsTheKeyItself(): void
    {
        static::bootKernel();
        $translator = Runtime::translator();

        $this->assertSame('aetherupload.upload_error', $translator->trans('aetherupload.upload_error'), '未命中必须返回 key，而不是宿主翻译器的 id');

        Runtime::setLocale('zz');
        $this->assertSame('upload_error', $translator->trans('upload_error'), '语种未命中时同样返回 key');
    }
}
