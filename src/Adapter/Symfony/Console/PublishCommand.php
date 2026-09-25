<?php

namespace AetherUpload\Adapter\Symfony\Console;

use AetherUpload\Runtime;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * 把语言文件、前端资源、配置样例分发到应用里（Flex recipe 的等价物）。
 *
 * 复制走 Runtime::filesystem()->copyDir()：**不覆盖已存在的文件**，重复执行安全，
 * 也不会抹掉使用者改过的配置。要强制刷新用 --force。
 */
#[AsCommand(
    name: 'aetherupload:publish',
    description: 'Publish the aetherupload translations, assets and config sample into the app'
)]
class PublishCommand extends Command
{
    /** 配置样例：只列常用键并全部注释掉，未写出的键一律沿用配置树的默认值 */
    const CONFIG_SAMPLE = <<<'YAML'
# AetherUpload 配置样例（由 aetherupload:publish 生成，重复执行不会覆盖本文件）
#
# 全部键与默认值见 vendor/erikwang2013/aetherupload-webman/config/aetherupload.php，
# 以及 AetherUpload\Adapter\Symfony\Configuration（配置树）。写在这里的键会覆盖默认值。
aetherupload:
    # enable: true
    # instant_completion: false          # 秒传，需要 redis 客户端
    # resource_redis_expire: 604800
    # root_dir: storage/app/aetherupload
    # chunk_size: 1000000
    # resource_subdir_rule: month        # year / month / date / const
    # lax_mode: false
    # x_accel_redirect: false
    # route_preprocess: /aetherupload/preprocess
    # route_uploading: /aetherupload/uploading
    # route_display: /aetherupload/display
    # route_download: /aetherupload/download
    # groups:
    #     file:
    #         group_dir: file
    #         resource_maxsize: 104857600
    #         resource_extensions: [jpg, jpeg, png, gif, webp, bmp, svg, pdf, doc, docx, xls, xlsx, ppt, pptx, txt, zip, rar, 7z, mp4, mp3, wav]
    #         event_before_upload_complete: false
    #         event_upload_complete: false

YAML;

    protected function configure(): void
    {
        $this->addOption('tag', 't', InputOption::VALUE_REQUIRED, 'translations / assets / config / all', 'all')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Overwrite files that already exist');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $tag = (string)$input->getOption('tag');
        $force = (bool)$input->getOption('force');
        $package = dirname(__DIR__, 4);
        $filesystem = Runtime::filesystem();

        $targets = [
            'translations' => [$package . '/translations', Runtime::paths()->translationsPath() . '/aetherupload'],
            'assets' => [$package . '/docs/js', Runtime::paths()->assetPath()],
        ];

        if ( ! in_array($tag, array_merge(array_keys($targets), ['config', 'all']), true) ) {
            $output->writeln('Unknown tag "' . $tag . '". Available: ' . implode(', ', array_merge(array_keys($targets), ['config', 'all'])));

            return Command::FAILURE;
        }

        foreach ( $targets as $name => $paths ) {
            if ( $tag !== 'all' && $tag !== $name ) {
                continue;
            }

            if ( $force ) {
                $filesystem->removeDir($paths[1]);
            }

            $filesystem->copyDir($paths[0], $paths[1]);
            $output->writeln($name . ' → ' . $paths[1]);
        }

        if ( $tag === 'all' || $tag === 'config' ) {
            $configFile = Runtime::paths()->basePath() . '/config/packages/aetherupload.yaml';

            if ( is_file($configFile) && ! $force ) {
                $output->writeln('config → ' . $configFile . ' (已存在，跳过；--force 可覆盖)');
            } else {
                $dir = dirname($configFile);
                if ( ! is_dir($dir) ) {
                    mkdir($dir, 0755, true);
                }
                file_put_contents($configFile, self::CONFIG_SAMPLE);
                $output->writeln('config → ' . $configFile);
            }
        }

        $output->writeln('Done.');

        return Command::SUCCESS;
    }
}
