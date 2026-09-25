<?php

namespace app\command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Command\Command;
use AetherUpload\ConfigMapper;
use Symfony\Component\Console\Input\InputArgument;


class AetherUploadCleanUpDirectory extends Command
{

    protected static $defaultName = 'aetherupload:clean {days=2}';
    protected static $defaultDescription = 'Remove partial files which are created a few days ago';

    /**
     * @return void
     */
    protected function configure()
    {
        $this->addArgument('days', InputArgument::OPTIONAL, 'The number of days from today', 2);
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $invalidHeaders = [];
        $invalidFiles = [];
        $days = $input->getArgument('days');
        $dueTime = strtotime('-' . $days . ' day');
        $rootDir = base_path().DIRECTORY_SEPARATOR.config(ConfigMapper::PREFIX.'root_dir');

        try {
            if($days <= 0){
                throw new \Exception("invalid param 'days', should be greater than 0 .");
            }

            $output->writeln('Start deleting partial files created '.$days.' days ago...');

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

            $output->writeln(count($invalidHeaders) . ' invalid headers have been deleted.');

            $groupDirs = array_map(function ($v) {
                return $v['group_dir'];
            }, config(ConfigMapper::PREFIX.'groups'));

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

            $output->writeln(count($invalidFiles) . ' invalid files have been deleted.');
            $output->writeln('Done.');

        } catch ( \Exception $e ) {

            $output->writeln('Error: '.$e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }



    public function getDirs($path)
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

    public function getFiles($path)
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

    public function deleteFiles($arr)
    {
        foreach ($arr as $file){
            if ( file_exists($file) === true && unlink($file) === false ) {
                throw new \Exception('fail to delete '.$file);
            }
        }
    }




}