<?php

namespace AetherUpload\Tests\Integration\Yii;

use AetherUpload\Adapter\Yii\Bootstrap;
use AetherUpload\PartialResource;
use AetherUpload\Resource;
use RuntimeException;
use yii\base\Event;
use yii\console\Application as ConsoleApplication;
use yii\redis\Connection;
use yii\web\Application as WebApplication;

/**
 * 宿主应用工厂：每个请求一个真 yii\web\Application。
 *
 * 与 Yii 的真实生命周期一致（一请求一应用实例），顺带也验证了 Runtime 的执行上下文
 * 确实会随应用实例切换 —— 上下文的身份值就是 Yii::$app（见 YiiAdapter::contextToken()）。
 */
final class App
{
    /**
     * 事件探针：抛异常的那个必须不影响上传响应，记录的那个必须仍被调用。
     *
     * Yii 的事件表是**静态**的（Event::$events），与「每请求新建应用」无关，因此注册一次即可。
     * 前者挂 before_upload_complete、后者挂 upload_complete，天然是「先抛后记」：
     * 异常只要冒泡，上传响应就会被 500 打断，第 7 条第一句断言直接红。
     */
    public static function registerProbes(string $log): void
    {
        Event::on(PartialResource::class, 'aetherupload.before_upload_complete', static function (): void {
            throw new RuntimeException('E2E probe: this listener exception must not bubble');
        });

        Event::on(Resource::class, 'aetherupload.upload_complete', static function (Event $event) use ($log): void {
            $sender = $event->sender;

            file_put_contents(
                $log,
                'aetherupload.upload_complete sender=' . (is_object($sender) ? get_class($sender) : gettype($sender)) . "\n",
                FILE_APPEND
            );
        });
    }

    public static function offProbes(): void
    {
        Event::offAll();
    }

    /**
     * 插件配置：整份读默认配置再覆写，而不是只塞几个键 ——
     * 这样测的就是 config/aetherupload.php 的真内容（改默认值不会被测试静默放过）。
     */
    public static function pluginParams(bool $instantCompletion): array
    {
        $config = require Harness::repoRoot() . '/config/aetherupload.php';

        $config['instant_completion'] = $instantCompletion;                  // 第 8 条（需 redis）
        $config['groups']['file']['event_before_upload_complete'] = true;     // 第 7 条
        $config['groups']['file']['event_upload_complete'] = true;

        return $config;
    }

    /**
     * 宿主配置里真正需要写的那一行就是 'bootstrap' => [Bootstrap::class]（绑 Runtime + 注册路由/命令）。
     *
     * $params 传 null 用整份默认配置（正常用例）；传数组则当作宿主手写的 params
     * （用来验证 aetherupload/publish 生成的样例配置那种「只列常用键」的形态）。
     */
    public static function web(?array $params = null): WebApplication
    {
        return new WebApplication([
            'id'          => 'aetherupload-yii-e2e',
            'basePath'    => Harness::appRoot(),
            'params'      => ['aetherupload' => $params ?? self::pluginParams(Harness::redisAvailable())],
            'bootstrap'   => ['aetherupload' => Bootstrap::class],
            'components'  => [
                'response'   => ['class' => RecordingResponse::class],
                // 前置条件：路径参数（<uri>）只有在 prettyUrl 下才解析得出来。
                // enableStrictParsing 也是必需的：否则规则全不匹配时（例如 GET 打 POST 路由）Yii 会退化成
                // pathInfo 直解析，把 'aetherupload/preprocess' 当路由名送到同一个控制器 —— verb 约束形同虚设。
                'urlManager' => ['enablePrettyUrl' => true, 'showScriptName' => false, 'enableStrictParsing' => true],
                'redis'      => ['class' => Connection::class] + Harness::redisConfig(),
            ],
        ]);
    }

    public static function console(): ConsoleApplication
    {
        return new ConsoleApplication([
            'id'       => 'aetherupload-yii-e2e-console',
            'basePath' => Harness::appRoot(),
            'params'   => ['aetherupload' => self::pluginParams(Harness::redisAvailable())],
            'bootstrap' => ['aetherupload' => Bootstrap::class],
            // aetherupload/build 要读写秒传索引；宿主的控制台应用同样得配 redis 组件
            'components' => ['redis' => ['class' => Connection::class] + Harness::redisConfig()],
        ]);
    }

    /**
     * 跑一个控制台动作，返回退出码。
     *
     * 不走 Application::run()：那会解析 $_SERVER['argv'] 并把响应内容 echo 出来；
     * runAction 是 handleRequest 内部真正干活的那一步，退出码语义一致（动作返回 int → exitStatus）。
     *
     * 输出没法拦：yii\console\Controller::stdout() 最终是 fwrite(STDOUT)，ob_start 抓不到，
     * 因此它直接落在 phpunit 的终端输出里 —— 正好当作「命令真的执行了」的证据。
     */
    public static function runConsole(string $route): int
    {
        return (int)self::console()->runAction($route);
    }
}
