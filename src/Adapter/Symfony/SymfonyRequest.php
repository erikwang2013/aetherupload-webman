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

        if ( $request === null ) {
            return $default;
        }

        // 忠实移植 Symfony ≤7.4 的 Request::get()（8.0 已移除）：attributes → query → request，
        // 存在即返回原值（不做任何过滤/转换），三段都没有才给 $default。
        // 不能用对象哨兵 + bag->get($key, $sentinel)：8.x 的 query/request 是 InputBag，其 get()
        // 第二参必须是标量（InputBag.php:40 抛 InvalidArgumentException），且值是数组时还会抛
        // BadRequestException——而 ≤7.4 的 Request::get() 对数组值原样返回。
        // has()/all() 未被 InputBag 覆写（ParameterBag 实现，array_key_exists 语义、无类型约束），
        // 与原实现的每次取值逐字等价。
        if ( $request->attributes->has($key) ) {
            return $request->attributes->all()[$key];
        }

        if ( $request->query->has($key) ) {
            return $request->query->all()[$key];
        }

        if ( $request->request->has($key) ) {
            return $request->request->all()[$key];
        }

        return $default;
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
