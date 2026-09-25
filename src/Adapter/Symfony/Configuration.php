<?php

namespace AetherUpload\Adapter\Symfony;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

/**
 * aetherupload 的配置树（app 里写作顶层段落 `aetherupload:`）。
 *
 * Symfony 遇到**未声明**的键会让容器启动直接失败（不是静默忽略），所以
 * config/aetherupload.php（六框架共用的逻辑键）里有的键这里一个都不能少。
 * 两边的键集合与默认值由 tests/Integration/symfony/SymfonyConfigParityTest.php 逐键比对，
 * 改一边而没改另一边会立刻红。
 */
class Configuration implements ConfigurationInterface
{
    /** 默认分组：与 config/aetherupload.php 的 groups.file 逐键一致 */
    const DEFAULT_GROUP = [
        'group_dir' => 'file',
        'resource_maxsize' => 104857600,
        'resource_extensions' => [
            'jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg', 'pdf',
            'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt',
            'zip', 'rar', '7z', 'mp4', 'mp3', 'wav',
        ],
        'event_before_upload_complete' => false,
        'event_upload_complete' => false,
    ];

    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('aetherupload');
        $rootNode = $treeBuilder->getRootNode();

        $rootNode
            ->children()
                ->booleanNode('enable')
                    ->defaultTrue()
                ->end()
                ->booleanNode('instant_completion')
                    ->info('秒传：需要 Redis 与浏览器支持 FileReader/File.slice()，缺一不可')
                    ->defaultFalse()
                ->end()
                ->integerNode('resource_redis_expire')
                    ->info('秒传记录在 Redis 中的存活秒数')
                    ->defaultValue(604800)
                ->end()
                ->scalarNode('root_dir')
                    ->info('上传根目录名（相对项目根）')
                    ->defaultValue('storage/app/aetherupload')
                ->end()
                ->integerNode('chunk_size')
                    ->defaultValue(1000000)
                ->end()
                ->scalarNode('resource_subdir_rule')
                    ->info('子目录生成规则：year / month / date / const')
                    ->defaultValue('month')
                ->end()
                ->arrayNode('forbidden_extensions')
                    ->info('后缀名黑名单：命中的一律拒绝')
                    ->scalarPrototype()->end()
                    ->defaultValue([
                        'php', 'part', 'html', 'shtml', 'htm', 'shtm', 'xhtml', 'xml', 'js', 'jsp',
                        'asp', 'java', 'py', 'sh', 'bat', 'exe', 'dll', 'cgi', 'htaccess', 'reg',
                        'aspx', 'vbs',
                    ])
                ->end()
                ->arrayNode('extra_mime_types')
                    ->info('额外 MIME 映射（格式 jpg: image/jpeg）')
                    ->variablePrototype()->end()
                    ->defaultValue([])
                ->end()
                ->arrayNode('middleware_preprocess')
                    ->scalarPrototype()->end()
                    ->defaultValue([])
                ->end()
                ->arrayNode('middleware_uploading')
                    ->scalarPrototype()->end()
                    ->defaultValue([])
                ->end()
                ->arrayNode('middleware_display')
                    ->scalarPrototype()->end()
                    ->defaultValue([])
                ->end()
                ->arrayNode('middleware_download')
                    ->scalarPrototype()->end()
                    ->defaultValue([])
                ->end()
                ->scalarNode('route_preprocess')->defaultValue('/aetherupload/preprocess')->end()
                ->scalarNode('route_uploading')->defaultValue('/aetherupload/uploading')->end()
                ->scalarNode('route_display')->defaultValue('/aetherupload/display')->end()
                ->scalarNode('route_download')->defaultValue('/aetherupload/download')->end()
                ->booleanNode('lax_mode')
                    ->info('宽松模式：跳过上传前的 hash 校验（同时失去秒传与完整性校验）')
                    ->defaultFalse()
                ->end()
                ->booleanNode('x_accel_redirect')
                    ->info('交由 nginx 直发文件（X-Accel-Redirect）')
                    ->defaultFalse()
                ->end()
                ->arrayNode('groups')
                    ->info('资源分组')
                    ->normalizeKeys(false)
                    ->prototype('array')
                        ->children()
                            ->scalarNode('group_dir')->defaultValue('file')->end()
                            ->integerNode('resource_maxsize')->defaultValue(104857600)->end()
                            ->arrayNode('resource_extensions')
                                ->scalarPrototype()->end()
                                ->defaultValue(self::DEFAULT_GROUP['resource_extensions'])
                            ->end()
                            ->booleanNode('event_before_upload_complete')->defaultFalse()->end()
                            ->booleanNode('event_upload_complete')->defaultFalse()->end()
                        ->end()
                    ->end()
                    ->defaultValue(['file' => self::DEFAULT_GROUP])
                ->end()
            ->end();

        return $treeBuilder;
    }
}
