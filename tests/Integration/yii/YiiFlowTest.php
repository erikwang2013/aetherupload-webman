<?php

namespace AetherUpload\Tests\Integration\Yii;

use AetherUpload\Adapter\Yii\AetherUploadController;
use AetherUpload\Tests\Integration\FlowAssertions;
use PHPUnit\Framework\TestCase;
use yii\console\ExitCode;
use yii\web\HttpException;

/**
 * Yii2 真实端到端：真 yiisoft/yii2 2.0.55（真 urlManager 路由 → 真控制器 → 真响应对象）+ 真文件系统
 * + 真 Redis。
 *
 * 这一层才是「Yii 可用」的证据；仓库 tests/ 下的用例用的是替身，只覆盖内核逻辑。
 * 装配与工作目录见同目录 ci.sh / bootstrap.php / App.php，断言清单见 FlowAssertions。
 */
class YiiFlowTest extends TestCase
{
    use FlowAssertions;

    public static function setUpBeforeClass(): void
    {
        // 事件探针（Yii 的事件表是静态的，注册一次即可，与每请求新建的应用无关）
        App::registerProbes(Harness::probeLog());

        // 真跑控制台命令：aetherupload/publish 发布语言文件与前端资源。
        // 这一步不是可有可无的 —— PhpMessageSource 只认应用里的目录（@app/messages），不认 vendor，
        // 不发布就没有 en/messages.php，第 5 条的英文断言必然红。
        self::assertSame(ExitCode::OK, App::runConsole('aetherupload/publish'), 'aetherupload/publish 必须成功');
        self::assertFileExists(
            Harness::appRoot() . '/messages/aetherupload/en/messages.php',
            'aetherupload/publish 必须把语言文件发到 YiiTranslator 查找的位置'
        );

        // 建上传根目录、_header 与各分组目录（宿主装完之后要做的那一步）
        self::assertSame(ExitCode::OK, App::runConsole('aetherupload/groups'), 'aetherupload/groups 必须成功');
    }

    public static function tearDownAfterClass(): void
    {
        App::offProbes();
    }

    protected function setUp(): void
    {
        parent::setUp();

        // 每个用例都从干净的秒传索引开始：命中秒传会让 preprocess 直接回 savedPath，
        // 跳过 .part 建立与事件派发，上一轮留下的记录会把第 1、7 条打成假红。
        Harness::flushRedis();
    }

    // ------------------------------------------------------- FlowAssertions 钩子

    protected function appBasePath(): string
    {
        return Harness::appRoot();
    }

    protected function probeLogPath(): ?string
    {
        return Harness::probeLog();
    }

    protected function supportsInstantCompletion(): bool
    {
        return Harness::redisAvailable();
    }

    protected function instantCompletionSkipReason(): string
    {
        return Harness::redisSkipReason();
    }

    // ------------------------------------------------------------------ 发请求

    /**
     * 进程内发一个真实请求：填好 $_SERVER/$_POST/$_FILES 与 $_GET，新建应用并 handleRequest()。
     *
     * 返回**原始** status/body/header 行，因为 FlowAssertions 要按字节比对响应体。
     */
    protected function send(string $method, string $path, array $post = [], array $files = [], array $headers = []): array
    {
        $tempFiles = [];
        $uploads = [];

        foreach ( $files as $field => $file ) {
            $temp = tempnam(sys_get_temp_dir(), 'aetherupload-e2e-');
            file_put_contents($temp, $file['content']);
            $tempFiles[] = $temp;

            // 真 HTTP 的 multipart 解析结果就长这样；Yii 的 UploadedFile::getInstanceByName() 直接读 $_FILES
            $uploads[$field] = [
                'name'     => $file['name'],
                'type'     => 'application/octet-stream',
                'tmp_name' => $temp,
                'error'    => UPLOAD_ERR_OK,
                'size'     => strlen($file['content']),
            ];
        }

        // 真 HTTP 里 multipart/表单字段全是字符串（'42' 而不是 42）。进程内直接塞数组会把 int
        // 带进内核，与其余框架的端到端（真 HTTP）语义不一致，这里对齐成宿主真实拿到的形态。
        array_walk_recursive($post, static function (&$value): void {
            if ( is_scalar($value) ) {
                $value = (string)$value;
            }
        });

        $this->fillSuperGlobals($method, $path, $post, $uploads, $headers);

        $app = App::web();

        try {
            try {
                $response = $app->handleRequest($app->getRequest());
            } catch ( HttpException $e ) {
                // 真应用里这一步是全局异常处理器干的（ErrorHandler::handleException 按异常的状态码渲染响应）；
                // 进程内没有它，异常会直接冒到 PHPUnit。只接 HttpException 的状态码，其它异常照旧冒泡。
                $response = $app->getResponse();
                $response->setStatusCode($e->statusCode);
            }

            // RecordingResponse::send() 只记录：真发 HTTP 头/正文会污染 PHPUnit 输出，
            // 也可能因 headers already sent 直接抛异常。
            $response->send();
        } finally {
            foreach ( $tempFiles as $temp ) {
                @unlink($temp);
            }
        }

        if ( ! is_array($response->recorded) ) {
            $this->fail('响应未被记录：response 组件不是 RecordingResponse？');
        }

        return $response->recorded;
    }

