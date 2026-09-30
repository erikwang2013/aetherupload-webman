<?php

namespace AetherUpload\Storage;

/**
 * 存储驱动的统一接口。分块暂存与临时文件不经这里 —— 只有「成品」的落地与读取。
 */
interface StorageInterface
{
    /**
     * 把本地成品文件落到存储。目标已存在（同 hash 去重）时删本地、不重复写。
     * 失败抛 \Exception（local: rename_resource_fail/delete_resource_fail；s3: rename_resource_fail 包裹底层错误）。
     */
    public function publish(string $localPath, string $groupDir, string $groupSubDir, string $name): void;

    /** 目标是否存在（s3: HEAD 200/404；其余状态抛异常） */
    public function exists(string $groupDir, string $groupSubDir, string $name): bool;

    /** 删除目标（s3 幂等：缺失也算成功）；失败抛异常 */
    public function delete(string $groupDir, string $groupSubDir, string $name): bool;

    /** 可直发的 URL（s3: 预签名 GET，$responseParams 合并为 response-content-* 查询参数）；local 恒 null */
    public function url(string $groupDir, string $groupSubDir, string $name, array $responseParams = []): ?string;
}
