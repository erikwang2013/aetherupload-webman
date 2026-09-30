<?php

namespace AetherUpload\Storage;

use AetherUpload\ConfigMapper;
use AetherUpload\Runtime;

/**
 * 默认驱动：行为与历史版本逐字节相同（等价于旧 PartialResource::rename + Resource::exists/delete）。
 */
final class LocalStorage implements StorageInterface
{
    public function publish(string $localPath, string $groupDir, string $groupSubDir, string $name): void
    {
        $dest = $this->fullPath($groupDir, $groupSubDir, $name);

        if ( file_exists($dest) ) {
            // 与旧 rename() 的去重语义一致：目标已存在（同 hash）即丢弃本次临时文件；失败报旧错误键
            if ( unlink($localPath) === false ) {
                throw new \Exception(Runtime::trans('delete_resource_fail'));
            }

            return;
        }

        if ( rename($localPath, $dest) === false ) {
            throw new \Exception(Runtime::trans('rename_resource_fail'));
        }
    }

    public function exists(string $groupDir, string $groupSubDir, string $name): bool
    {
        return file_exists($this->fullPath($groupDir, $groupSubDir, $name));
    }

    public function delete(string $groupDir, string $groupSubDir, string $name): bool
    {
        if ( unlink($this->fullPath($groupDir, $groupSubDir, $name)) === false ) {
            throw new \Exception(Runtime::trans('delete_resource_fail'));
        }

        return true;
    }

    public function url(string $groupDir, string $groupSubDir, string $name, array $responseParams = []): ?string
    {
        return null;
    }

    private function fullPath(string $groupDir, string $groupSubDir, string $name): string
    {
        // 与 Resource/PartialResource 的 realPath 同源（root_dir 走 ConfigMapper，保持既有测试语义）
        return Runtime::basePath() . DIRECTORY_SEPARATOR . ConfigMapper::get('root_dir')
            . DIRECTORY_SEPARATOR . $groupDir . DIRECTORY_SEPARATOR . $groupSubDir . DIRECTORY_SEPARATOR . $name;
    }
}
