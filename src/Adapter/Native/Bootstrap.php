<?php

namespace AetherUpload\Adapter\Native;

use AetherUpload\ResourceController;
use AetherUpload\Runtime;
use AetherUpload\UploadController;
use Throwable;

/**
 * 原生 PHP 的引导与入口：**一行接入**，连路由都不用自己写。
 *
 *     // public/index.php —— FPM / Apache / nginx+php-fpm
 *     require __DIR__ . '/../vendor/autoload.php';
 *     exit(\AetherUpload\Adapter\Native\Bootstrap::handle([
 *         'base_path' => dirname(__DIR__),
 *         'config'    => require __DIR__ . '/../config/aetherupload.php',   // 可选
 *         'redis'     => static fn () => new \Redis(),                       // 秒传需要，可选
 *     ]));
 *
 *     // php -S 的内置服务器：php -S 127.0.0.1:8080 -t public public/index.php
 *     // （同一个文件即可：静态文件存在就让内置服务器自己发，见下）
 *
 * handle() 做四件事：绑定适配器（Runtime::bind）→ 发一个新上下文身份 → 按配置里的路径路由四条业务路由
 * → 把内核产物真正发出去（唯一一次 header()/echo）。
 *
 * **静态文件**：内置服务器下若要让 `php -S` 自己发 `public/` 里的文件（例如发布出来的前端 js），
 * 在调用 handle() 之前加一句即可：
 *
 *     if (is_file(__DIR__ . parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH))) { return false; }
 *
 * 路由与中间件都取自配置（route_preprocess / route_uploading / route_display / route_download 与
 * 对应的 middleware_*）。中间件在这里是一个**无参可调用对象**：返回响应对象即短路（权限不足直接
 * 回 403 就是这种用法），返回别的一律忽略并继续。
 *
 * @see NativeAdapter 配置与选项清单
 */
class Bootstrap
{
    /**
     * 绑定适配器，返回它（控制台入口只需这一行 + 一个 Application）。
     *
     * @param array<string,mixed> $config
     * @param array<string,mixed> $options
     */
    public static function bind(array $config = [], string $basePath = '', array $options = []): NativeAdapter
    {
        $adapter = new NativeAdapter($config, $basePath, $options);

        Runtime::bind($adapter);

        return $adapter;
    }

    /**
     * 处理当前 SAPI 请求并发出去，返回状态码（`exit(Bootstrap::handle())` 即可）。
     *
     * @param array<string,mixed> $options 见 NativeAdapter 类注释，外加 config（配置数组）与 base_path
     */
    public static function handle(array $options = []): int
    {
        $adapter = self::bind(
            isset($options['config']) ? (array)$options['config'] : [],
            isset($options['base_path']) ? (string)$options['base_path'] : '',
            $options
        );

        $adapter->beginRequest();

        try {
            $response = self::dispatch($adapter);
        } catch ( Throwable $e ) {
            // 内核把「已知错误」都转成了响应，能落到这里的是真异常（类型错误、IO 失败…）。
            // 与其它宿主一致：对外只给 500，细节进 error_log，绝不把堆栈写进响应体
            error_log('AetherUpload: 处理请求时抛出未捕获异常：' . get_class($e) . ' ' . $e->getMessage());

            $response = Runtime::response()->text('Internal Server Error', 500);
        } finally {
            $adapter->endRequest();
        }

        return $response->send();
    }

    /**
     * 按配置里的四条路由分发当前请求。未命中返回 404，路径命中但方法不对返回 405
     * （与其余宿主的行为一致 —— 它们由各自的路由器给出同样的两种结果）。
     */
    private static function dispatch(NativeAdapter $adapter): object
    {
        $config = $adapter->config();
        $method = strtoupper((string)(isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET'));
        $path = self::requestPath();

        if ( $path === (string)$config->get('route_preprocess') ) {
            return self::run('POST', $method, $config->get('middleware_preprocess'), static function () {
                return (new UploadController())->preprocess();
            });
        }

        if ( $path === (string)$config->get('route_uploading') ) {
            return self::run('POST', $method, $config->get('middleware_uploading'), static function () {
                return (new UploadController())->saveChunk();
            });
        }

        // 展示：{route_display}/{uri}，uri 是**一段**（savedPath 用下划线编码三段，本身不含 /）
        $prefix = rtrim((string)$config->get('route_display'), '/') . '/';

        if ( strpos($path, $prefix) === 0 ) {
            $uri = substr($path, strlen($prefix));

            if ( $uri !== '' && strpos($uri, '/') === false ) {
                return self::run('GET', $method, $config->get('middleware_display'), static function () use ($uri) {
                    return (new ResourceController())->display($uri);
                });
            }
        }

        // 下载：{route_download}/{uri}/{newName}
        $prefix = rtrim((string)$config->get('route_download'), '/') . '/';

        if ( strpos($path, $prefix) === 0 ) {
            $parts = explode('/', (string)substr($path, strlen($prefix)));

            if ( count($parts) === 2 && $parts[0] !== '' && $parts[1] !== '' ) {
                return self::run('GET', $method, $config->get('middleware_download'), static function () use ($parts) {
                    return (new ResourceController())->download($parts[0], $parts[1]);
                });
            }
        }

        return Runtime::response()->text('Not Found', 404);
    }

    /**
     * 方法校验 + 中间件 + 调用控制器。
     *
     * 中间件是无参可调用对象：**返回响应对象即短路**（原样返回它，不再进控制器），
     * 返回 null/true 之类一律继续。不可调用的项（误配）静默跳过 —— 与其它宿主把中间件交给
     * 各自路由器处理时的宽容度一致。
     *
     * @param mixed    $middleware
     * @param callable $call
     */
    private static function run(string $expectedMethod, string $actualMethod, $middleware, callable $call): object
    {
        if ( $expectedMethod !== $actualMethod ) {
            return Runtime::response()->text('Method Not Allowed', 405)->withHeader('Allow', $expectedMethod);
        }

        foreach ( (array)$middleware as $one ) {
            if ( ! is_callable($one) ) {
                continue;
            }

            $result = $one();

            if ( is_object($result) ) {
                return $result;
            }
        }

        return $call();
    }

    /**
     * 请求路径（去掉查询串）。不做 URL 解码：路由配置与 savedPath 都是 ASCII 安全字符集，
     * 解码反而会让 `%2F` 这类输入绕过「uri 只有一段」的判断。
     */
    private static function requestPath(): string
    {
        $uri = isset($_SERVER['REQUEST_URI']) ? (string)$_SERVER['REQUEST_URI'] : '/';
        $path = parse_url($uri, PHP_URL_PATH);

        return is_string($path) && $path !== '' ? $path : '/';
    }
}
