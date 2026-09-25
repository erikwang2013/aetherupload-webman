<?php

namespace AetherUpload\Adapter\Yii;

use AetherUpload\Contract\ConfigInterface;
use AetherUpload\Contract\EventDispatcherInterface;
use AetherUpload\Contract\PathsInterface;
use AetherUpload\Contract\RedisInterface;
use AetherUpload\Contract\RequestInterface;
use AetherUpload\Contract\ResponseFactoryInterface;
use AetherUpload\Contract\TranslatorInterface;
use AetherUpload\Kernel\AbstractAdapter;
use AetherUpload\Kernel\PrefixedConfig;
use Yii;

/**
 * Yii2 适配器。
 *
 * 绑定位置是应用配置的 bootstrap 数组（见 Bootstrap 的类注释）：
 * Yii 没有「每请求跑一遍的配置文件」，bootstrap 是唯一保证「应用就绪后、任何业务代码前执行一次」
 * 的入口，而 ConfigMapper::get() 从路由规则那一行起就要求 Runtime 已经绑好。
 */
class YiiAdapter extends AbstractAdapter
{
    /** @var ConfigInterface|null */
    private $config;

    /** @var TranslatorInterface|null */
    private $translator;

    /** @var RequestInterface|null */
    private $request;

    /** @var ResponseFactoryInterface|null */
    private $response;

    /** @var PathsInterface|null */
    private $paths;

    public function name(): string
    {
        return 'yii';
    }

    /**
     * 配置来源是 params['aetherupload']（Yii 没有 config() 这种全局配置函数）。
     *
     * 前缀传空串，由下面的回调把「包内默认值 + params」合并后的数组当作根；键里可能带点号
     * （内核会取 groups.file.group_dir 这种嵌套键），因此要按点号逐层下钻，
     * 而不是把整个点号字符串当成一个数组下标。
     *
     * 必须垫一层包内默认值：aetherupload/publish 生成的样例只列常用键，并明确写着「未列出的键
     * 一律沿用默认值」——而 route_preprocess 这类逻辑键恰好不在样例里，不合并的话路由 pattern
     * 就是空串，四条路由一条都注册不出来。合并粒度与 Slim 适配器一致（顶层键整体替换）。
     */
    public function config(): ConfigInterface
    {
        if ( $this->config === null ) {
            $this->config = new PrefixedConfig(
                static function ($key, $default = null) {
                    $app = Yii::$app;

                    $params = ($app === null || ! isset($app->params['aetherupload']) || ! is_array($app->params['aetherupload']))
                        ? []
                        : $app->params['aetherupload'];

                    $value = array_merge(self::defaults(), $params);

                    foreach ( explode('.', (string)$key) as $segment ) {
                        if ( ! is_array($value) || ! array_key_exists($segment, $value) ) {
                            return $default;
                        }

                        $value = $value[$segment];
                    }

                    return $value;
                }
            );
        }

        return $this->config;
    }

    /**
     * 参考默认值 = 包内的 config/aetherupload.php（与 webman 版逐键一致，由 tests/ConfigParityTest.php 守着）。
     * 文件缺失（异常安装形态）时给出空数组，交给内核自己的缺失兜底，不在读取期抛异常。
     * 进程内缓存：配置读取每请求要跑几十次，require 每次都会重新执行这个文件。
     *
     * @return array<string,mixed>
     */
    private static function defaults(): array
    {
        static $defaults;

        if ( $defaults === null ) {
            $file = dirname(__DIR__, 3) . '/config/aetherupload.php';
            $config = is_file($file) ? require $file : [];

            $defaults = is_array($config) ? $config : [];
        }

        return $defaults;
    }

    public function translator(): TranslatorInterface
    {
        return $this->translator ?: ($this->translator = new YiiTranslator());
    }

    public function request(): RequestInterface
    {
        return $this->request ?: ($this->request = new YiiRequest());
    }

    public function response(): ResponseFactoryInterface
    {
        return $this->response ?: ($this->response = new YiiResponseFactory());
    }

    public function paths(): PathsInterface
    {
        return $this->paths ?: ($this->paths = new YiiPaths());
    }

    public function redis(): RedisInterface
    {
        return $this->redis ?: ($this->redis = new YiiRedis());
    }

    public function events(): EventDispatcherInterface
    {
        return $this->events ?: ($this->events = new YiiEvents());
    }

    /**
     * 应用实例即执行上下文身份：每个请求一个 yii\web\Application（常驻型 Yii 运行器亦然），
     * 因此上下文切换时 Runtime::context() 会换一份干净的 RequestContext，
     * 上一段的配置快照与已登记的语言文件不会留给下一段。
     *
     * 控制台命令里 Yii::$app 是 console\Application：进程内只有一个实例，退化为单上下文，
     * 与 CLI 的语义一致。
     *
     * @return mixed
     */
    public function contextToken()
    {
        return Yii::$app;
    }
}
