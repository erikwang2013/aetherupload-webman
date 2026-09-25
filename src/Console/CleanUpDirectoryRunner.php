<?php

namespace AetherUpload\Console;

use AetherUpload\Runtime;

/**
 * aetherupload:clean 的业务逻辑。
 *
 * 刻意不继承任何基类、不引用任何宿主命名空间：六个框架的命令壳都委托到这里，逻辑只有一份。
 */
class CleanUpDirectoryRunner
{
    /**
     * 删除 N 天前创建的过期临时文件（按真实 mtime 判定）。
     *
     * @param callable(string): void $write 逐行输出回调
     * @param int $days 天数，必须大于 0
     * @return int 0 成功 / 1 失败（与 Symfony\Console\Command::SUCCESS|FAILURE 同值）
     */
    public function run(callable $write, int $days): int
    {
        $invalidHeaders = [];
        $invalidFiles = [];
        $dueTime = strtotime('-' . $days . ' day');
        $rootDir = Runtime::basePath().DIRECTORY_SEPARATOR.Runtime::config()->get('root_dir');

        try {
            if($days <= 0){
                throw new \Exception("invalid param 'days', should be greater than 0 .");
            }

            $write('Start deleting partial files created '.$days.' days ago...');

            $headers = $this->getFiles($rootDir . DIRECTORY_SEPARATOR . '_header');

            foreach ( $headers as $header ) {

                if ( pathinfo($header, PATHINFO_EXTENSION) !== '' ) {
                    continue;
                }

                // 临时文件名是随机串，不再带时间戳前缀，只能按真实修改时间判断
                if ( filemtime($header) < $dueTime ) {
                    $invalidHeaders[] = $header;
                }
            }

            $this->deleteFiles($invalidHeaders);

            $write(count($invalidHeaders) . ' invalid headers have been deleted.');

            $groupDirs = array_map(function ($v) {
                return $v['group_dir'];
            }, Runtime::config()->get('groups'));

            foreach ( $groupDirs as $groupDir ) {
                $subDirNames = $this->getDirs($rootDir . DIRECTORY_SEPARATOR . $groupDir);

                foreach ( $subDirNames as $subDirName ) {
                    $files = $this->getFiles($subDirName);

                    foreach ( $files as $file ) {

                        if ( pathinfo($file, PATHINFO_EXTENSION) !== 'part' ) {
                            continue;
                        }

                        // 同上：按真实修改时间判断，避免误删正在上传的分块文件
                        if ( filemtime($file) < $dueTime ) {
                            $invalidFiles[] = $file;
                        }
                    }
                }
            }

            $this->deleteFiles($invalidFiles);

            $write(count($invalidFiles) . ' invalid files have been deleted.');
            $write('Done.');

        } catch ( \Exception $e ) {

            $write('Error: '.$e->getMessage());

            return 1;
        }

        return 0;
    }

    private function getDirs($path)
    {
        $dir = opendir($path);

        if ( $dir === false ) {
            return;
        }

        while ( ($value = readdir($dir)) !== false ) {
            if ( $value != '.' && $value != '..' && is_dir($path.DIRECTORY_SEPARATOR.$value) ) {
                yield $path.DIRECTORY_SEPARATOR.$value;
            }
        }

        closedir($dir);
    }

    private function getFiles($path)
    {
        $dir = opendir($path);

        if ( $dir === false ) {
            return;
        }

        while ( ($value = readdir($dir)) !== false ) {
            if ( $value != '.' && $value != '..' && is_file($path.DIRECTORY_SEPARATOR.$value) ) {
                yield $path.DIRECTORY_SEPARATOR.$value;
            }
        }

        closedir($dir);
    }

    private function deleteFiles($arr)
    {
        foreach ($arr as $file){
            if ( file_exists($file) === true && unlink($file) === false ) {
                throw new \Exception('fail to delete '.$file);
            }
        }
    }
}
