<?php

namespace AetherUpload\Tests\Integration\Webman;

use AetherUpload\Tests\Integration\FlowAssertions;
use PHPUnit\Framework\TestCase;

/**
 * webman 真实端到端（local 模式）：真骨架（composer create-project workerman/webman）+ 真 HTTP（curl）+ 真文件系统。
 *
 * 这一层才是「webman 可用」的证据；tests/ 下的 217 个用例用的是替身，只覆盖内核逻辑。
 * 应用准备与启停见 bootstrap.php / App.php，请求发送见 WebmanHttp，断言清单见 FlowAssertions。
 * S3 驱动那一套在 S3FlowTest.php（同目录，入口 phpunit-s3.xml）。
 */
class WebmanFlowTest extends TestCase
{
    use FlowAssertions;
    use WebmanHttp;

    protected function setUp(): void
    {
        parent::setUp();

        // 每条用例从「无秒传记录」开始：残留记录会让第 7 条探针假红、第 1 条看不到 .part
        App::flushInstantCompletionKeys();
    }

    protected function appBasePath(): string
    {
        return App::root();
    }

    protected function probeLogPath(): ?string
    {
        return App::probeLog();
    }

    protected function supportsInstantCompletion(): bool
    {
        return App::redisAvailable();
    }

    protected function instantCompletionSkipReason(): string
    {
        return '本机 ' . App::redisHost() . ':' . App::redisPort() . ' 上没有可用的 redis'
            . '（装了 redis 但返回值不对也会走到这里）；第 8 条秒传未验证，其余 7 条不受影响';
    }

    public function testExamplePageServesPetExpressions()
    {
        $page = $this->send('GET', '/aetherupload');

        $this->assertSame(200, $page['status']);
        $this->assertStringContainsString('id="aetherupload-pet"', $page['body']);
        $this->assertStringContainsString('/vendor/aetherupload/js/pet-expressions/smile.svg', $page['body']);
        $this->assertStringContainsString('aetherupload:state', $page['body']);

        $asset = $this->send('GET', '/vendor/aetherupload/js/pet-expressions/knocking.svg');

        $this->assertSame(200, $asset['status']);
        $this->assertStringContainsString('<svg', $asset['body']);
    }
}
