<?php

namespace AetherUpload\Tests\Integration\Symfony;

use AetherUpload\Adapter\Symfony\AetherUploadExtension;
use AetherUpload\Adapter\Symfony\Configuration;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Processor;

/**
 * Symfony 的配置树（app 里写 `aetherupload:`）必须与内核的规格 config/aetherupload.php 逐键一致。
 *
 * 为什么值得单独测：Symfony 遇到**未声明**的键会让容器启动直接失败，而漏声明一个键更隐蔽 ——
 * 使用者按文档写了那个键，容器却直接报「Unrecognized option」，本插件就成了装不上的插件。
 * 这里用配置树的默认值（对空配置跑一遍 Processor）与规格文件比对：键集合 + 值，两边都不许漂。
 *
 * 与根目录的 tests/ConfigParityTest.php（webman 版 ↔ 规格文件）是同一个思路，管的是另一半：
 * 规格文件 ↔ Symfony 配置树。
 */
class SymfonyConfigParityTest extends TestCase
{
    /** 规格文件（六个框架共用的逻辑键） */
    private function canonicalConfig(): array
    {
        return require \dirname(__DIR__, 3) . '/config/aetherupload.php';
    }

    /** 对空配置跑一遍配置树 ⇒ 拿到树的全部默认值 */
    private function treeDefaults(): array
    {
        $tree = (new Configuration())->getConfigTreeBuilder()->buildTree();

        return (new Processor())->process($tree, []);
    }

    /** @return array<string,mixed> 点号路径 => 叶子值 */
    private function flatten(array $array, string $prefix = ''): array
    {
        $flat = [];

        foreach ( $array as $key => $value ) {
            $path = $prefix === '' ? (string)$key : $prefix . '.' . $key;

            if ( is_array($value) && $value !== [] && array_keys($value) !== range(0, count($value) - 1) ) {
                $flat += $this->flatten($value, $path);
                continue;
            }

            $flat[$path] = $value;
        }

        return $flat;
    }

    public function testConfigRootIsNamedAetherUpload(): void
    {
        $tree = (new Configuration())->getConfigTreeBuilder()->buildTree();

        // 配置树的根名就是 app 里 YAML 的段落名，也是容器参数的前缀，不能按类名推导
        $this->assertSame('aetherupload', $tree->getName());
        $this->assertSame('aetherupload', (new AetherUploadExtension())->getAlias());
    }

    public function testTreeDeclaresExactlyTheCanonicalKeys(): void
    {
        $tree = array_keys($this->flatten($this->treeDefaults()));
        $canonical = array_keys($this->flatten($this->canonicalConfig()));

        sort($tree);
        sort($canonical);

        $this->assertSame(
            $canonical,
            $tree,
            'Symfony 配置树与 config/aetherupload.php 的逻辑键集合不一致：' . PHP_EOL
            . '  仅配置树有: ' . implode(', ', array_diff($tree, $canonical)) . PHP_EOL
            . '  仅规格文件有: ' . implode(', ', array_diff($canonical, $tree)) . PHP_EOL
            . '（规格里有的键必须在 Configuration 里声明，否则使用者一写就炸）'
        );
    }

    public function testTreeDefaultsMatchTheCanonicalDefaults(): void
    {
        $tree = $this->flatten($this->treeDefaults());
        $canonical = $this->flatten($this->canonicalConfig());

        foreach ( $canonical as $key => $value ) {
            if ( ! array_key_exists($key, $tree) ) {
                continue; // 键集合差异由上一条用例报告
            }

            $this->assertSame($value, $tree[$key], '默认值不一致：' . $key);
        }
    }

    public function testGroupsPrototypeAcceptsAnyGroupName(): void
    {
        $tree = (new Configuration())->getConfigTreeBuilder()->buildTree();
        $config = (new Processor())->process($tree, [
            ['groups' => ['avatar' => ['group_dir' => 'avatar', 'resource_maxsize' => 1024]]],
        ]);

        // 分组名是使用者自定的；写了 groups 就整段以写的为准（Symfony 的常规语义，默认的 file 分组不会残留）
        $this->assertSame(['avatar'], array_keys($config['groups']));
        $this->assertSame(1024, $config['groups']['avatar']['resource_maxsize']);
        // 没写的键沿用原型默认值
        $this->assertFalse($config['groups']['avatar']['event_upload_complete']);
        $this->assertSame(['jpg', 'jpeg', 'png'], \array_slice($config['groups']['avatar']['resource_extensions'], 0, 3));
    }
}
