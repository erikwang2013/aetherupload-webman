<?php

namespace AetherUpload\Adapter\Slim;

use AetherUpload\Contract\EventDispatcherInterface;
use Throwable;

/**
 * 事件端口。webman 的语义是「监听器抛异常不影响上传响应」，而 PSR-14 派发器（以及多数 Slim 应用
 * 用的事件库）默认**冒泡**，所以：
 *
 *  - 本地监听器逐个包在 try/catch 里 —— 一个监听器抛异常，后面的监听器照样执行；
 *  - 外部 PSR-14 派发器（或自定义可调用对象）整体包在 try/catch 里 —— 绝不冒泡到上传响应。
 *
 * 注册方式：构造时传 `listeners`（形如 ['aetherupload.upload_complete' => callable|callable[]]），
 * 或拿到本对象后 listen()。异常交给 logger：默认 error_log()，可由 `logger` 选项换成 PSR-3 或自己的可调用对象。
 */
class SlimEvents implements EventDispatcherInterface
{
    /** @var array<string,array<int,callable>> 事件名 => 监听器 */
    private $listeners = [];

    /** @var object|callable|null PSR-14 派发器，或 function (Event $event): void */
    private $dispatcher;

    /** @var callable|object|null function (string $message, Throwable $e): void 或带 error() 的对象 */
    private $logger;

    /**
     * @param array<string,callable|array<int,callable>> $listeners
     * @param object|callable|null                       $dispatcher
     * @param object|callable|null                       $logger
     */
    public function __construct(array $listeners = [], $dispatcher = null, $logger = null)
    {
        foreach ( $listeners as $name => $registered ) {
            foreach ( is_array($registered) ? $registered : [$registered] as $listener ) {
                if ( is_callable($listener) ) {
                    $this->listen((string)$name, $listener);
                }
            }
        }

        $this->dispatcher = $dispatcher;
        $this->logger = $logger;
    }

    public function listen(string $name, callable $listener): void
    {
        $this->listeners[$name][] = $listener;
    }

    /**
     * @param mixed $payload
     */
    public function emit(string $name, $payload): void
    {
        $event = new Event($name, $payload);

        foreach ( isset($this->listeners[$name]) ? $this->listeners[$name] : [] as $listener ) {
            try {
                $listener($event);
            } catch ( Throwable $e ) {
                $this->log($name, $e);
            }
        }

        if ( $this->dispatcher === null ) {
            return;
        }

        try {
            if ( is_callable($this->dispatcher) ) {
                ($this->dispatcher)($event);
            } else {
                $this->dispatcher->dispatch($event);
            }
        } catch ( Throwable $e ) {
            $this->log($name, $e);
        }
    }

    private function log(string $name, Throwable $e): void
    {
        $message = 'AetherUpload: 事件 ' . $name . ' 的监听器抛出异常（已按契约吞掉）：'
            . get_class($e) . ' ' . $e->getMessage();

        if ( is_callable($this->logger) ) {
            ($this->logger)($message, $e);

            return;
        }

        if ( is_object($this->logger) && method_exists($this->logger, 'error') ) {
            // PSR-3 形态（不 require psr/log，避免为一个可选日志器加硬依赖）
            $this->logger->error($message, ['exception' => $e]);

            return;
        }

        error_log($message);
    }
}
