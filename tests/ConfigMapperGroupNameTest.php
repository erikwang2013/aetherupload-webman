<?php

namespace AetherUpload\Tests;

use AetherUpload\ConfigMapper;
use AetherUpload\SavedPathResolver;
use AetherUpload\Tests\Support\TestState;
use PHPUnit\Framework\TestCase;

/**
 * 分组名校验回归测试。
 * savedPath 用 '_' 拼接（group_subdir_name），SavedPathResolver::decode 也按 '_' 切成 3 段，
 * 因此分组名一旦含 '_'，编码/解码必然错位：'my_file_202609_a.gif' 解出来 group='my'，
 * applyGroupConfig('my') 抛异常 -> 展示/下载 404。这种分组必须在使用时就被拒绝。
 */
class ConfigMapperGroupNameTest extends TestCase
{
    private const PREFIX = 'plugin.erikwang2013.aetherupload-webman.app';

    protected function setUp(): void
    {
        TestState::reset();
        TestState::normalizeConfig();
        TestState::resetConfigMapper();
    }

    private function addGroup(string $group): void
    {
        TestState::set(self::PREFIX . '.groups.' . $group, [
            'group_dir'                    => $group,
            'resource_maxsize'             => 104857600,
            'resource_extensions'          => ['gif'],
            'event_before_upload_complete' => false,
            'event_upload_complete'        => false,
        ]);
        TestState::resetConfigMapper();
    }

    /**
     * 捕获的是 \Throwable 而非 \Exception：分组名来自请求参数，若校验写成了
     * str_contains($group, '_') 而没有 is_string() 守卫，PHP 8 会抛 TypeError，
     * 用 catch (\Exception) 会漏过去，测试反而在别处以"意外错误"的形式炸掉。
     *
     * @return \Throwable|null
     */
    private function captured(callable $fn): ?\Throwable
    {
        try {
            $fn();
        } catch ( \Throwable $e ) {
            return $e;
        }

        return null;
    }

    /**
     * 守护 bug 4（P1）：含下划线的分组名必须在使用时抛异常。
     * 修复前 applyGroupConfig('my_file') 静默通过，savedPath 解码错位后展示/下载 404。
     */
    public function testApplyGroupConfigRejectsGroupNameWithUnderscore(): void
    {
        $this->addGroup('my_file');

        $thrown = $this->captured(fn() => ConfigMapper::applyGroupConfig('my_file'));

        $this->assertNotNull($thrown, '含下划线的分组名必须在 applyGroupConfig() 阶段被拒绝，不能静默通过');
        $this->assertSame('invalid_operation', $thrown->getMessage());
    }

    /**
     * 守护 bug 4（P1）：分组名可以被请求方构造成数组（?group[]=file），
     * 必须按"非法分组"处理成 \Exception，而不是让 PHP 8 抛 TypeError 变成 500。
     */
    public function testApplyGroupConfigRejectsNonStringGroupWithoutTypeError(): void
    {
        $thrown = $this->captured(fn() => ConfigMapper::applyGroupConfig(['file']));

        $this->assertNotNull($thrown, '数组分组名必须被拒绝');
        $this->assertNotInstanceOf(\TypeError::class, $thrown, '不能因缺少 is_string() 守卫而抛 TypeError');
        $this->assertInstanceOf(\Exception::class, $thrown);
        $this->assertSame('invalid_operation', $thrown->getMessage());
    }

    /**
     * 反向断言：不含下划线的分组名（含数字/中划线）必须照常通过，校验不能做过头。
     */
    public function testApplyGroupConfigStillAcceptsLegalGroupNames(): void
    {
        $this->addGroup('my-file2');

        ConfigMapper::applyGroupConfig('file');
        $this->assertSame('file', ConfigMapper::get('group'));

        ConfigMapper::applyGroupConfig('my-file2');
        $this->assertSame('my-file2', ConfigMapper::get('group'));
        $this->assertSame('my-file2', ConfigMapper::get('group_dir'));
    }

    /**
     * 拒绝的原因（本用例是给上面那条兜底的证据）：下划线分组名在 encode/decode 往返中会丢信息。
     */
    public function testSavedPathRoundTripIsLossyForGroupNameWithUnderscore(): void
    {
        $params = SavedPathResolver::decode(SavedPathResolver::encode('my_file', '202609', 'abc.gif'));

        $this->assertSame('my', $params->group, '分组名 "my_file" 被切成了 "my"');
        $this->assertSame('file', $params->groupSubDir, '子目录错位成 "file"');
        $this->assertSame('202609_abc.gif', $params->resourceName, '文件名错位');
    }
}
