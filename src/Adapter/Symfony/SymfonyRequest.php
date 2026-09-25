<?php

namespace AetherUpload\Adapter\Symfony;

use AetherUpload\Contract\RequestInterface;
use AetherUpload\Contract\UploadedFileInterface;
use AetherUpload\Kernel\UploadedFile as UploadedFilePort;
use Symfony\Component\HttpFoundation\File\UploadedFile as SymfonyUploadedFile;
use Symfony\Component\HttpFoundation\RequestStack;

class SymfonyRequest implements RequestInterface
{
    /** @var RequestStack|null */
    private $stack;

    public function __construct(?RequestStack $stack)
    {
        $this->stack = $stack;
    }

    /**
     * Request::get() 取的是 attributes → query → request 三段里的原值，不做任何过滤/转换。
     * 内核的类型守卫靠的正是「拿到原样的数组/整数/字符串」。
     *
     * @param mixed $default
     * @return mixed
     */
    public function input(string $key, $default = null)
    {
        $request = $this->current();

        return $request === null ? $default : $request->get($key, $default);
    }

    public function file(string $key): ?UploadedFileInterface
    {
        $request = $this->current();

        if ( $request === null ) {
            return null;
        }

        $file = $request->files->get($key);

        // 字段名写成 resource_chunk[] 时 files->get() 返回数组，视为「没有文件」
        return $file instanceof SymfonyUploadedFile ? new UploadedFilePort($file) : null;
    }

    public function all(): array
    {
        $request = $this->current();

        if ( $request === null ) {
            return [];
        }

        return $request->query->all() + $request->request->all();
    }

    /** CLI（命令、warmup）下没有当前请求，各方法退化为默认值 */
    private function current(): ?object
    {
        return $this->stack === null ? null : $this->stack->getCurrentRequest();
    }
}
