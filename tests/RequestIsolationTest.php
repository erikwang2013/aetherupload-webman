<?php

namespace AetherUpload\Tests;

use AetherUpload\ConfigMapper;
use AetherUpload\Tests\Support\TestState;
use PHPUnit\Framework\TestCase;
use Webman\Http\Request;

/**
 * 执行上下文隔离。
 *
 * ConfigMapper 是可变单例（applyGroupConfig 改的是实例属性），在 webman 常驻进程下
 * 会在并发请求之间互相覆盖 —— 代码里两处「快照 group 配置」的防御注释正为此存在。
 * Hyperf 的协程交错会让这个问题更尖锐。
 *
 * 这里用「换请求对象」模拟上下文切换：webman 每个请求都是新的 Request 实例，
 * Runtime 以请求对象身份判定上下文，因此换对象等同于换请求。
 */
class RequestIsolationTest extends TestCase
{
    protected function setUp(): void
    {
        TestState::reset();
        TestState::normalizeConfig();
        TestState::resetConfigMapper();
        $this->addGroup('video');
    }

    private function addGroup(string $name): void
    {
        TestState::set(TestState::PREFIX . '.groups.' . $name, [
            'group_dir'                    => $name,
            'resource_maxsize'             => 104857600,
            'resource_extensions'          => ['mp4'],
            'event_before_upload_complete' => false,
            'event_upload_complete'        => false,
        ]);
        TestState::resetConfigMapper();
    }

    /** 守护：上一段请求的分组配置不得泄漏到下一段 */
    public function testGroupConfigDoesNotLeakIntoTheNextRequestContext(): void
    {
        // 请求 A：进入 file 分组
        TestState::$request = new Request(['group' => 'file']);
        ConfigMapper::applyGroupConfig('file');
        $this->assertSame('file', ConfigMapper::get('group'));

        // 请求 B：webman 每请求新建 Request 对象 → 执行上下文切换
        TestState::$request = new Request(['group' => 'video']);

        $this->assertNotSame(
            'file',
            ConfigMapper::get('group'),
            '请求 A 的分组配置泄漏到了请求 B —— 并发请求会读到彼此的分组设置'
        );
        $this->assertNotSame(
            'file',
            ConfigMapper::get('group_dir'),
            '请求 A 的分组目录泄漏到了请求 B'
        );
    }

    /** 守护：同一次请求内，applyGroupConfig 的结果必须稳定可见 */
    public function testGroupConfigSurvivesWithinOneRequestContext(): void
    {
        TestState::$request = new Request(['group' => 'file']);
        ConfigMapper::applyGroupConfig('file');

        $this->assertSame('file', ConfigMapper::get('group'));
        $this->assertSame('file', ConfigMapper::get('group_dir'));
        $this->assertSame(104857600, ConfigMapper::get('resource_maxsize'));
    }

    /** 守护：测试脚手架依赖的反射重置语义（resetConfigMapper）必须继续有效 */
    public function testResetConfigMapperStillRebuildsFromConfig(): void
    {
        TestState::$request = new Request([]);
        ConfigMapper::get('chunk_size');

        TestState::set(TestState::PREFIX . '.chunk_size', 4242);
        TestState::resetConfigMapper();

        $this->assertSame(4242, ConfigMapper::get('chunk_size'));
    }
}
