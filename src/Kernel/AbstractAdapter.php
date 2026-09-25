<?php

namespace AetherUpload\Kernel;

use AetherUpload\Contract\AdapterInterface;
use AetherUpload\Contract\ContextStoreInterface;
use AetherUpload\Contract\EventDispatcherInterface;
use AetherUpload\Contract\FilesystemInterface;
use AetherUpload\Contract\RedisInterface;

/**
 * 适配器基类：给出与框架无关的默认实现，具体适配器只需覆盖自己那几项。
 *
 * 九个端口实例全部惰性创建并缓存 —— 同一个适配器实例在整个进程内必须返回同一批端口对象，
 * 否则端口内部的状态（例如翻译器已登记过哪些语言文件）会互相看不见。
 */
abstract class AbstractAdapter implements AdapterInterface
{
    /** @var RedisInterface|null */
    protected $redis;

    /** @var EventDispatcherInterface|null */
    protected $events;

    /** @var FilesystemInterface|null */
    protected $filesystem;

    /** @var ContextStoreInterface|null */
    protected $contextStore;

    public function redis(): RedisInterface
    {
        return $this->redis ?: ($this->redis = new NullRedis());
    }

    public function events(): EventDispatcherInterface
    {
        return $this->events ?: ($this->events = new NullEventDispatcher());
    }

    public function filesystem(): FilesystemInterface
    {
        return $this->filesystem ?: ($this->filesystem = new Filesystem());
    }

    public function contextStore(): ContextStoreInterface
    {
        return $this->contextStore ?: ($this->contextStore = new ArrayContextStore());
    }

    /**
     * CLI 与「每请求一个进程」的宿主不需要区分上下文。
     *
     * @return mixed
     */
    public function contextToken()
    {
        return null;
    }
}
