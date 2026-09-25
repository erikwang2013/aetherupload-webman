<?php

namespace AetherUpload\Adapter\Hyperf\Command;

use AetherUpload\Adapter\Hyperf\HyperfPaths;
use AetherUpload\Runtime;
use Hyperf\Command\Command;

/**
 * 把包内的配置、语言文件与前端资源分发到宿主：
 *   config/aetherupload.php  →  config/autoload/aetherupload.php（缺省才写，已存在不动）
 *   translations/            →  storage/translations/aetherupload/
 *   docs/js/                 →  public/vendor/aetherupload/js/
 *
 * 全部走「不覆盖」的文件系统端口，重装不会抹掉使用者改过的配置。
 */
class AetherUploadPublishCommand extends Command
{
    /** @var string|null 与 Hyperf\Command\Command::$name 的类型保持一致 */
    protected ?string $name = 'aetherupload:publish';

    /** @var string */
    protected string $description = 'Publish the aetherupload config, translations and front-end assets';

    public function handle(): int
    {
        $package = HyperfPaths::packagePath();
        $paths = new HyperfPaths();

        $this->publishConfig($package, $paths);

        Runtime::filesystem()->copyDir($package . '/translations', $paths->translationsPath() . '/aetherupload');
        Runtime::filesystem()->copyDir($package . '/docs/js', $paths->assetPath());

        $this->line('Translations published to ' . $paths->translationsPath() . '/aetherupload');
        $this->line('Assets published to ' . $paths->assetPath());
        $this->line('Done.');

        return 0;
    }

    private function publishConfig(string $package, HyperfPaths $paths): void
    {
        $target = $paths->basePath() . '/config/autoload/aetherupload.php';

        if ( is_file($target) ) {
            $this->line('config/autoload/aetherupload.php already exists, kept untouched.');

            return;
        }

        $dir = dirname($target);
        if ( ! is_dir($dir) ) {
            @mkdir($dir, 0755, true);
        }

        if ( @copy($package . '/config/aetherupload.php', $target) ) {
            $this->line('Generated config/autoload/aetherupload.php');
        } else {
            $this->error('Failed to write ' . $target);
        }
    }
}
