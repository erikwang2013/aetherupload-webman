<?php

namespace AetherUpload\Adapter\Native;

use AetherUpload\Contract\EventDispatcherInterface;
use Throwable;

/**
 * 事件端口：注册表 + 逐个 try/catch。
 *
 * 内核的硬性语义是「监听器抛异常不影响上传响应」（webman 的实现会吞掉），所以这里：
 *  - 每个监听器单独包 try/catch —— 一个监听器抛异常，后面的监听器照样执行；
 *  - 异常交给 logger 后**绝不冒泡**，上传响应该是什么还是什么。
 *
 * 原生 PHP 没有事件组件，因此没有「外部派发器」这一项（Slim 侧要桥接 PSR-14，这里不需要）。
 * 注册方式：构造时传 `listeners`（形如 ['aetherupload.upload_complete' => callable|callable[]]），
 * 或拿到本对象后 listen()。异常默认 error_log()，可用 `logger` 选项换成 PSR-3 对象或自己的可调用对象。
 */
class NativeEvents implements EventDispatcherInterface
{
    /** @var array<string,array<int,callable>> 事件名 => 监听器 */
    private $listeners = [];

    /** @var callable|object|null function (string $message, Throwable $e): void 或带 error() 的对象 */
    private $logger;

    /**
     * @param array<string,callable|array<int,callable>> $listeners
     * @param object|callable|null                       $logger
     */
    public function __construct(array $listeners = [], $logger = null)
    {
        foreach ( $listeners as $name => $registered ) {
            foreach ( is_array($registered) ? $registered : [$registered] as $listener ) {
                if ( is_callable($listener) ) {
                    $this->listen((string)$name, $listener);
                }
            }
        }

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
        $event = new NativeEvent($name, $payload);

        foreach ( isset($this->listeners[$name]) ? $this->listeners[$name] : [] as $listener ) {
            try {
                $listener($event);
            } catch ( Throwable $e ) {
                $this->log($name, $e);
            }
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
