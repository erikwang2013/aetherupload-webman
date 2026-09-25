<?php

namespace AetherUpload\Adapter\Symfony;

use AetherUpload\Contract\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface as SymfonyEventDispatcherInterface;
use Throwable;

class SymfonyEvents implements EventDispatcherInterface
{
    /** @var SymfonyEventDispatcherInterface|null */
    private $dispatcher;

    /** @var LoggerInterface|null */
    private $logger;

    public function __construct(?SymfonyEventDispatcherInterface $dispatcher, ?LoggerInterface $logger = null)
    {
        $this->dispatcher = $dispatcher;
        $this->logger = $logger;
    }

    /**
     * @param mixed $payload
     */
    public function emit(string $name, $payload): void
    {
        if ( $this->dispatcher === null ) {
            return; // 宿主没有事件系统：等价于没注册监听器
        }

        $event = new AetherUploadEvent($payload);

        // 逐个取监听器调用，而不是 dispatch()：Symfony 的 dispatch 在监听器抛异常时立刻中断，
        // 排在后面的监听器就再也轮不到（webman 的 Event::emit 是逐个 try/catch 后继续）。
        // 契约与端到端断言第 7 条都要求：监听器异常不影响上传响应，且后续监听器仍被调用。
        if ( ! method_exists($this->dispatcher, 'getListeners') ) {
            try {
                $this->dispatcher->dispatch($event, $name);
            } catch ( Throwable $e ) {
                $this->report($name, $e);
            }

            return;
        }

        foreach ( $this->dispatcher->getListeners($name) as $listener ) {
            try {
                // 三个实参与 Symfony 自己的派发约定一致（debug 下监听器会被 WrappedListener 包一层，
                // 它要求完整的三个参数；只声明一个参数的监听器，多余实参会由 PHP 忽略）
                $listener($event, $name, $this->dispatcher);
            } catch ( Throwable $e ) {
                $this->report($name, $e);
            }
        }
    }

    /**
     * 文件已经落盘、响应已经成型，监听器出错不允许把一次成功的上传变成 500。
     */
    private function report(string $name, Throwable $e): void
    {
        if ( $this->logger !== null ) {
            $this->logger->error('AetherUpload: 事件「' . $name . '」的监听器抛出异常，已忽略。', ['exception' => $e]);
        }
    }
}
