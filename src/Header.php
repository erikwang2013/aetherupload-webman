<?php

namespace AetherUpload;


class Header
{
    public $path;
    public $name;
    public $realPath;

    public function __construct($tempBaseName)
    {
        $this->name = $tempBaseName;
        $this->path = $this->getRelativePath();
        $this->realPath = $this->getRealPath();
    }

    public function write($content)
    {
        $handle = @fopen($this->realPath, 'c');

        if ( $handle === false ) {
            throw new \Exception(trans('write_header_fail'));
        }

        flock($handle, LOCK_EX);
        $ok = ftruncate($handle, 0) !== false && fwrite($handle, $content) !== false;
        flock($handle, LOCK_UN);
        fclose($handle);

        if ( $ok === false ) {
            throw new \Exception(trans('write_header_fail'));
        }
    }

    public function read()
    {
        if ( ($content = @file_get_contents($this->realPath)) === false ) {
            throw new \Exception(trans('read_header_fail'));
        }

        return $content;
    }

    public function delete()
    {
        if ( @unlink($this->realPath) === false ) {
            throw new \Exception(trans('delete_header_fail'));
        }
    }

    private function getRelativePath()
    {
        return ConfigMapper::get('root_dir') . DIRECTORY_SEPARATOR . '_header' . DIRECTORY_SEPARATOR . $this->name;
    }

    public function getRealPath()
    {
        return base_path(). DIRECTORY_SEPARATOR .$this->path;
    }

    public function exists()
    {
        return file_exists($this->realPath);
    }


}