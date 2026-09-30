<?php

namespace AetherUpload\Storage;

use AetherUpload\Runtime;

/**
 * S3 兼容驱动。对象键 = {prefix}{group_dir}/{subdir}/{name}（与本地布局同构，root_dir 不泄漏进桶）。
 */
final class S3Storage implements StorageInterface
{
    /** 预签名 URL 有效期：下载是「302 后立即取」，300s 足够；不作为配置项 */
    const PRESIGN_TTL = 300;

    /** @var S3Client */
    private $client;

    public function __construct(array $config, ?callable $transport = null)
    {
        $this->client = new S3Client($config, $transport);
    }

    public function publish(string $localPath, string $groupDir, string $groupSubDir, string $name): void
    {
        $key = $this->client->key($groupDir, $groupSubDir, $name);

        try {
            if ( ! $this->client->head($key) ) {
                $contentType = @mime_content_type($localPath);
                $this->client->uploadMultipartOrPut(
                    $key,
                    $localPath,
                    is_string($contentType) && $contentType !== '' ? $contentType : 'application/octet-stream'
                );
            }
        } catch ( \Exception $e ) {
            // 对外统一为上传链路的既有错误键；底层原因挂在 previous 上供排查（消息不含凭据）
            throw new \Exception(Runtime::trans('rename_resource_fail'), 0, $e);
        }

        if ( @unlink($localPath) === false ) {
            throw new \Exception(Runtime::trans('delete_resource_fail'));
        }
    }

    public function exists(string $groupDir, string $groupSubDir, string $name): bool
    {
        return $this->client->head($this->client->key($groupDir, $groupSubDir, $name));
    }

    public function delete(string $groupDir, string $groupSubDir, string $name): bool
    {
        $this->client->delete($this->client->key($groupDir, $groupSubDir, $name));

        return true;
    }

    public function url(string $groupDir, string $groupSubDir, string $name, array $responseParams = []): ?string
    {
        return $this->client->presign($this->client->key($groupDir, $groupSubDir, $name), self::PRESIGN_TTL, $responseParams);
    }
}
