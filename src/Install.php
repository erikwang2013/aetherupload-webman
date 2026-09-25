<?php
namespace AetherUpload;

class Install
{
    const WEBMAN_PLUGIN = true;

    /** 配置读不到 root_dir 时的回落值 */
    const DEFAULT_ROOT_DIR = 'storage/app/aetherupload';

    /**
     * @var array
     */
    protected static $pathRelation = array (
      '../config' => 'config/plugin/erikwang2013/aetherupload-webman',
      '../docs/js' => 'public/vendor/aetherupload/js',
      '../commands' => 'app/command',
      '../translations' => 'resource/translations/aetherupload',
    );

    /**
     * Install
     * @return void
     */
    public static function install()
    {
        // 本方法由 webman 的 composer 脚本（post-package-install → support\Plugin::install）调用，
        // 那一刻宿主应用还没启动、route.php 还没被加载，因此 Runtime 尚未绑定适配器。
        // 这里是 webman 专属安装器（见 WEBMAN_PLUGIN 常量），自己兜底绑定，否则 `composer require` 就报错。
        if ( ! Runtime::isBound() ) {
            Runtime::bind(new Adapter\Webman\WebmanAdapter());
        }

        // 根目录与分组目录都是配置项（root_dir、groups.<name>.group_dir），不能硬编码。
        // 全新安装时宿主的 config() 里还没有本插件的配置（配置文件正是本次安装才复制进去的），
        // 此时回落到插件自带的默认配置，保证默认分组目录一定建出来 —— 否则 preprocess 会因
        // 分组目录不存在而失败（createGroupSubDir 是非递归 mkdir，父目录缺失直接返回 false）。
        $defaults = self::shippedConfig();

        $rootDir = Runtime::config()->get('root_dir');
        if ( ! is_string($rootDir) || $rootDir === '' ) {
            $rootDir = is_string($defaults['root_dir'] ?? null) ? $defaults['root_dir'] : self::DEFAULT_ROOT_DIR;
        }

        $groups = Runtime::config()->get('groups');
        if ( ! is_array($groups) || $groups === [] ) {
            $groups = is_array($defaults['groups'] ?? null) ? $defaults['groups'] : [];
        }

        $root = Runtime::basePath().'/'.$rootDir;
        @mkdir($root, 0755 ,true);
        @mkdir($root."/_header", 0755 ,true);

        foreach ( (array)$groups as $group ) {
            $groupDir = is_array($group) ? ($group['group_dir'] ?? '') : '';
            if ( ! is_string($groupDir) || $groupDir === '' ) {
                continue;
            }
            @mkdir($root.'/'.$groupDir, 0755 ,true);
        }

        static::installByRelation();
    }

    /**
     * 插件自带的默认配置（config/app.php）。
     *
     * 全新安装时宿主 config() 里还没有本插件的配置——配置文件正是本次安装才复制进去的——
     * 用它兜底。读不到或内容异常时返回空数组，由调用方各自回落常量：安装器绝不抛异常。
     */
    private static function shippedConfig(): array
    {
        $path = __DIR__ . '/../config/app.php';

        if ( ! is_file($path) ) {
            return [];
        }

        try {
            $config = require $path;
        } catch ( \Throwable $e ) {
            return [];
        }

        return is_array($config) ? $config : [];
    }

    /**
     * Uninstall
     * @return void
     */
    public static function uninstall()
    {
        self::uninstallByRelation();
    }

    /**
     * installByRelation
     * @return void
     */
    public static function installByRelation()
    {
        foreach (static::$pathRelation as $source => $dest) {
            if ($pos = strrpos($dest, '/')) {
                $parent_dir = Runtime::basePath().'/'.substr($dest, 0, $pos);
                if (!is_dir($parent_dir)) {
                    mkdir($parent_dir, 0777, true);
                }
            }
            //symlink(__DIR__ . "/$source", Runtime::basePath()."/$dest");
            // 经文件系统端口复制：不覆盖目标已存在的文件（重装不抹掉使用者改过的配置）
            Runtime::filesystem()->copyDir(__DIR__ . "/$source", Runtime::basePath()."/$dest");
        }
    }

    /**
     * uninstallByRelation
     * @return void
     */
    public static function uninstallByRelation()
    {
        foreach (static::$pathRelation as $source => $dest) {
            /*if (is_link(Runtime::basePath()."/$dest")) {
                unlink(Runtime::basePath()."/$dest");
            }*/
            Runtime::filesystem()->removeDir(Runtime::basePath()."/$dest");
        }
    }
    
}
