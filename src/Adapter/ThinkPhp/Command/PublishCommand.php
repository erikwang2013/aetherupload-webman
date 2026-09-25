<?php

namespace AetherUpload\Adapter\ThinkPhp\Command;

use AetherUpload\Runtime;
use think\console\Command;
use think\console\Input;
use think\console\Output;

/**
 * aetherupload:publish
 *
 * 把包内语言文件与前端 js 分发到应用里：
 *   translations/  →  app/lang/aetherupload/          （内核按 translationsPath()/aetherupload/<locale>/messages.php 查找）
 *   docs/js/       →  public/vendor/aetherupload/js/  （示例页与使用者接入都依赖这个 URL）
 *
 * 复制走 Runtime::filesystem()->copyDir()：**不覆盖已存在的文件**，重复执行安全，
 * 也不会抹掉使用者改过的语言文件。
 *
 * 不发布配置：适配器已把包内 config/aetherupload.php 作为基线并进宿主配置树
 * （见 AetherUploadService::registerDefaultConfig()，与 Laravel 的 mergeConfigFrom 同语义），
 * 宿主只需在 config/aetherupload.php 里写要覆盖的键。
 *
 * 逻辑不进 Runner：与 Symfony/Hyperf/Yii 三家的 publish 命令同构（各自两条 copyDir），
 * 抽到 src/Console/ 只是把两行代码换个地方摆，没有第二个调用方。
 */
class PublishCommand extends Command
{
    protected function configure()
    {
        $this->setName('aetherupload:publish')
            ->setDescription('Publish the aetherupload translations and front-end assets');
    }

    protected function execute(Input $input, Output $output): int
    {
        $package    = dirname(__DIR__, 4);
        $filesystem = Runtime::filesystem();

        $translations = Runtime::paths()->translationsPath() . '/aetherupload';
        $assets       = Runtime::paths()->assetPath();

        $filesystem->copyDir($package . '/translations', $translations);
        $output->writeln('translations → ' . $translations);

        $filesystem->copyDir($package . '/docs/js', $assets);
        $output->writeln('assets       → ' . $assets);

        $output->writeln('Done.');

        return 0;
    }
}
