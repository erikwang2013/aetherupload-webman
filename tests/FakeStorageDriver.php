<?php

namespace AetherUpload\Tests;

use AetherUpload\Storage\StorageInterface;

/**
 * StorageInterface 的替身：记录每次调用的方法与参数，行为由公开属性驱动。
 * 供 Storage 门面注入（Storage::inject）后测 Resource / PartialResource / ResourceController 的分支。
 *
 * 只做「记录 + 可配置返回值」，不模拟任何真实存储语义——真实语义由 LocalStorageTest / S3StorageTest 覆盖。
 */
class FakeStorageDriver implements StorageInterface
{
    /** @var array<int,array{0:string,1:array}> [方法名, 参数列表]，按调用顺序 */
    public array $calls = [];

    public bool $existsResult = true;

    public ?string $urlResult = null;

    public bool $deleteThrows = false;

    public function publish(string $localPath, string $groupDir, string $groupSubDir, string $name): void
    {
        $this->calls[] = ['publish', [$localPath, $groupDir, $groupSubDir, $name]];
    }

    public function exists(string $groupDir, string $groupSubDir, string $name): bool
    {
        $this->calls[] = ['exists', [$groupDir, $groupSubDir, $name]];

        return $this->existsResult;
    }

    public function delete(string $groupDir, string $groupSubDir, string $name): bool
    {
        $this->calls[] = ['delete', [$groupDir, $groupSubDir, $name]];

        if ( $this->deleteThrows ) {
            throw new \Exception('delete_resource_fail');
        }

        return true;
    }

    public function url(string $groupDir, string $groupSubDir, string $name, array $responseParams = []): ?string
    {
        $this->calls[] = ['url', [$groupDir, $groupSubDir, $name, $responseParams]];

        return $this->urlResult;
    }

    /** @return array<int,string> */
    public function calledMethods(): array
    {
        return array_map(static function (array $call): string {
            return $call[0];
        }, $this->calls);
    }

    public function callCount(string $method): int
    {
        return count(array_filter($this->calls, static function (array $call) use ($method): bool {
            return $call[0] === $method;
        }));
    }

    /**
     * 指定方法最后一次调用的参数（该方法从未被调用时返回 null）。
     *
     * @return array|null
     */
    public function lastArgs(string $method): ?array
    {
        $args = null;

        foreach ( $this->calls as $call ) {
            if ( $call[0] === $method ) {
                $args = $call[1];
            }
        }

        return $args;
    }
}
