<?php

namespace AetherUpload\Adapter\Hyperf;

use AetherUpload\Adapter\Hyperf\Command\AetherUploadBuildRedisHashesCommand;
use AetherUpload\Adapter\Hyperf\Command\AetherUploadCleanUpDirectoryCommand;
use AetherUpload\Adapter\Hyperf\Command\AetherUploadListGroupsCommand;
use AetherUpload\Adapter\Hyperf\Command\AetherUploadPublishCommand;
use AetherUpload\Adapter\Hyperf\Listener\BootApplicationListener;

/**
 * Hyperf 的接入点：在应用的 config/config.php（或 config/autoload/*.php 的 ConfigProvider 列表）里
 * 加上本类的完全限定名即可，不需要改路由文件、也不需要注解扫描。
 */
class ConfigProvider
{
    public function __invoke(): array
    {
        return [
            // 绑定 Runtime + 注册路由
            'listeners' => [
                BootApplicationListener::class,
            ],

            // 命令一律在这里登记，不依赖 #[Command] 注解扫描
            // （注解扫描要求应用把 src/ 写进 scan.paths，插件不该提这个要求）
            'commands' => [
                AetherUploadBuildRedisHashesCommand::class,
                AetherUploadCleanUpDirectoryCommand::class,
                AetherUploadListGroupsCommand::class,
                AetherUploadPublishCommand::class,
            ],

            // 交给 hyperf/devtool 的 vendor:publish；aetherupload:publish 是它的等价物（含语言文件与前端资源）
            'publish' => [
                [
                    'id' => 'config',
                    'description' => 'aetherupload 的配置（config/autoload/aetherupload.php）',
                    'source' => __DIR__ . '/../../../config/aetherupload.php',
                    'destination' => BASE_PATH . '/config/autoload/aetherupload.php',
                ],
            ],
        ];
    }
}
