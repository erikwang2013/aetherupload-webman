<?php

namespace AetherUpload\Tests;

use AetherUpload\Tests\Support\TestState;
use AetherUpload\Tests\Support\UploadFixtures;
use PHPUnit\Framework\TestCase;

/**
 * 输入类型边界回归测试。
 *
 * 客户端可以用 JSON 自然发出整数/浮点（{"resource_size": 1048576}），也能发出数组与 null
 * （?resource_name[]=1、{"group":null}）。加固时给参数加类型前置守卫是对的方向，但很容易过紧：
 * is_string($resource_size) 会把整数/浮点一并拒掉，于是合法的 JSON 客户端全部上传失败。
 * 这类"加固顺手误伤合法输入"的回归只有反向断言挡得住，所以本文件里
 * "非字符串必须被拒" 与 "数字形态必须被接受" 是成对存在的，改任何一边都会红。
 */
class InputTypeBoundaryTest extends TestCase
{
    use UploadFixtures;

    /** 允许的拒绝方式：参数非法 / 操作非法。出现其它值（含 PHP 异常逃逸成的 500）都算不符合预期 */
    private const REJECTED = ['invalid_resource_params', 'invalid_operation'];

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

    private function preprocessWith(array $overrides): array
    {
        $this->createDirs();

        return $this->decoded($this->runPreprocess($this->preprocessRequest($overrides)));
    }

    /**
     * 造一个"只剩参数类型有问题"的 saveChunk 场景：.part 与 header 都在、分块有效，
     * 因此被拒一定是因为类型守卫，而不是因为文件不存在。
     */
    private function saveChunkWith(array $overrides): array
    {
        $this->createDirs();
        file_put_contents($this->partPath($this->fxTempBase), '');
        file_put_contents($this->headerPath($this->fxTempBase), '0');

        return $this->decoded($this->runSaveChunk($this->saveChunkRequest($overrides, $this->chunkObject('x'))));
    }

    /**
     * 跑一次完整的单分块上传，返回 [preprocess 结果, saveChunk 结果]。
     */
    private function completeUpload(array $chunkOverrides = []): array
    {
        $this->createDirs();

        $content = $this->gifContent();
        $hash = md5($content);

        $pre = $this->decoded($this->runPreprocess($this->preprocessRequest([
            'resource_size' => (string)strlen($content),
            'resource_hash' => $hash,
        ])));

        $result = $this->decoded($this->runSaveChunk($this->saveChunkRequest(array_merge([
            'resource_temp_basename' => $pre['resourceTempBaseName'],
            'resource_ext'           => $pre['resourceExt'],
            'group_subdir'           => $pre['groupSubDir'],
            'resource_hash'          => $hash,
        ], $chunkOverrides), $this->chunkObject($content))));

        return [$pre, $result];
    }

    /**
     * 反向断言（本轮加固误伤的那条）：resource_size 的字符串/整数/浮点三种形态都必须被接受。
     * JSON 客户端发出的是整数或浮点，is_string() 前置会把它们一并拒掉——这正是要挡的回归。
     */
    public function testNumericResourceSizeInEveryScalarShapeIsAccepted(): void
    {
        foreach ( ['字符串' => '1048576', '整数' => 1048576, '浮点' => 1048576.0] as $label => $size ) {
            $result = $this->preprocessWith(['resource_size' => $size]);

            $this->assertNotSame(
                'invalid_resource_params',
                $result['error'],
                $label . '形态的 resource_size 是合法输入，被类型守卫误伤了'
            );
            $this->assertSame(0, $result['error'], $label . '形态的 resource_size 应能正常预处理');
        }
    }

    /**
     * 反向断言：chunk_index / chunk_total 走 ctype_digit((string)…)，整数与字符串应当等价。
     */
    public function testIntegerAndStringChunkParametersAreEquivalent(): void
    {
        [, $stringResult] = $this->completeUpload(['chunk_total' => '1', 'chunk_index' => '1']);
        [, $intResult] = $this->completeUpload(['chunk_total' => 1, 'chunk_index' => 1]);

        $this->assertSame(0, $stringResult['error'], '字符串分块参数应能完成上传：' . $stringResult['error']);
        $this->assertSame(0, $intResult['error'], '整数分块参数应能完成上传：' . $intResult['error']);
        $this->assertSame($stringResult['savedPath'], $intResult['savedPath']);
    }

