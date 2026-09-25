<?php

namespace AetherUpload\Adapter\Symfony;

use AetherUpload\Runtime;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\HttpKernel\Bundle\Bundle;

/**
 * Symfony 侧的入口：注册配置扩展，并在 boot() 里把内核绑到 Symfony 适配器上。
 *
 * 应用侧只需要：
 *   config/bundles.php:  AetherUpload\Adapter\Symfony\AetherUploadBundle::class => ['all' => true]
 *   config/routes.yaml:  aetherupload: { resource: '@AetherUploadBundle/Resources/config/routes.php', type: php }
 *
 * 路由不做自动注册：Symfony 里 bundle 路由必须由应用的 routing 资源 import
 * （framework.router.resource 是单个必填字符串，没有「bundle 自带路由自动加载」的机制）。
 */
class AetherUploadBundle extends Bundle
{
    /**
     * 不按命名约定推导（约定会找 DependencyInjection\AetherUploadExtension），显式给出扩展实例。
     */
    public function getContainerExtension(): ?ExtensionInterface
    {
        if ( $this->extension === null ) {
            $this->extension = new AetherUploadExtension();
        }

        return $this->extension;
    }

    /**
     * 每个进程一次：容器已编译，参数与公开服务都可用。
     *
     * 绑定放在这里（而非某个请求监听器）是为了让 CLI 命令也能用 —— 命令行下没有请求，
     * contextToken() 退化为 null，与 webman 的 CLI 行为一致。
     */
    public function boot(): void
    {
        if ( $this->container === null || ! $this->container->has('aetherupload.adapter') ) {
            return;
        }

        Runtime::bind($this->container->get('aetherupload.adapter'));

        $this->loadTranslations();
    }

    /**
     * 语言文件在安装时被分发到 translations/aetherupload/<locale>/messages.php。
     * 这里按语种登记一次（translator 端口内部进程级幂等，重复 boot 不会重复 I/O）；
     * UploadController 构造函数里的登记是每请求兜底，两者都指向同一份文件。
     */
    private function loadTranslations(): void
    {
        $directory = Runtime::paths()->translationsPath() . DIRECTORY_SEPARATOR . 'aetherupload';

        foreach ( ['en', 'zh'] as $locale ) {
            Runtime::translator()->loadMessages($directory, $locale);
        }
    }
}
