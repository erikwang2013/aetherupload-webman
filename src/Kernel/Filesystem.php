<?php

namespace AetherUpload\Kernel;

use AetherUpload\Contract\FilesystemInterface;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * 不依赖任何框架的目录复制/删除实现（替代 webman 的 copy_dir / remove_dir 全局函数）。
 */
class Filesystem implements FilesystemInterface
{
    /**
     * 递归复制；目标已存在的文件**不覆盖**。
     */
    public function copyDir(string $source, string $dest): void
    {
        if ( ! is_dir($dest) && ! @mkdir($dest, 0755, true) && ! is_dir($dest) ) {
            return;
        }

        if ( ! is_dir($source) ) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ( $iterator as $item ) {
            $target = $dest . DIRECTORY_SEPARATOR . $iterator->getSubPathName();

            if ( $item->isDir() ) {
                if ( ! is_dir($target) ) {
                    @mkdir($target, 0755, true);
                }
                continue;
            }

            if ( file_exists($target) ) {
                continue; // 不覆盖：重装不应抹掉使用者改过的配置
            }

            copy($item->getPathname(), $target);
        }
    }

    public function removeDir(string $path): void
    {
        if ( is_link($path) || is_file($path) ) {
            @unlink($path);
            return;
        }

        if ( ! is_dir($path) ) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ( $iterator as $item ) {
            $item->isDir() && ! $item->isLink() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }

        @rmdir($path);
    }
}
