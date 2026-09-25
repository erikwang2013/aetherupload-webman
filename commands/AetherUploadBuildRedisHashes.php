<?php

namespace app\command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Command\Command;
use AetherUpload\ConfigMapper;
use AetherUpload\SavedPathResolver;
use AetherUpload\RedisSavedPath;
use AetherUpload\Util;


class AetherUploadBuildRedisHashes extends Command
{

    protected static $defaultName = 'aetherupload:build';
    protected static $defaultDescription = 'Rebuild the correlations between hashes and file storage paths in Redis';

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $savedPathArr = [];
        $totalCount = 0;
        $output->writeln('Start rebuilding the correlations...');
        try {

            RedisSavedPath::deleteAll();

            foreach ( config(ConfigMapper::PREFIX.'groups') as $groupName => $group ) {

                // 分组名参与存储路径的编码，含下划线时写进去的记录永远解码不回来，跳过并告警而不是报成功
                if ( ! is_string($groupName) || str_contains($groupName, '_') ) {
                    $output->writeln('Invalid group name "' . $groupName . '": underscore is not allowed, skipped.');

                    continue;
                }

                $path = base_path().DIRECTORY_SEPARATOR.ConfigMapper::get('root_dir') . DIRECTORY_SEPARATOR . $group['group_dir'];

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

            $output->writeln($totalCount . ' items have been set in Redis.');
            $output->writeln('Done.');
        } catch ( \Exception $e ) {

            $output->writeln('Error: '.$e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;

    }

    public function getDirsPath($path)
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

    public function getFilesPath($path)
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