    /**
     * 正向断言：数组与 null 形态的客户端可控参数必须被干净地拒绝，
     * 不能抛 TypeError（逃出 catch \Exception → 500），也不能靠 (string) 把数组转成 "Array" 混过白名单。
     */
    public function testArrayAndNullParametersAreRejectedWithoutTypeError(): void
    {
        $rows = [
            ['preprocess', 'resource_name', ['a.gif']],
            ['preprocess', 'resource_name', null],
            ['preprocess', 'resource_size', ['1048576']],
            ['preprocess', 'resource_size', null],
            ['preprocess', 'group', ['file']],
            ['preprocess', 'group', null],
            ['saveChunk', 'resource_temp_basename', ['tempabc123']],
            ['saveChunk', 'resource_temp_basename', null],
            ['saveChunk', 'resource_ext', ['gif']],
            ['saveChunk', 'resource_ext', null],
            ['saveChunk', 'group_subdir', ['202609']],
            ['saveChunk', 'group_subdir', null],
            ['saveChunk', 'resource_hash', ['abc']],
            ['saveChunk', 'group', ['file']],
        ];

        foreach ( $rows as [$endpoint, $param, $value] ) {
            $label = $endpoint . '：' . $param . ' = ' . (is_array($value) ? '数组' : 'null');

            $result = $endpoint === 'preprocess'
                ? $this->preprocessWith([$param => $value])
                : $this->saveChunkWith([$param => $value]);

            $this->assertContains(
                $result['error'],
                self::REJECTED,
                $label . ' 必须被拒绝（TypeError/500 或"静默接受"都不允许），实际 error=' . var_export($result['error'], true)
            );
            $this->assertSame('', $result['savedPath'], $label . ' 被拒时不得返回 savedPath');
        }
    }

    /**
     * chunk_index / chunk_total 走的是 `ctype_digit((string)…)`，缺字符串前置时
     * `(string)['1']` 会先触发 "Array to string conversion" 警告再返回 false —— 拒绝是对的，
     * 但警告会污染日志。PHPUnit 默认不因 PHP 警告失败，所以这类退化只有显式采集才看得见。
     */
    public function testArrayChunkParametersAreRejectedWithoutPhpWarning(): void
    {
        $warnings = [];

        set_error_handler(static function (int $level, string $message) use (&$warnings) {
            $warnings[] = $message;

            return true;
        });

        try {
            foreach ( ['chunk_index' => ['1'], 'chunk_total' => ['1']] as $param => $value ) {
                $result = $this->saveChunkWith([$param => $value]);

                $this->assertContains(
                    $result['error'],
                    self::REJECTED,
                    '数组型 ' . $param . ' 必须被拒绝，实际 error=' . var_export($result['error'], true)
                );
            }
        } finally {
            restore_error_handler();
        }

        $this->assertSame([], $warnings, '数组型分块参数触发了 PHP 警告：' . implode(' | ', $warnings));
    }

    /**
     * 数组型 resource_hash 不得变成秒传 key 的一部分：旧写法 (string) 数组会得到 "Array"，
     * 拼出 file_Array 这种垃圾 key 并被后续上传命中。开启秒传时尤其要确认没有写进 Redis。
     */
    public function testArrayResourceHashNeverReachesRedis(): void
    {
        TestState::set(TestState::PREFIX . '.instant_completion', true);
        TestState::resetConfigMapper();

        $this->preprocessWith(['resource_hash' => ['abc']]);
        $this->saveChunkWith(['resource_hash' => ['abc']]);

        $this->assertSame([], TestState::$redisStrings, '数组 resource_hash 拼出了垃圾秒传记录：' . implode(', ', array_keys(TestState::$redisStrings)));
        $this->assertSame([], TestState::$redisHash);
    }

    /**
     * 数组型 locale 必须优雅降级（实现里非字符串回落到默认 'en'），
     * 不能透传给 setLocale(string) 抛 TypeError 变成 500。
     */
    public function testArrayLocaleDegradesToDefaultInsteadOfFailing(): void
    {
        $result = $this->preprocessWith(['locale' => ['zh']]);

        $this->assertNotContains($result['error'], self::REJECTED, 'locale 非法不应导致上传被拒');
        $this->assertSame(0, $result['error']);
        $this->assertSame('en', TestState::$locale, '数组 locale 应回落到默认语言');

        $this->saveChunkWith(['locale' => ['zh']]);
        $this->assertSame('en', TestState::$locale);
    }
}
