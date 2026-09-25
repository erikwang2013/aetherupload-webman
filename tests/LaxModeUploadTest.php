<?php

namespace AetherUpload\Tests;

use AetherUpload\SavedPathResolver;
use AetherUpload\Tests\Support\TestState;
use AetherUpload\Tests\Support\UploadFixtures;
use PHPUnit\Framework\TestCase;

/**
 * 宽松模式（lax_mode=true）全流程回归测试。
 * 宽松模式下前端不计算 hash，resource_hash 提交空串——这条链路此前没有任何测试覆盖。
 */
class LaxModeUploadTest extends TestCase
{
    use UploadFixtures;

    private const PREFIX = 'plugin.erikwang2013.aetherupload-webman.app';
    private const GROUP  = 'file';

    protected function setUp(): void
    {
        TestState::reset();
        TestState::normalizeConfig();
        TestState::set(self::PREFIX . '.lax_mode', true);
        TestState::resetConfigMapper();
        mkdir(TestState::$basePath, 0777, true);
        $this->createDirs();
    }

    protected function tearDown(): void
    {
        remove_dir(TestState::$basePath);
    }

    /** ConfigMapper 单例在构造时缓存 config()，改配置后必须重置单例 */
    private function setConfig(string $key, $value): void
    {
        TestState::set(self::PREFIX . '.' . $key, $value);
        TestState::resetConfigMapper();
    }

    /** 走完整的 preprocess + 单分块 saveChunk 流程（客户端真实调用顺序），返回 saveChunk 的解码结果 */
    private function uploadInOneChunk(string $content, string $hash, string $name = 'a.gif'): array
    {
        $pre = $this->decoded($this->runPreprocess($this->preprocessRequest([
            'resource_name' => $name,
            'resource_size' => (string)strlen($content),
            'resource_hash' => $hash,
        ])));

        $this->assertSame(0, $pre['error'], 'preprocess 失败: ' . var_export($pre['error'] ?? null, true));

        return $this->decoded($this->runSaveChunk($this->saveChunkRequest([
            'chunk_total'            => '1',
            'chunk_index'            => '1',
            'resource_temp_basename' => $pre['resourceTempBaseName'],
            'resource_ext'           => $pre['resourceExt'],
            'group_subdir'           => $pre['groupSubDir'],
            'resource_hash'          => $hash,
        ], $this->chunkObject($content))));
    }

    private function realPathOf(string $savedPath): string
    {
        $params = SavedPathResolver::decode($savedPath);

        return $this->uploadDir($params->groupSubDir) . '/' . $params->resourceName;
    }

    /**
     * 展开两套 Redis 桩存储（旧版单 hash + 新版本每条记录一个 key），用于断言"什么都没写入"。
     * 秒传记录的存储形状可能随实现变化，因此断言只看 key/字段名与取值，不依赖具体结构。
     *
     * @return array<int, array{key:string, field:?string, value:string}>
     */
    private function redisRecords(): array
    {
        $records = [];

        foreach ( TestState::$redisHash as $key => $fields ) {
            foreach ( (array)$fields as $field => $value ) {
                $records[] = ['key' => (string)$key, 'field' => (string)$field, 'value' => (string)$value];
            }
        }

        foreach ( TestState::$redisStrings as $key => $value ) {
            $records[] = ['key' => (string)$key, 'field' => null, 'value' => (string)$value];
        }

        return $records;
    }

    /**
     * 守护 bug 1（P0）：宽松模式下客户端提交 resource_hash='' 时，preprocess 与单分块 saveChunk
     * 都必须成功——修复前 saveChunk 里 RedisSavedPath::getKey($group, '') 因空串非法抛 invalid_operation，
     * 整条上传链路直接失败。
     */
    public function testLaxModeWithEmptyResourceHashCompletesUpload(): void
    {
        $gif = $this->gifContent();

        $body = $this->uploadInOneChunk($gif, '');

        $this->assertSame(0, $body['error'], '宽松模式下空 hash 上传必须成功，实际: ' . var_export($body['error'], true));
        $this->assertNotEmpty($body['savedPath']);

        $completePath = $this->realPathOf($body['savedPath']);
        $this->assertStringEndsWith(md5($gif) . '.gif', $body['savedPath'], 'savedPath 仍应由真实内容 hash 生成');
        $this->assertFileExists($completePath);
        $this->assertSame($gif, file_get_contents($completePath));
    }

