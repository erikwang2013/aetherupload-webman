<?php

namespace AetherUpload\Adapter\Yii3\Command;

use AetherUpload\Console\RunnerCommand;
use AetherUpload\Runtime;

/**
 * aetherupload/publish：把语言文件与前端资源分发到应用里，并落一份配置样例。
 *
 *   translations/  →  {translationsPath}/aetherupload   （内核按该路径查找 messages.php）
 *   docs/js/       →  {assetPath}                       （示例页与使用者接入都依赖这个 URL）
 *   config/aetherupload.php                             （样例，已存在则不覆盖）
 *
 * 复制走 Runtime::filesystem()->copyDir()：**不覆盖已存在的文件**，重复执行安全，
 * 也不会抹掉使用者改过的语言文件或配置样例 —— 与 webman 的 Install::install() 同语义。
 *
 * 配置样例写给入口脚本 require（`Bootstrap::bind(require __DIR__ . '/../config/aetherupload.php', …)`），
 * 不是 Yii3 的配置组文件（config/configuration.php 那套是宿主自己的 DI/params 分组，本包不掺和）。
 */
final class PublishCommand extends RunnerCommand
{
    /** 配置样例：只列常用键，未列出的键一律沿用包内默认值 */
    const CONFIG_SAMPLE = <<<'PHP'
<?php

/**
 * AetherUpload 配置样例（由 `yii aetherupload/publish` 生成，重复执行不会覆盖本文件）。
 *
 * 用法：在入口脚本里交给适配器
 *
 *   \AetherUpload\Adapter\Yii3\Bootstrap::bind(
 *       require __DIR__ . '/../config/aetherupload.php',
 *       $root = dirname(__DIR__),
 *       ['redis' => static fn () => new \Predis\Client(['database' => 0])]
 *   );
 *
 * 全部键与默认值见 vendor/erikwang2013/aetherupload-webman/config/aetherupload.php，
 * 这里只列常用的几个，未列出的键一律沿用默认值。
 */

return [
    // 秒传：需要 redis 客户端（见入口脚本的 redis 选项）与浏览器支持 FileReader/File.slice()。默认关闭。
    'instant_completion' => false,

    // 上传根目录名（相对项目根）
    'root_dir' => 'storage/app/aetherupload',

    // 分块大小（字节）
    'chunk_size' => 1000000,

    // 子目录生成规则：year / month / date / const
    'resource_subdir_rule' => 'month',

    // 资源分组
    'groups' => [
        'file' => [
            'group_dir'                    => 'file',
            'resource_maxsize'             => 104857600,
            'resource_extensions'          => ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg', 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'zip', 'rar', '7z', 'mp4', 'mp3', 'wav'],
            'event_before_upload_complete' => false,
            'event_upload_complete'        => false,
        ],
    ],
];
PHP;

    public function __construct()
    {
        parent::__construct(
            'aetherupload/publish',
            'Publish the aetherupload translations, assets and config sample into the app',
            static function (array $arguments, callable $write): int {
                return self::publish($write);
            }
        );
    }

    private static function publish(callable $write): int
    {
        // src/Adapter/Yii3/Command/ → 包根
        $package = dirname(__DIR__, 4);
        $filesystem = Runtime::filesystem();

        $translations = Runtime::paths()->translationsPath() . '/aetherupload';
        $filesystem->copyDir($package . '/translations', $translations);
        $write('translations → ' . $translations);

        $assets = Runtime::paths()->assetPath();
        $filesystem->copyDir($package . '/docs/js', $assets);
        $write('assets       → ' . $assets);

        $sample = Runtime::paths()->basePath() . '/config/aetherupload.php';

        if ( is_file($sample) ) {
            $write('config       → ' . $sample . '（已存在，跳过）');
        } else {
            $dir = dirname($sample);

            if ( ! is_dir($dir) ) {
                mkdir($dir, 0755, true);
            }

            file_put_contents($sample, self::CONFIG_SAMPLE);
            $write('config       → ' . $sample);
        }

        return 0;
    }
}
