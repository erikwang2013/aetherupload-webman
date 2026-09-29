<?php

namespace AetherUpload\Adapter\Yii3;

use AetherUpload\ResourceController;
use AetherUpload\Runtime;
use AetherUpload\UploadController;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use RuntimeException;
use Yiisoft\Router\CurrentRoute;

/**
 * 承接四条业务路由的动作壳：每个动作一行，转交给框架无关的内核控制器。
 *
 * 为什么需要这层壳：Yii3 的 action 由 yiisoft/middleware-dispatcher 的 MiddlewareFactory 调起，
 * **返回值必须是 PSR-7 `ResponseInterface`**，而内核控制器返回的是可变 shim（见 Response 的类注释）。
 * 转换就在这里做，而且只在这里做一次。
 *
 * 构造注入（容器装配，宿主不需要写任何定义）：
 *   ResponseFactoryInterface  宿主的 PSR-17 响应工厂（标准应用模板在 config/web/di/psr17.php 绑好）
 *   CurrentRoute              yiisoft/router 的服务，路由匹配后持有路径参数（display 的 <uri> 等）
 *   StreamFactoryInterface    可选：有它文件响应就走零拷贝，没有就分块拷进响应体
 *
 * 请求数据一律由内核经 Runtime::request() 取 —— 本类只把**当前 PSR-7 请求**交给适配器登记
 * （Yii3 没有 request() 全局函数），传完就在 finally 里清理，这样「post() 不过滤」的契约
 * 与其余适配器是同一份实现。
 */
class AetherUploadController
{
    /** @var ResponseFactoryInterface */
    private $responseFactory;

    /** @var CurrentRoute */
    private $currentRoute;

    /** @var StreamFactoryInterface|null */
    private $streamFactory;

    public function __construct(
        ResponseFactoryInterface $responseFactory,
        CurrentRoute $currentRoute,
        ?StreamFactoryInterface $streamFactory = null
    ) {
        $this->responseFactory = $responseFactory;
        $this->currentRoute = $currentRoute;
        $this->streamFactory = $streamFactory;
    }

    public function preprocess(ServerRequestInterface $request): ResponseInterface
    {
        return $this->handle($request, static function () {
            return (new UploadController())->preprocess();
        });
    }

    /** 路由名是 aetherupload/uploading（见配置 route_uploading），落到内核的 saveChunk */
    public function uploading(ServerRequestInterface $request): ResponseInterface
    {
        return $this->handle($request, static function () {
            return (new UploadController())->saveChunk();
        });
    }

    public function display(ServerRequestInterface $request): ResponseInterface
    {
        return $this->handle($request, function () {
            return (new ResourceController())->display($this->argument('uri'));
        });
    }

    public function download(ServerRequestInterface $request): ResponseInterface
    {
        return $this->handle($request, function () {
            $newName = $this->currentRoute->getArgument('newName');

            return (new ResourceController())->download(
                $this->argument('uri'),
                $newName === null ? null : (string)$newName
            );
        });
    }

    /**
     * 每次请求：登记当前请求 → 调内核 → 落成真 PSR-7 → 清理临时副本。
     *
     * @param callable $call function (): object 内核控制器调用
     */
    private function handle(ServerRequestInterface $request, callable $call): ResponseInterface
    {
        $adapter = Runtime::adapter();

        if ( ! $adapter instanceof Yii3Adapter ) {
            throw new RuntimeException(
                'AetherUpload: 当前绑定的不是 Yii3 适配器（' . get_class($adapter) . '）。'
                . '请在 public/index.php（以及控制台入口）里调用 Adapter\Yii3\Bootstrap::bind()。'
            );
        }

        $adapter->beginRequest($request);

        try {
            return $this->convert($call());
        } finally {
            $adapter->endRequest();
        }
    }

    /**
     * 内核产物 → 真 PSR-7（**全案唯一**的转换点，见 Response 的类注释）。
     *
     * @param mixed $result
     */
    private function convert($result): ResponseInterface
    {
        if ( $result instanceof Response ) {
            return $result->toPsr7($this->responseFactory, $this->streamFactory);
        }

        // 宿主自己接的中间件/自定义控制器已经给出真 PSR-7 时原样放行
        if ( $result instanceof ResponseInterface ) {
            return $result;
        }

        throw new RuntimeException(
            'AetherUpload: Yii3 的 action 只能返回 PSR-7 响应或本包的响应 shim，实际拿到 '
            . (is_object($result) ? get_class($result) : gettype($result))
        );
    }

    /**
     * 取路由路径参数。路由 pattern 里写了 {uri}，匹配成功时它必然存在；
     * 真为空说明有人把本动作挂到了没有该参数的 pattern 上，这里按空串交给内核的路径校验去拒绝。
     */
    private function argument(string $name): string
    {
        $value = $this->currentRoute->getArgument($name);

        return $value === null ? '' : (string)$value;
    }
}
