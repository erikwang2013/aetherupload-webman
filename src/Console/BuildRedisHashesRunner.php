<?php

namespace AetherUpload\Console;

use AetherUpload\RedisSavedPath;
use AetherUpload\Runtime;
use AetherUpload\SavedPathResolver;
use AetherUpload\Util;

/**
 * aetherupload:build 的业务逻辑。
 *
 * 刻意不继承任何基类、不引用任何宿主命名空间：六个框架的命令壳（Symfony / Illuminate\
 * Console / think\console / yii\console / Hyperf …）都委托到这里，逻辑只有一份。
 */
class BuildRedisHashesRunner
{
    /**
     * 按磁盘现状重建秒传索引。
     *
     * @param callable(string): void $write 逐行输出回调
     * @return int 0 成功 / 1 失败（与 Symfony\Console\Command::SUCCESS|FAILURE 同值）
     */
    public function run(callable $write): int
    {
        $savedPathArr = [];
        $totalCount = 0;
        $write('Start rebuilding the correlations...');
        try {

            RedisSavedPath::deleteAll();

            foreach ( Runtime::config()->get('groups') as $groupName => $group ) {

                // 分组名参与存储路径的编码，含下划线时写进去的记录永远解码不回来，跳过并告警而不是报成功
                if ( ! is_string($groupName) || str_contains($groupName, '_') ) {
                    $write('Invalid group name "' . $groupName . '": underscore is not allowed, skipped.');

                    continue;
                }

                $path = Runtime::basePath().DIRECTORY_SEPARATOR.Runtime::config()->get('root_dir') . DIRECTORY_SEPARATOR . $group['group_dir'];

                $subDirNames = $this->getDirsPath($path);

                foreach ( $subDirNames as $subDirName ) {
                    $fileNames = $this->getFilesPath($path.DIRECTORY_SEPARATOR.$subDirName);
                    foreach ( $fileNames as $fileName ) {
                        if ( pathinfo($fileName, PATHINFO_EXTENSION) === 'part' ) {
                            continue;
                        }

                        $hash = pathinfo($fileName, PATHINFO_FILENAME);

                        // 目录里可能存在非本插件命名的文件（.DS_Store、.htaccess、带空格的临时文件等），跳过而不是中断整个重建
                        if ( Util::isSafePathComponent($hash, false, 64) === false ) {
                            continue;
                        }

                        $savedPathArr[RedisSavedPath::getKey($groupName, $hash)] = SavedPathResolver::encode($groupName, basename($subDirName), basename($fileName));
                        $totalCount++;

                        if ( count($savedPathArr) >= 1000 ) {
                            RedisSavedPath::setMulti($savedPathArr);
                            $savedPathArr = [];
                        }
                    }
                }
            }

            if ( ! empty($savedPathArr) ) {
                RedisSavedPath::setMulti($savedPathArr);
            }

            $write($totalCount . ' items have been set in Redis.');
            $write('Done.');
        } catch ( \Exception $e ) {

            $write('Error: '.$e->getMessage());

            return 1;
        }

        return 0;
    }

    private function getDirsPath($path)
    {
        $arr = array();
        $data = scandir($path);
        foreach ($data as $value){
            if($value != '.' && $value != '..'){
                if(is_dir($path.DIRECTORY_SEPARATOR.$value)){
                    $arr[] = $value;
                }
            }
        }
        return $arr;
    }

    private function getFilesPath($path)
    {
        $arr = array();
        $data = scandir($path);
        foreach ($data as $value){
            if($value != '.' && $value != '..'){
                if(is_file($path.DIRECTORY_SEPARATOR.$value)){
                    $arr[] = $value;
                }
            }
        }
        return $arr;
    }
}
