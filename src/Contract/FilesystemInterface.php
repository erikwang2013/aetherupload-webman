<?php

namespace AetherUpload\Contract;

interface FilesystemInterface
{
    /**
     * 递归复制目录。
     *
     * 硬性语义：**不覆盖目标已存在的文件**（与 webman 的 copy_dir 默认行为一致）。
     * Install 的幂等性、以及「重装不覆盖使用者改过的配置」这两条契约都依赖它。
     * 测试侧的无条件覆盖替身已随本次抽取移除，测试与生产语义现已一致。
     */
    public function copyDir(string $source, string $dest): void;

    /** 递归删除目录（不存在时静默返回） */
    public function removeDir(string $path): void;
}
