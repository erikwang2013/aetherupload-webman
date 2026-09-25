<?php

namespace AetherUpload\Console;

use AetherUpload\Runtime;

/**
 * aetherupload:groups 的业务逻辑。
 *
 * 刻意不继承任何基类、不引用任何宿主命名空间：六个框架的命令壳都委托到这里，逻辑只有一份。
 */
class ListGroupsRunner
{
    /**
     * 列出分组并补齐缺失的目录。
     *
     * @param callable(string): void $write 逐行输出回调
     * @return int 0 成功 / 1 失败（与 Symfony\Console\Command::SUCCESS|FAILURE 同值）
     */
    public function run(callable $write): int
    {
        $rootDir = Runtime::basePath().DIRECTORY_SEPARATOR.Runtime::config()->get('root_dir');
        try {

            if ( ! is_dir($rootDir) ) {
                mkdir($rootDir . DIRECTORY_SEPARATOR . '_header', 0755 ,true);
                $write('Root directory "' . $rootDir . '" has been created.');
            }

            $directories = array_map(function ($directory) {
                return basename($directory);
            }, $this->getDirs($rootDir));

            $groupDirs = array_map(function ($v) {
                return $v['group_dir'];
            }, Runtime::config()->get('groups'));

            foreach ( $groupDirs as $groupDir ) {
                if ( in_array($groupDir, $directories) ) {
                    continue;
                } else {
                    if ( mkdir($rootDir . DIRECTORY_SEPARATOR . $groupDir, 0755) ) {
                        $write('Directory "' . $rootDir . DIRECTORY_SEPARATOR . $groupDir . '" has been created.');
                    } else {
                        throw new \Exception('Fail to create directory "' . $rootDir . DIRECTORY_SEPARATOR . $groupDir . '".');
                    }
                }
            }

            $write('Group-Directory List:');

            foreach ( Runtime::config()->get('groups') as $groupName => $groupArr ) {
                if ( str_contains($groupName, '_') ) {
                    // 分组名参与存储路径的编码，含下划线时该分组下的资源无法被定位，必须改名
                    $write('Invalid group name "' . $groupName . '": underscore is not allowed, rename the group.');

                    continue;
                }

                if ( is_dir($rootDir . DIRECTORY_SEPARATOR . $groupArr['group_dir']) ) {
                    $write($groupName . ' - ' . $rootDir.DIRECTORY_SEPARATOR.$groupArr['group_dir']);
                }
            }

        } catch ( \Exception $e ) {

            $write('Error: '.$e->getMessage());

            return 1;
        }

        return 0;
    }

    private function getDirs($path)
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
}
