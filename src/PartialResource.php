<?php

namespace AetherUpload;


class PartialResource
{
    public $tempName;
    public $group;
    public $groupDir;
    public $groupSubDir;
    public $header;
    public $path;
    public $realPath;
    public $maxSize;
    public $allowedExtensions;
    public $forbiddenExtensions;

    public function __construct($tempBaseName, $extension, $groupSubDir)
    {
        // 快照 group 配置：webman 单进程内请求交错会覆盖 ConfigMapper 单例，
        // 校验与路径必须基于本请求自己的配置，不能滞后读取单例
        $this->group = ConfigMapper::get('group');
        $this->groupDir = ConfigMapper::get('group_dir');
        $this->maxSize = ConfigMapper::get('resource_maxsize');
        $this->allowedExtensions = ConfigMapper::get('resource_extensions');
        $this->forbiddenExtensions = ConfigMapper::get('forbidden_extensions');
        $this->tempName = Util::getFileName($tempBaseName, $extension);
        $this->groupSubDir = $groupSubDir;
        $this->path = $this->getPath();
        $this->realPath = $this->getRealPath();
        $this->header = new Header($tempBaseName);
    }

    public function create()
    {
        if ( $this->createGroupSubDir() === false ) {
            throw new \Exception(trans('create_subfolder_fail'));
        }

        if ( file_put_contents($this->realPath, '', false) === false ) {
            throw new \Exception(trans('create_resource_fail'));
        }

    }

    public function append($chunkRealPath)
    {
        $handle = @fopen($chunkRealPath, 'rb');

        if ( $handle === false ) {
            throw new \Exception(trans('upload_error'));
        }

        $target = @fopen($this->realPath, 'ab');

        if ( $target === false ) {
            fclose($handle);
            throw new \Exception(trans('write_resource_fail'));
        }

        flock($target, LOCK_EX);
        $copied = stream_copy_to_stream($handle, $target);
        flock($target, LOCK_UN);

        fclose($target);
        fclose($handle);

        if ( $copied === false ) {
            throw new \Exception(trans('write_resource_fail'));
        }
    }

    public function delete()
    {
        if ( unlink($this->realPath) === false ) {
            throw new \Exception(trans('delete_resource_fail'));
        }

        return true;
    }

    public function cleanup()
    {
        @unlink($this->realPath);

        if ( $this->header->exists() ) {
            try {
                unset($this->chunkIndex);
            } catch ( \Exception $e ) {
                // 清理失败不应掩盖原始异常
            }
        }
    }

    public function rename($completeName)
    {
        $completePath = $this->getCompletePath($completeName);

        if ( file_exists($completePath) ) {

            $this->delete();
        }else{

            if ( rename($this->realPath, $completePath) === false ) {
                throw new \Exception(trans('rename_resource_fail'));
            }
        }
    }

    public function filterBySize($resourceSize)
    {
        $maxSize = (int)$this->maxSize;

        if ( (int)$resourceSize === 0 || ((int)$resourceSize > $maxSize && $maxSize !== 0) ) {
            throw new \Exception(trans('invalid_resource_size'));
        }

    }

    public function filterByExtension($resourceExt)
    {
        if ( empty($resourceExt) || (empty($this->allowedExtensions) === false && in_array($resourceExt, $this->allowedExtensions, true) === false) || in_array($resourceExt, $this->forbiddenExtensions, true) === true ) {
            throw new \Exception(trans('invalid_resource_type'));
        }
    }

    public function checkSize()
    {
        $this->filterBySize(filesize($this->realPath));
    }

    public function checkMimeType()
    {
        $extension = MimeType::search(mime_content_type($this->realPath));

        if($extension === null){
            throw new \Exception(trans('missing_mimetype'));
        }

        $this->filterByExtension($extension);
    }

    public function exists()
    {
        return file_exists($this->realPath);
    }

    public function createGroupSubDir()
    {
        $groupDir = dirname($groupSubDir = $this->getGroupSubDirPath());

        if ( file_exists($groupDir) === false ) {
            return false;
        }

        if ( file_exists($groupSubDir) === false ) {
            if ( mkdir($groupSubDir, 0755) === false ) {
                return false;
            }
        }

        return true;
    }

    public function calculateHash()
    {
        return md5_file($this->realPath);
    }

    public function getPath()
    {
        return ConfigMapper::get('root_dir') . DIRECTORY_SEPARATOR . $this->groupDir . DIRECTORY_SEPARATOR . $this->groupSubDir . DIRECTORY_SEPARATOR . $this->tempName . '.part';
    }

    public function getRealPath()
    {
        return base_path(). DIRECTORY_SEPARATOR .$this->path;
    }

    public function getCompletePath($name)
    {
        return $this->resourcePath($name);
    }

    public function getGroupSubDirPath()
    {
        return $this->resourcePath();
    }

    private function resourcePath($name = null)
    {
        $relative = ConfigMapper::get('root_dir') . DIRECTORY_SEPARATOR . $this->groupDir . DIRECTORY_SEPARATOR . $this->groupSubDir;

        if ( $name !== null ) {
            $relative .= DIRECTORY_SEPARATOR . $name;
        }

        return base_path() . DIRECTORY_SEPARATOR . $relative;
    }

    public function __set($property, $value)
    {
        if ( $property === 'chunkIndex' ) {
            $this->header->write($value);
        }
    }

    public function __get($property)
    {
        if ( $property === 'chunkIndex' ) {
            return $this->header->read();
        }

        return null;
    }

    public function __unset($property)
    {
        if ( $property === 'chunkIndex' ) {
            $this->header->delete();
        }
    }



}