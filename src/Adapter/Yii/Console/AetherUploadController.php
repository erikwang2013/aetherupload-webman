<?php

namespace AetherUpload\Adapter\Yii\Console;

use AetherUpload\Console\BuildRedisHashesRunner;
use AetherUpload\Console\CleanUpDirectoryRunner;
use AetherUpload\Console\ListGroupsRunner;
use AetherUpload\Runtime;
use yii\console\Controller;
use yii\console\ExitCode;

/**
 * 控制台壳：只做参数解析与输出通道转接，业务逻辑在 AetherUpload\Console\*Runner
 * （同一个 Runner 也被另外五个框架的命令壳复用）。
 *
 *   yii aetherupload/groups        列出配置里的分组，并建好上传根目录与各分组目录
 *   yii aetherupload/build         重建 redis 里 hash → 存储路径的对应关系
 *   yii aetherupload/clean 7       清理 7 天前遗留的分块残留（天数默认 2）
 *   yii aetherupload/publish       把语言文件、前端资源、配置样例分发到应用里
 *
 * 通过 Bootstrap 的 controllerMap 注册（宿主不用改自己的 console 配置，
 * 只要 console 应用的 bootstrap 数组里也加上 Bootstrap）。
 */
class AetherUploadController extends Controller
{
    /** @var int aetherupload/clean 保留多少天内的分块残留 */
    public $days = 2;

    public function options($actionID)
    {
        // days 只在 clean 下有意义的选项，其余动作保持父类的默认选项集
        return $actionID === 'clean' ? array_merge(parent::options($actionID), ['days']) : parent::options($actionID);
    }

    public function actionGroups(): int
    {
        return (new ListGroupsRunner())->run($this->writer());
    }

    public function actionBuild(): int
    {
        return (new BuildRedisHashesRunner())->run($this->writer());
    }

    public function actionClean(): int
    {
        return (new CleanUpDirectoryRunner())->run($this->writer(), (int)$this->days);
    }

    /**
     * 发布语言文件与前端资源，并落一份配置样例。
     *
     * 复制走 Runtime::filesystem()->copyDir()：**不覆盖已存在的文件**，重复执行安全，
     * 也不会抹掉使用者改过的语言文件或配置样例。
     *
     * 语言文件必须真发布一份：内核按 translationsPath()/aetherupload/{locale}/messages.php 查找，
     * 而 Yii 的 PhpMessageSource 只认应用里的 directories，不认 vendor 目录，
     * 因此这一步是「装完能用」的必要条件（webman 的 Install 同样会复制语言文件）。
     */
    public function actionPublish(): int
    {
        $package = dirname(__DIR__, 4);
        $filesystem = Runtime::filesystem();
        $translations = Runtime::paths()->translationsPath() . '/aetherupload';
        $assets = Runtime::paths()->assetPath();

        $filesystem->copyDir($package . '/translations', $translations);
        $this->stdout('translations → ' . $translations . "\n");

        $filesystem->copyDir($package . '/docs/js', $assets);
        $this->stdout('assets       → ' . $assets . "\n");

        $sample = Runtime::paths()->basePath() . '/config/aetherupload.php';

        if ( is_file($sample) ) {
            $this->stdout('config       → ' . $sample . '（已存在，跳过）' . "\n");
        } else {
            $dir = dirname($sample);

            if ( ! is_dir($dir) ) {
                mkdir($dir, 0755, true);
            }

            file_put_contents($sample, self::CONFIG_SAMPLE);
            $this->stdout('config       → ' . $sample . "\n");
        }

        $this->stdout('把该文件的内容合并进 config/web.php 的 params，或直接 require 它（见文件内注释）。' . "\n");

        return ExitCode::OK;
    }

    /** 配置样例：写成可直接 require 的数组，宿主把它塞进 params['aetherupload'] 即可 */
    const CONFIG_SAMPLE = <<<'PHP'
<?php

/**
 * AetherUpload 配置样例（由 `yii aetherupload/publish` 生成，重复执行不会覆盖本文件）。
 *
 * 用法：在 config/web.php（控制台则 config/console.php）里
 *
 *   'params' => [
 *       'aetherupload' => require __DIR__ . '/aetherupload.php',
 *   ],
 *
 * 全部键与默认值见 vendor/erikwang2013/aetherupload-webman/config/aetherupload.php，
 * 这里只列常用的几个，未列出的键一律沿用默认值。
 */

return [
    // 秒传：需要 redis 组件（yii2-redis）与浏览器支持 FileReader/File.slice()。默认关闭。
    'instant_completion' => false,

    // 上传根目录名（相对项目根，即 Yii 的 @app）
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

    /**
     * 内核的 Runner 只要求「给一行文本」；Yii 侧写 STDOUT。
     *
     * @return callable
     */
    private function writer(): callable
    {
        return function (string $line): void {
            $this->stdout($line . "\n");
        };
    }
}
