<?php

namespace AetherUpload;

use AetherUpload\Storage\Storage;

class Resource
{
    public $name;
    public $group;
    public $groupDir;
    public $groupSubDir;
    public $path;
    public $realPath;

    public function __construct($group, $groupDir, $groupSubDir, $name)
    {
        $this->name = $name;
        $this->group = $group;
        $this->groupDir = $groupDir;
        $this->groupSubDir = $groupSubDir;
        $this->path = $this->getPath();
        $this->realPath = $this->getRealPath();
    }

    public function getPath()
    {
        return ConfigMapper::get('root_dir') . DIRECTORY_SEPARATOR . $this->groupDir . DIRECTORY_SEPARATOR . $this->groupSubDir . DIRECTORY_SEPARATOR . $this->name;
    }

    public function getRealPath()
    {
        return Runtime::basePath(). DIRECTORY_SEPARATOR .$this->path;
    }

    public function exists()
    {
        return Storage::driver()->exists($this->groupDir, $this->groupSubDir, $this->name);
    }

    public function delete()
    {
        Storage::driver()->delete($this->groupDir, $this->groupSubDir, $this->name);

        return true;
    }



}