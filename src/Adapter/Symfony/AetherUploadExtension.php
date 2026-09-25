<?php

namespace AetherUpload\Adapter\Symfony;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Reference;

class AetherUploadExtension extends Extension
{
    /** 命令壳：逻辑在 src/Console/*Runner，这里只注册「带 #[AsCommand] 属性的薄壳」 */
    const COMMANDS = [
        'aetherupload.command.build' => Console\BuildRedisHashesCommand::class,
        'aetherupload.command.clean' => Console\CleanUpDirectoryCommand::class,
        'aetherupload.command.groups' => Console\ListGroupsCommand::class,
        'aetherupload.command.publish' => Console\PublishCommand::class,
    ];

    /**
     * 配置树的名字是 aetherupload（不是按类名推导出来的 aether_upload），
     * 与 config/aetherupload.php 的逻辑键前缀、以及其余五个框架的 aetherupload. 前缀保持一致。
     */
    public function getAlias(): string
    {
        return 'aetherupload';
    }

    public function load(array $configs, ContainerBuilder $container): void
    {
        $config = $this->processConfiguration(new Configuration(), $configs);

        // 逻辑键 → 容器参数：内核（Runtime::config()）与路由文件（%aetherupload.route_preprocess%）读的是同一批值
        $this->exportParameters($container, 'aetherupload', $config);

        $container->register('aetherupload.adapter', SymfonyAdapter::class)
            ->setPublic(true)
            ->setArguments([
                new Reference('service_container'),
                new Reference('request_stack', ContainerInterface::IGNORE_ON_INVALID_REFERENCE),
                new Reference('translator', ContainerInterface::IGNORE_ON_INVALID_REFERENCE),
                new Reference('event_dispatcher', ContainerInterface::IGNORE_ON_INVALID_REFERENCE),
                new Reference('logger', ContainerInterface::IGNORE_ON_INVALID_REFERENCE),
                '%kernel.project_dir%',
            ]);

        // 命令：console.command 标签 + 类上的 #[AsCommand] 属性（Symfony 7 已移除 $defaultName 写法）。
        // 没装 symfony/console 的应用（纯 HTTP）直接跳过。
        if ( class_exists(Command::class) ) {
            foreach ( self::COMMANDS as $id => $class ) {
                $container->register($id, $class)->addTag('console.command');
            }
        }
    }

    /**
     * 把配置树的每个节点导出成容器参数（中间层子树也导出）：
     *   aetherupload                → 整棵树
     *   aetherupload.groups         → groups 子树
     *   aetherupload.groups.file.group_dir → 叶子
     * 这样内核拿点号逻辑键直接查参数即可，路由文件也能用 %aetherupload.route_preprocess%。
     */
    private function exportParameters(ContainerBuilder $container, string $name, array $config): void
    {
        $container->setParameter($name, $config);

        foreach ( $config as $key => $value ) {
            $isMap = is_array($value) && $value !== [] && array_keys($value) !== range(0, count($value) - 1);

            if ( $isMap ) {
                $this->exportParameters($container, $name . '.' . $key, $value);
                continue;
            }

            $container->setParameter($name . '.' . $key, $value);
        }
    }
}
