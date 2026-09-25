<?php

namespace AetherUpload\Tests;

use AetherUpload\Tests\Support\TestState;
use AetherUpload\Tests\Support\UploadFixtures;
use PHPUnit\Framework\TestCase;

/**
 * group_subdir 必须与 group 同款校验：它参与 savedPath 的 '_' 分隔拼接（SavedPathResolver::encode），
 * 含下划线会让 decode 错位——'file_a_b_<md5>.gif' 被切成 group='file'、subdir='a'、name='b_<md5>.gif'，
 * 资源写进去就再也定位不到，永久 404。服务端自己生成的取值（generateSubDirName 的四个分支）都不含下划线，
 * 因此含下划线的提交一定是客户端构造的，直接拒掉。
 * 注意 isSafePathComponent 是允许下划线的（它是合法的路径字符），所以这条校验必须单独存在。
 */
class GroupSubDirValidationTest extends TestCase
{
    use UploadFixtures;

    protected function setUp(): void
    {
        TestState::reset();
        TestState::normalizeConfig();
        TestState::resetConfigMapper();
        mkdir(TestState::$basePath, 0777, true);
    }

    protected function tearDown(): void
    {
        remove_dir(TestState::$basePath);
    }

    private function setSubDirRule(string $rule): void
    {
        TestState::set(TestState::PREFIX . '.resource_subdir_rule', $rule);
        TestState::resetConfigMapper();
    }

    /** 分组目录与 _header 目录必须先存在（preprocess 只在已存在的分组目录下建子目录） */
    private function prepareDirs(): void
    {
        mkdir(dirname($this->uploadDir()), 0755, true);
        mkdir($this->headerDir(), 0755, true);
    }

    /**
     * 守护"group_subdir 含下划线"（评审发现的永久 404）：必须拒绝，且不得留下任何目录/临时文件。
     * 该校验在文件检查之前，因此不需要预先造 .part/header 也能命中。
     */
    public function testSaveChunkRejectsGroupSubDirWithUnderscore(): void
    {
        $this->setSubDirRule('month');
        $this->prepareDirs();

        $content = $this->gifContent();
        $response = $this->runSaveChunk($this->saveChunkRequest([
            'group_subdir' => 'a_b',
            'resource_hash' => md5($content),
        ], $this->chunkObject($content)));

        $result = $this->decoded($response);

        $this->assertSame('invalid_resource_params', $result['error']);
        $this->assertSame('', $result['savedPath']);
        $this->assertDirectoryDoesNotExist(
            TestState::dir($this->fxRootDir, $this->fxGroupDir, 'a_b'),
            '含下划线的 group_subdir 不得被落盘：路径会永久 404'
        );
    }

    /**
     * 守护"group_subdir 含下划线"的真正危害场景：客户端已经在 file/a_b/ 下有进度时，
     * 旧实现会返回 error=0 且 savedPath='file_a_b_<md5>.gif' —— 这个 savedPath 解码出来是
     * group=file、subdir=a、name=b_<md5>.gif，指向一个不存在的路径，资源上传"成功"却永久 404。
     * 修复后必须直接拒绝，且不得把文件写进 file/a_b/。
     */
    public function testSaveChunkRejectsUnderscoreSubDirEvenWhenUploadAlreadyInProgress(): void
    {
        $this->setSubDirRule('month');
        $this->prepareDirs();

        // 客户端在非法子目录里已有 .part 与 header，旧实现正是靠这个绕过 exists() 检查
        $illegalDir = TestState::dir($this->fxRootDir, $this->fxGroupDir, 'a_b');
        mkdir($illegalDir, 0755, true);
        file_put_contents($illegalDir . '/' . $this->fxTempBase . '.gif.part', '');
        file_put_contents($this->headerPath($this->fxTempBase), '0');

        $content = $this->gifContent();
        $response = $this->runSaveChunk($this->saveChunkRequest([
            'group_subdir' => 'a_b',
            'resource_hash' => md5($content),
        ], $this->chunkObject($content)));

        $result = $this->decoded($response);

        $this->assertSame('invalid_resource_params', $result['error']);
        $this->assertSame('', $result['savedPath'], '不得返回一个解码后指向不存在路径的 savedPath');
        $this->assertFileDoesNotExist(
            $illegalDir . '/' . md5($content) . '.gif',
            '资源被写进了永久 404 的目录'
        );
    }

    /**
     * 反向断言：month 规则生成的子目录（Ym）必须照常走完全流程，校验别把合法值一起挡了。
     */
    public function testMonthStyleSubDirStillCompletesUpload(): void
    {
        $this->setSubDirRule('month');

        $this->assertUploadCompletesIn(date('Ym'));
    }

    /**
     * 反向断言：const 规则生成的子目录（'subdir'）同样必须照常走完。
     */
    public function testConstStyleSubDirStillCompletesUpload(): void
    {
        $this->setSubDirRule('const');

        $this->assertUploadCompletesIn('subdir');
    }

    private function assertUploadCompletesIn(string $expectedSubDir): void
    {
        // 分块目录按当前规则动态取，别写死月份
        $this->fxSubDir = $expectedSubDir;
        $this->createDirs();

        $content = $this->gifContent();
        $hash = md5($content);

        $pre = $this->decoded($this->runPreprocess($this->preprocessRequest([
            'resource_size' => (string)strlen($content),
            'resource_hash' => $hash,
        ])));

        $this->assertSame(0, $pre['error'], 'preprocess 失败：' . $pre['error']);
        $this->assertSame($expectedSubDir, $pre['groupSubDir']);

        $savedPath = 'file_' . $expectedSubDir . '_' . $hash . '.gif';

        $result = $this->decoded($this->runSaveChunk($this->saveChunkRequest([
            'resource_temp_basename' => $pre['resourceTempBaseName'],
            'resource_ext'           => $pre['resourceExt'],
            'group_subdir'           => $pre['groupSubDir'],
            'resource_hash'          => $hash,
        ], $this->chunkObject($content))));

        $this->assertSame(0, $result['error'], 'saveChunk 失败：' . $result['error']);
        $this->assertSame($savedPath, $result['savedPath']);
        $this->assertFileExists($this->uploadDir($expectedSubDir) . '/' . $hash . '.gif');
    }
}
