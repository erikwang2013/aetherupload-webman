<?php

namespace AetherUpload\Tests;

use PHPUnit\Framework\TestCase;

/**
 * 两份默认配置必须严格一致。
 *
 * 内核只认「逻辑键」（root_dir、groups.file.group_dir …），webman 版把它们藏在
 * plugin.erikwang2013.aetherupload-webman.app. 前缀下，其余框架用 config/aetherupload.php。
 * 同一套默认值写两遍必然会漂移：改了 webman 版忘了改另一份，六个框架的默认行为就悄悄不同了。
 *
 * 因此这里逐键比对（键集合 + 值），任何一边改动而另一边没跟上，这个测试立刻红。
 */
class ConfigParityTest extends TestCase
{
    private function webmanConfig(): array
    {
        return require __DIR__ . '/../config/app.php';
    }

    private function flatConfig(): array
    {
        return require __DIR__ . '/../config/aetherupload.php';
    }

    /** @return array<string,mixed> 点号路径 => 叶子值 */
    private function flatten(array $array, string $prefix = ''): array
    {
        $flat = [];

        foreach ( $array as $key => $value ) {
            $path = $prefix === '' ? (string)$key : $prefix . '.' . $key;

            if ( is_array($value) && $value !== [] && array_keys($value) !== range(0, count($value) - 1) ) {
                // 关联数组（含 groups）继续下钻
                $flat += $this->flatten($value, $path);
                continue;
            }

            $flat[$path] = $value;
        }

        return $flat;
    }

    public function testBothConfigFilesExposeTheSameLogicalKeys(): void
    {
        $webman = array_keys($this->flatten($this->webmanConfig()));
        $flat   = array_keys($this->flatten($this->flatConfig()));

        sort($webman);
        sort($flat);

        $this->assertSame(
            $webman,
            $flat,
            'config/app.php（webman 版）与 config/aetherupload.php 的逻辑键集合不一致：' . PHP_EOL
            . '  仅 webman 有: ' . implode(', ', array_diff($webman, $flat)) . PHP_EOL
            . '  仅扁平版有: '   . implode(', ', array_diff($flat, $webman))
        );
    }

    public function testDefaultValuesAreIdenticalAcrossBothFiles(): void
    {
        $webman = $this->flatten($this->webmanConfig());
        $flat   = $this->flatten($this->flatConfig());

        foreach ( $webman as $key => $value ) {
            if ( ! array_key_exists($key, $flat) ) {
                continue; // 键集合差异由上一条用例报告
            }

            $this->assertSame(
                $value,
                $flat[$key],
                sprintf('默认值不一致：%s —— webman 版与扁平版必须给出相同的默认行为', $key)
            );
        }
    }

    /** 守护：内核真正读取的逻辑键必须在默认配置里存在（防拼写错误） */
    public function testKernelRequiredKeysArePresentInBothFiles(): void
    {
        $required = [
            'root_dir', 'chunk_size', 'resource_subdir_rule', 'forbidden_extensions',
            'extra_mime_types', 'instant_completion', 'resource_redis_expire',
            'middleware_preprocess', 'middleware_uploading', 'middleware_display', 'middleware_download',
            'route_preprocess', 'route_uploading', 'route_display', 'route_download',
            'lax_mode', 'x_accel_redirect', 'groups',
        ];

        foreach ( ['webman' => $this->webmanConfig(), 'flat' => $this->flatConfig()] as $label => $config ) {
            foreach ( $required as $key ) {
                $this->assertArrayHasKey(
                    $key,
                    $config,
                    sprintf('%s 配置缺少内核读取的键：%s', $label, $key)
                );
            }

            foreach ( $config['groups'] as $group => $settings ) {
                foreach ( ['group_dir', 'resource_maxsize', 'resource_extensions', 'event_before_upload_complete', 'event_upload_complete'] as $key ) {
                    $this->assertArrayHasKey(
                        $key,
                        $settings,
                        sprintf('%s 配置的分组 %s 缺少键：%s', $label, $group, $key)
                    );
                }
            }
        }
    }
}