    /**
     * 守护 bug 1（P0）：宽松模式下分块文件也必须会被清掉，不能留下 .part / header 垃圾。
     */
    public function testLaxModeWithEmptyResourceHashCleansTempFiles(): void
    {
        $gif = $this->gifContent();
        $pre = $this->decoded($this->runPreprocess($this->preprocessRequest([
            'resource_size' => (string)strlen($gif),
            'resource_hash' => '',
        ])));
        $tempBase = $pre['resourceTempBaseName'];

        $this->assertFileExists($this->partPath($tempBase));
        $this->assertFileExists($this->headerPath($tempBase));

        $body = $this->decoded($this->runSaveChunk($this->saveChunkRequest([
            'chunk_total'            => '1',
            'chunk_index'            => '1',
            'resource_temp_basename' => $tempBase,
            'group_subdir'           => $pre['groupSubDir'],
            'resource_hash'          => '',
        ], $this->chunkObject($gif))));

        $this->assertSame(0, $body['error']);
        $this->assertFileDoesNotExist($this->partPath($tempBase));
        $this->assertFileDoesNotExist($this->headerPath($tempBase));
    }

    /**
     * 守护 bug 2（P0 连带风险）：宽松模式与秒传同开、resource_hash='' 时，完成上传不得写入
     * 空 hash 的秒传记录（字段名退化成 '<group>_'），否则内容不同的第二个文件会被"秒传"成第一个文件的 savedPath。
     */
    public function testEmptyHashIsNeverStoredAsInstantCompletionRecord(): void
    {
        $this->setConfig('instant_completion', true);

        $gifA = $this->gifContent();
        $gifB = $this->gifContent('B');

        $bodyA = $this->uploadInOneChunk($gifA, '');
        $this->assertSame(0, $bodyA['error']);
        $savedPathA = $bodyA['savedPath'];
        $this->assertNotEmpty($savedPathA);

        // 空 hash 的 key：旧版 hash 存储里字段名就是 'file_'，新版一记录一 key 时 key 以 'file_' 结尾
        foreach ( $this->redisRecords() as $record ) {
            $this->assertNotSame(self::GROUP . '_', $record['field'], '空 hash 不得作为秒传字段名写入');
            $this->assertStringEndsNotWith(self::GROUP . '_', $record['key'], '空 hash 不得作为秒传 key 写入');
            $this->assertNotSame($savedPathA, $record['value'], '第一个文件的 savedPath 不得进入秒传记录');
        }

        $bodyB = $this->uploadInOneChunk($gifB, '');

        $this->assertSame(0, $bodyB['error']);
        $this->assertNotSame($savedPathA, $bodyB['savedPath'], '内容不同的第二个文件不得被秒传成第一个文件');
        $this->assertStringEndsWith(md5($gifB) . '.gif', $bodyB['savedPath']);
        $this->assertSame($gifB, file_get_contents($this->realPathOf($bodyB['savedPath'])));
        // 第一个文件必须原样保留
        $this->assertSame($gifA, file_get_contents($this->realPathOf($savedPathA)));
    }

    /**
     * 反向断言：宽松模式不影响秒传本身——有 hash 时秒传记录照常写入并命中。
     */
    public function testInstantCompletionStillWorksWithHashInLaxMode(): void
    {
        $this->setConfig('instant_completion', true);
        $gif  = $this->gifContent();
        $hash = md5($gif);

        $bodyA = $this->uploadInOneChunk($gif, $hash);
        $this->assertSame(0, $bodyA['error']);
        $this->assertNotEmpty($bodyA['savedPath']);

        $records = array_column($this->redisRecords(), 'value');
        $this->assertContains($bodyA['savedPath'], $records, '有 hash 时秒传记录必须写入');

        // 同样内容再传一次：直接秒传，不再产生分块文件
        $bodyB = $this->uploadInOneChunk($gif, $hash);
        $this->assertSame(0, $bodyB['error']);
        $this->assertSame($bodyA['savedPath'], $bodyB['savedPath']);
    }
}
