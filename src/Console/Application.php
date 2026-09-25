<?php

namespace AetherUpload\Console;

use AetherUpload\Runtime;
use Symfony\Component\Console\Application as SymfonyApplication;

/**
 * 「宿主没有控制台约定」时的现成入口：aetherupload:build / :clean / :groups / :publish 四条命令。
 *
 * 需要 symfony/console（本包 require-dev / suggest，未安装时本类不可用）。
 * 入口脚本要先把适配器绑上，Runner 才能解析 base_path 与配置：
 *
 *     // bin/aetherupload
 *     require __DIR__ . '/../vendor/autoload.php';
 *     \AetherUpload\Adapter\Slim\Bootstrap::bind(require __DIR__ . '/../config/aetherupload.php', dirname(__DIR__));
 *     exit((new \AetherUpload\Adapter\Slim\Console\Application())->run());
 *
 * 业务逻辑不在这里：三条命令的实现在 src/Console/*Runner（七个框架共用同一份），
 * 本类只负责拼装命令壳与 publish（复制两个目录）。Slim 与原生 PHP 的入口类都继承本类。
 */
class Application extends SymfonyApplication
{
    public function __construct()
    {
        parent::__construct('aetherupload');

        $this->add(new RunnerCommand(
            'aetherupload:build',
            'Rebuild the correlations between hashes and file storage paths in Redis',
            static function (array $arguments, callable $write): int {
                return (new BuildRedisHashesRunner())->run($write);
            }
        ));

        $this->add(new RunnerCommand(
            'aetherupload:clean',
            'Remove partial files which are created a few days ago',
            static function (array $arguments, callable $write): int {
                return (new CleanUpDirectoryRunner())->run($write, (int)$arguments['days']);
            },
            ['days' => 2]
        ));

        $this->add(new RunnerCommand(
            'aetherupload:groups',
            'List and create the directories for the groups',
            static function (array $arguments, callable $write): int {
                return (new ListGroupsRunner())->run($write);
            }
        ));

        $this->add(new RunnerCommand(
            'aetherupload:publish',
            'Publish the aetherupload translations and front-end assets',
            static function (array $arguments, callable $write): int {
                // static:: 而非 self:: —— 子类（各宿主的入口类）可以换掉发布逻辑
                return static::publish($write);
            }
        ));
    }

    /**
     * aetherupload:publish：把包内的语言文件与前端 js 分发到应用里。
     *
     *   translations/  →  {translationsPath}/aetherupload   （内核按该路径查找 messages.php）
     *   docs/js/       →  {assetPath}                       （示例页与使用者接入都依赖这个 URL）
     *
     * 复制走 Runtime::filesystem()->copyDir()：**不覆盖已存在的文件**，重复执行安全，
     * 也不会抹掉使用者改过的语言文件/脚本 —— 与 webman 的 Install::install() 同语义。
     *
     * 不发布配置：适配器已把包内 config/aetherupload.php 作为基线（顶层浅合并），
     * 使用者只需把自己要覆盖的键写进传给 Bootstrap 的数组。
     *
     * 逻辑不进 src/Console/*Runner：那三个 Runner 是六个框架共享的业务逻辑，
     * 这里只是「复制两个目录」，与 Symfony/ThinkPHP/Hyperf/Yii 的 publish 命令同构。
     */
    protected static function publish(callable $write): int
    {
        // src/Console/ → src/ → 包根
        $package = dirname(__DIR__, 2);
        $filesystem = Runtime::filesystem();

        $translations = Runtime::paths()->translationsPath() . '/aetherupload';

        $filesystem->copyDir($package . '/translations', $translations);
        $write('translations → ' . $translations);

        $assets = Runtime::paths()->assetPath();

        $filesystem->copyDir($package . '/docs/js', $assets);
        $write('assets       → ' . $assets);

        $write('Done.');

        return 0;
    }
}