    /**
     * @param array $post    已转成字符串的请求体
     * @param array $uploads $_FILES 形态的上传数组
     */
    private function fillSuperGlobals(string $method, string $path, array $post, array $uploads, array $headers): void
    {
        $query = [];

        if ( ($pos = strpos($path, '?')) !== false ) {
            parse_str(substr($path, $pos + 1), $query);
            $path = substr($path, 0, $pos);
        }

        $_GET = $query;
        $_POST = $post;
        $_FILES = $uploads;

        // Yii 从这些 server 变量推导 @webroot/@web/baseUrl 与 pathInfo，缺一个就是
        // 「Unable to determine the entry script file path」级别的错误
        $_SERVER['SCRIPT_FILENAME'] = Harness::appRoot() . '/web/index.php';
        $_SERVER['SCRIPT_NAME'] = '/index.php';
        $_SERVER['PHP_SELF'] = '/index.php';
        $_SERVER['REQUEST_METHOD'] = $method;
        $_SERVER['REQUEST_URI'] = $path . ($query === [] ? '' : '?' . http_build_query($query));
        $_SERVER['QUERY_STRING'] = http_build_query($query);
        $_SERVER['HTTP_HOST'] = 'localhost';
        $_SERVER['SERVER_NAME'] = 'localhost';
        $_SERVER['SERVER_PORT'] = '80';
        $_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';

        // 上一个请求可能留下的：都清掉，避免跨用例互相影响（例如 [8] 留下的 Range 头）
        unset(
            $_SERVER['HTTPS'],
            $_SERVER['CONTENT_TYPE'],
            $_SERVER['CONTENT_LENGTH'],
            $_SERVER['HTTP_RANGE'],
            $_SERVER['HTTP_X_FORWARDED_PROTO']
        );

        foreach ( $headers as $name => $value ) {
            if ( is_int($name) ) {
                continue;
            }

            $key = strtoupper(str_replace('-', '_', (string)$name));
            $_SERVER[$key === 'CONTENT_TYPE' || $key === 'CONTENT_LENGTH' ? $key : 'HTTP_' . $key] = (string)$value;
        }
    }

    // ------------------------------------------------- 本框架特有的补充断言

    /**
     * 四条业务路由注册到了正确的控制器方法（适配器的交付项之一，FlowAssertions 不覆盖）。
     *
     * 路由的 pattern 用的是 Yii 的 <uri> 语法（不是 webman 的 {uri}），控制器是适配器的薄壳
     * （Yii 只认 yii\base\Controller 子类，内核控制器是纯类）。
     */
    public function testBusinessRoutesAreRegistered(): void
    {
        $app = App::web();
        $registered = [];
        $verbs = [];

        // 用 name 而不是 pattern 做键：UrlRule::preparePattern() 会把 pattern 就地编译成正则
        // （'aetherupload/preprocess' → '#^aetherupload/preprocess$#u'），name 才是规则的可读身份
        // （UrlRule::init()：未显式设置时取 pattern 的原值）。
        foreach ( $app->getUrlManager()->rules as $rule ) {
            $registered[$rule->name] = $rule->route;
            $verbs[$rule->name] = $rule->verb;
        }

        $this->assertSame('aetherupload/preprocess', $registered['aetherupload/preprocess'] ?? null, 'preprocess 路由');
        $this->assertSame(['POST'], $verbs['aetherupload/preprocess'] ?? null, 'preprocess 只接受 POST');

        $this->assertSame('aetherupload/uploading', $registered['aetherupload/uploading'] ?? null, 'uploading 路由');
        $this->assertSame(['POST'], $verbs['aetherupload/uploading'] ?? null, 'uploading 只接受 POST');

        $this->assertSame('aetherupload/display', $registered['aetherupload/display/<uri>'] ?? null, 'display 路由（<uri> 语法）');
        $this->assertSame('aetherupload/download', $registered['aetherupload/download/<uri>/<newName>'] ?? null, 'download 路由（<uri> 语法）');

        // Yii 靠 controllerMap 把路由定向到适配器的薄壳
        $this->assertSame(AetherUploadController::class, $app->controllerMap['aetherupload'] ?? null, '控制器映射');
    }

    /** GET 打 POST 路由必须是 404，而不是落到别的规则上（verb 约束真的生效） */
    public function testMethodConstraintIsEnforced(): void
    {
        $this->assertSame(404, $this->send('GET', $this->path('preprocess'))['status'], 'GET 打 preprocess 必须 404');
    }

    /**
     * 四个控制台命令都能经 controllerMap 跑到内核 Runner（交付项 3）。
     * publish/groups 在 setUpBeforeClass 里已经跑过（它们是后续断言的前置条件），这里补上另外两个。
     */
    public function testConsoleCommandsRun(): void
    {
        $this->assertSame(ExitCode::OK, App::runConsole('aetherupload/build'), 'aetherupload/build');
        $this->assertSame(ExitCode::OK, App::runConsole('aetherupload/clean'), 'aetherupload/clean');
    }

    /**
     * 宿主 params 缺键时必须由包内默认值兜住。
     *
     * aetherupload/publish 生成的样例只列常用键（root_dir/chunk_size/groups…），明写「未列出的键一律
     * 沿用默认值」，而 route_* 正好不在样例里 —— 不合并默认值的话这里注册出来的 pattern 是空串，
     * 真 HTTP 打过来四条路由全 404。这是「真跑起来」和「进程内测试」之间唯一的缺口。
     */
    public function testPackageDefaultsFillMissingParams(): void
    {
        $app = App::web([]); // 宿主只写了 'params' => ['aetherupload' => []]，一个键都没配
        $names = [];

        foreach ( $app->getUrlManager()->rules as $rule ) {
            $names[] = $rule->name;
        }

        $this->assertSame([
            'aetherupload/preprocess',
            'aetherupload/uploading',
            'aetherupload/display/<uri>',
            'aetherupload/download/<uri>/<newName>',
        ], $names, 'params 为空时也要按包内默认值注册出 4 条路由');
    }
}
