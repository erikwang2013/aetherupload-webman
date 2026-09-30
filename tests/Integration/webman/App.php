<?php

namespace AetherUpload\Tests\Integration\Webman;

use RuntimeException;

/**
 * webman 端到端测试的应用准备器：真装一个 webman 骨架 + 真起 HTTP 服务 + 用 curl 打真请求。
 *
 * 幂等：已就绪的应用会直接复用（重复跑测试不重复 composer install）。
 * 可用环境变量：
 *   AETHERA_E2E_APP    应用目录（默认 sys_get_temp_dir()/aetherupload-webman-e2e）
 *   AETHERA_E2E_PORT   监听端口（默认 8787）
 *   AETHERA_E2E_REDIS  '0' 强制视为无 redis（第 8 条跳过）；默认自动探测
 *   AETHERA_E2E_REDIS_HOST / _PORT / _DB   redis 连接参数（默认 127.0.0.1:6379，db 编号见 redisDb()）
 *   AETHERA_E2E_KEEP   '1' 测试结束后不停止服务（排查用）
 */
final class App
{
    private function __construct()
    {
    }

    public static function root(): string
    {
        $dir = getenv('AETHERA_E2E_APP');

        return $dir ? rtrim($dir, '/') : sys_get_temp_dir() . '/aetherupload-webman-e2e';
    }

    /** 本仓库根目录（path 仓库指向它，装进临时骨架的是当前工作区，不是发布版） */
    public static function repoRoot(): string
    {
        return dirname(__DIR__, 3);
    }

    public static function port(): int
    {
        // 刻意避开 webman 默认的 8787：同机上常有别的 webman 在跑（本仓库的其他 E2E 脚本也会用它）
        return (int)(getenv('AETHERA_E2E_PORT') ?: 18787);
    }

    public static function baseUrl(): string
    {
        return 'http://127.0.0.1:' . self::port();
    }

    public static function probeLog(): string
    {
        return self::root() . '/runtime/aetherupload-probe.log';
    }

    public static function redisHost(): string
    {
        return getenv('AETHERA_E2E_REDIS_HOST') ?: '127.0.0.1';
    }

    public static function redisPort(): int
    {
        return (int)(getenv('AETHERA_E2E_REDIS_PORT') ?: 6379);
    }

    /**
     * 秒传用的 redis 库。**每个框架一个专属库，默认值必须显式写死**，不要靠「碰巧和邻居一样」：
     * 各 harness 都会清 `aetherupload:*` 键，共用同一个库时并行跑测会互相删掉对方正在用的记录
     * （第 8 条秒传因此偶发失败）。已分配：webman 7 · laravel 4 · thinkphp 5 · hyperf 3 · slim 6
     * · symfony 8 · yii 9。
     */
    public static function redisDb(): int
    {
        return (int)(getenv('AETHERA_E2E_REDIS_DB') ?: 7);
    }

    /** redis 是否可用（第 8 条秒传的前提）；AETHERA_E2E_REDIS=0 可强制关掉 */
    public static function redisAvailable(): bool
    {
        if ( getenv('AETHERA_E2E_REDIS') === '0' ) {
            return false;
        }

        $socket = @fsockopen(self::redisHost(), self::redisPort(), $errno, $errstr, 2);

        if ( $socket === false ) {
            return false;
        }

        fwrite($socket, "PING\r\n");
        $reply = (string)fgets($socket);
        fclose($socket);

        return strpos($reply, 'PONG') !== false;
    }

    /**
     * 清掉本 E2E 可能踩到的秒传记录（aetherupload:*），让每条用例都从「查无记录」开始。
     *
     * 测试内容本身已经每条用例独占（见 FlowAssertions::gifBytes()），这里再上一道确定性保险：
     * 秒传短路发生在事件派发之前，任何残留记录都会让第 7 条探针假红、让第 1 条看不到 .part。
     * 按前缀精确删而不是 FLUSHDB —— 同机 laravel/thinkphp/hyperf 的 harness 默认也用 db 7，
     * FLUSHDB 会把别人的数据一起清掉。走裸 socket 发 RESP，免得测试进程还要装 redis 扩展
     * （骨架里的 webman/redis 只装在应用侧）。没 redis 时直接返回：第 8 条会 markTestSkipped。
     */
    public static function flushInstantCompletionKeys(): void
    {
        // 尊重 AETHERA_E2E_REDIS=0：该模式下本套件不碰 redis，也就不该动别人的库
        if ( ! self::redisAvailable() ) {
            return;
        }

        $socket = @fsockopen(self::redisHost(), self::redisPort(), $errno, $errstr, 2);

        if ( $socket === false ) {
            return;
        }

        stream_set_timeout($socket, 2);

        // 用 RESP 数组发命令，免得跟 redis 的行内命令引号规则打交道；回复都是一行（+OK / :N）
        $command = static function (array $args): string {
            $out = '*' . count($args) . "\r\n";

            foreach ( $args as $arg ) {
                $out .= '$' . strlen($arg) . "\r\n" . $arg . "\r\n";
            }

            return $out;
        };

        // 单行 Lua：先 KEYS 再 DEL，回复是 :N 一行，省掉 RESP 数组解析；#k 判空避免 unpack({}) 报错
        $lua = "local k=redis.call('keys',ARGV[1]) if #k>0 then return redis.call('del',unpack(k)) end return 0";

        fwrite($socket, $command(['SELECT', (string)self::redisDb()]));
        fgets($socket);
        fwrite($socket, $command(['EVAL', $lua, '0', 'aetherupload:*']));
        fgets($socket);
        fclose($socket);
    }

    // ------------------------------------------------------------------ S3 端到端

    /** S3 服务端默认端口（与 webman 18787、redis db 7 一样是约定值） */
    const S3_PORT = 19000;

    /** multipart 阈值：43B 的 GIF 走单次 PUT，填充过的 GIF（>100B）强制走 multipart —— 两条路都要真跑 */
    const S3_MULTIPART_THRESHOLD = 100;

    /** docker 起服务端时用的容器名（CI 的 workflow 也用同一个名字） */
    const S3_CONTAINER = 'aetherupload-e2e-minio';

    /** 是否 S3 模式：phpunit-s3.xml 的 <env> 显式声明；app-setup.php 子进程据此写 storage 配置 */
    public static function s3Mode(): bool
    {
        return getenv('AETHERA_E2E_S3') === '1';
    }

    /** 本次运行的模式：配置在 worker 启动时载入内存，模式不同必须重启服务（见 start()） */
    public static function mode(): string
    {
        return self::s3Mode() ? 's3' : 'local';
    }

    public static function s3Port(): int
    {
        return (int)(getenv('AETHERA_E2E_S3_PORT') ?: self::S3_PORT);
    }

    public static function s3Endpoint(): string
    {
        return rtrim(getenv('AETHERA_E2E_S3_ENDPOINT') ?: 'http://127.0.0.1:' . self::s3Port(), '/');
    }

    public static function s3Region(): string
    {
        return getenv('AETHERA_E2E_S3_REGION') ?: 'us-east-1';
    }

    public static function s3Key(): string
    {
        return getenv('AETHERA_E2E_S3_KEY') ?: 'minioadmin';
    }

    public static function s3Secret(): string
    {
        return getenv('AETHERA_E2E_S3_SECRET') ?: 'minioadmin';
    }

    public static function s3Bucket(): string
    {
        return getenv('AETHERA_E2E_S3_BUCKET') ?: 'aetherupload-e2e';
    }

    /** 缓存目录：mc/服务端二进制、数据目录、日志、pid 都在这里（AETHERA_E2E_S3_CACHE 可覆盖） */
    public static function s3CacheDir(): string
    {
        return rtrim(getenv('AETHERA_E2E_S3_CACHE') ?: sys_get_temp_dir() . '/aetherupload-e2e-cache', '/');
    }

    /** app-setup.php 每次显式写入的 storage 配置块 */
    public static function storageConfig(): array
    {
        if ( ! self::s3Mode() ) {
            // 必须显式写回 local：上一次 S3 运行会在骨架里留下 driver=s3
            return ['driver' => 'local'];
        }

        return [
            'driver' => 's3',
            's3'     => [
                'endpoint'            => self::s3Endpoint(),
                'region'              => self::s3Region(),
                'bucket'              => self::s3Bucket(),
                'access_key'          => self::s3Key(),
                'secret_key'          => self::s3Secret(),
                'path_style'          => true,
                'prefix'              => '',
                'multipart_threshold' => self::S3_MULTIPART_THRESHOLD,
            ],
        ];
    }

    /** 服务端是否在跑（根路径能应答即算活：MinIO 对匿名请求回 403，同样是活） */
    public static function s3ServerAvailable(): bool
    {
        return self::probe(self::s3Endpoint() . '/') !== 0;
    }

    /** 没有服务端时给测试写跳过原因用：说清找过哪儿 */
    public static function s3ResolutionHint(): string
    {
        return '找过 env AETHERA_E2E_S3_ENDPOINT、缓存二进制 ' . self::s3CacheDir() . '/{minio,weed}、docker 镜像 minio/minio，都没有';
    }

    /**
     * 确保 S3 服务端可用（幂等）：
     *   1. 已探活 → 直接用（别人起的、上次留下的都不动）
     *   2. 缓存二进制 minio / weed → 后台起一个（pid 记进 pidfile，收尾只停自己起的）
     *   3. docker 镜像 minio/minio → 起容器
     *   4. 都没有 → 安静返回，用例自己 markTestSkipped（如实跳过，绝不假绿）
     * 二进制在但起不来 → 抛异常：那是环境坏了，要响亮地失败，不是安静地跳过。
     */
    public static function ensureS3Server(): void
    {
        if ( ! self::s3ServerAvailable() ) {
            if ( ! self::startLocalS3Server() ) {
                return;
            }

            self::awaitS3Server();
        }

        self::ensureS3Bucket();
    }

    /** 建桶（幂等；mc 独立于我们自己的客户端） */
    public static function ensureS3Bucket(): void
    {
        // -p 就是 --ignore-existing 的短名，两个一起写 mc 会报 "two forms of the same flag"
        self::mcExec(['mb', '--ignore-existing', 'e2e/' . self::s3Bucket()]);
    }

    /**
     * 只停本次起的服务端（AETHERA_E2E_KEEP=1 保留供排查）。
     *
     * 缓存目录 /tmp/aetherupload-e2e-cache 是共享的：单看「pidfile 在」就收，会把并发跑同一套件的
     * 另一方正在用的服务端带走（它跑出连接被拒的假红）。所以 kill 之前先校验 server.json 里记的
     * 启动者身份：只有「启动者就是本进程」才动手，别人活着的服务端一律不碰。
     * 启动者已死（孤儿）默认也**不碰**：可能还有别家运行正在用（它只是复用了，没写记录）。
     * AETHERA_E2E_S3_RECLAIM_ORPHAN=1 才回收孤儿 —— 真崩过的运行会留下一台没人收的服务端，
     * 下一次运行探活到就直接复用（不影响测试），要清掉时开这个开关跑一次即可。
     * ponytail: 两个运行同时抢着起（都还没探活到）时后写的一方覆盖 pidfile，先写的那台可能变孤儿
     * 且无人记录；并发起服务端本身是少见路径，真频繁了再加文件锁。
     */
    public static function stopS3Server(): void
    {
        if ( getenv('AETHERA_E2E_KEEP') === '1' || ! is_file(self::s3PidFile()) ) {
            return;
        }

        $meta = json_decode((string)file_get_contents(self::s3PidFile()), true);

        if ( ! is_array($meta) ) {
            @unlink(self::s3PidFile());

            return;
        }

        $owner = (int)($meta['owner'] ?? 0);

        if ( $owner !== getmypid() ) {
            // 启动者还活着 → 是别人正在用的服务端，不碰
            if ( $owner > 0 && self::processAlive($owner) ) {
                return;
            }

            // 孤儿（含早期版本没记 owner 的 pidfile）：默认也不碰，要收得显式开开关
            if ( getenv('AETHERA_E2E_S3_RECLAIM_ORPHAN') !== '1' ) {
                return;
            }
        }

        @unlink(self::s3PidFile());

        if ( ($meta['kind'] ?? '') === 'docker' ) {
            self::run('docker rm -f ' . escapeshellarg((string)$meta['container']), self::s3CacheDir(), true);

            return;
        }

        $pid = (int)($meta['pid'] ?? 0);

        if ( $pid > 0 ) {
            // 先验明正身再杀：pid 可能已被复用，命令行里必须出现本 harness 的缓存目录
            $guard = 'ps -o args= -p ' . $pid . ' | grep -q ' . escapeshellarg(self::s3CacheDir()) . ' || exit 0';
            // 用 /bin/kill 而不是内建 kill：dash 的内建 kill 不认 `-TERM -- -PID`，
            // 会报 "Illegal number" 然后什么都不做（2>/dev/null 一盖就静默漏进程）
            $killer = is_executable('/bin/kill') ? '/bin/kill' : 'kill';
            // 负号 = 整个进程组（SeaweedFS 还会 fork 出 master/volume/filer，同组一起收）
            self::run($guard . '; ' . $killer . ' -TERM -- -' . $pid . ' 2>/dev/null; sleep 1; ' . $killer . ' -KILL -- -' . $pid . ' 2>/dev/null; exit 0', self::s3CacheDir(), true);
        }
    }

    /**
     * 跑 mc（MinIO 官方客户端：建桶与字节校验都走它，独立于我们自己的 S3 客户端，避免自证）。
     * 别名：e2e = 正确凭据；bad = 错误 secret（验签负例）。
     * 返回 ['code'=>int, 'stdout'=>string, 'stderr'=>string]；stdout/stderr 分开收，mc cat 的字节不能被 stderr 污染。
     */
    public static function mcRun(array $args): array
    {
        $env = [
            'MC_CONFIG_DIR' => self::s3CacheDir() . '/mc-config',
            'MC_HOST_e2e'   => self::mcHostUrl(self::s3Key(), self::s3Secret()),
            'MC_HOST_bad'   => self::mcHostUrl(self::s3Key(), self::s3Secret() . '-wrong'),
        ];

        $binary = self::mcBinary();

        if ( $binary !== null ) {
            return self::runCapture(array_merge([$binary], $args), $env);
        }

        if ( self::dockerImageExists('minio/mc') ) {
            // mc 镜像没有 shell（entrypoint 就是 mc），只能把参数直接递给它，不能 sh -c
            $command = ['docker', 'run', '--rm', '--network', 'host'];

            foreach ( $env as $name => $value ) {
                $command[] = '-e';
                $command[] = $name . '=' . $value;
            }

            $command[] = 'minio/mc';

            return self::runCapture(array_merge($command, $args), []);
        }

        throw new RuntimeException(
            '没有可用的 mc 客户端（' . self::s3CacheDir() . '/mc 与 docker 镜像 minio/mc 都没有），无法独立校验桶内对象'
        );
    }

    /** 跑 mc，非零退出即抛（带输出）；返回值是 stdout */
    public static function mcExec(array $args): string
    {
        $result = self::mcRun($args);

        if ( $result['code'] !== 0 ) {
            throw new RuntimeException(
                'mc ' . implode(' ', $args) . ' 失败（exit ' . $result['code'] . '）：' . trim($result['stderr'] . $result['stdout'])
            );
        }

        return $result['stdout'];
    }

    /** 桶内对象的原始字节（mc cat 独立读出；对象缺失/命令失败直接抛，不会把错误文本当成内容） */
    public static function s3ObjectBytes(string $key): string
    {
        return self::mcExec(['cat', 'e2e/' . self::s3Bucket() . '/' . $key]);
    }

    public static function s3ObjectMd5(string $key): string
    {
        return md5(self::s3ObjectBytes($key));
    }

    /**
     * multipart 真跑的服务端侧痕迹：对象元数据里的 X-Amz-Mp-Parts-Count，退而求其次看服务端日志。
     * 服务端不暴露时返回 ''（调用方如实说明，不静默放过）。
     */
    /**
     * 分片上传的**服务端**证据（不采信我们自己的客户端说了什么）。
     *
     * S3 语义：multipart 对象的 ETag = 各分片 MD5 拼接后再 MD5，加 "-" + 分片数；
     * 单次 PUT 的对象 ETag 是裸 MD5。所以「-N 后缀」本身就是服务端留下的分片痕迹。
     *
     * @return array{multipart: bool|null, detail: string} multipart 为 null 表示服务端没给出可判别的信息
     */
    public static function s3MultipartEvidence(string $key): array
    {
        $stat = self::mcRun(['stat', '--json', 'e2e/' . self::s3Bucket() . '/' . $key]);

        if ( $stat['code'] !== 0 ) {
            return ['multipart' => null, 'detail' => 'mc stat 失败：' . trim($stat['stderr'] . $stat['stdout'])];
        }

        if ( preg_match('/"?(X-Amz-Mp-Parts-Count|partsCount)"?\s*[:=]\s*"?(\d+)/i', $stat['stdout'], $m) ) {
            return ['multipart' => (int)$m[2] >= 1, 'detail' => '服务端元数据 X-Amz-Mp-Parts-Count=' . $m[2]];
        }

        if ( preg_match('/"etag"\s*:\s*"([^"]+)"/i', $stat['stdout'], $m) ) {
            $etag = trim($m[1], '"');

            if ( preg_match('/-(\d+)$/', $etag, $n) ) {
                return ['multipart' => true, 'detail' => '服务端 ETag=' . $etag . '（"-N" 后缀 = multipart 语义，分片数 ' . $n[1] . '）'];
            }

            if ( preg_match('/^[0-9a-f]{32}$/i', $etag) ) {
                return ['multipart' => false, 'detail' => '服务端 ETag=' . $etag . '（裸 MD5 = 单次 PUT 语义）'];
            }

            return ['multipart' => null, 'detail' => '服务端 ETag=' . $etag . '（既不是裸 MD5 也不是 -N 形态，判别不了）'];
        }

        if ( preg_match('/^.*(CreateMultipartUpload|UploadPart|CompleteMultipartUpload).*$/im', self::s3ServerLog(), $m) ) {
            return ['multipart' => true, 'detail' => '服务端日志：' . trim($m[0])];
        }

        return ['multipart' => null, 'detail' => '服务端既没给分片元数据，也没有可判别的 ETag'];
    }

    /** 取证都试过哪些来源（跳过/退化时写进说明） */
    public static function s3EvidenceSource(): string
    {
        return 'mc stat --json 的分片元数据/ETag（-N 后缀）+ 服务端日志（' . self::s3ServerLogPath() . ' 或 docker logs ' . self::S3_CONTAINER . '）';
    }

    /** 本次起的服务端日志；外部端点、别人起的服务端返回 ''（docker 起的走 docker logs） */
    public static function s3ServerLog(): string
    {
        $meta = self::s3ServerMeta();

        if ( $meta === null ) {
            return '';
        }

        if ( ($meta['kind'] ?? '') === 'docker' ) {
            return self::run('docker logs ' . escapeshellarg((string)($meta['container'] ?? self::S3_CONTAINER)) . ' 2>&1', self::s3CacheDir(), true);
        }

        $log = (string)($meta['log'] ?? '');

        return $log !== '' && is_file($log) ? (string)file_get_contents($log) : '';
    }

    // ------------------------------------------------------------------ 进程辅助

    /** 直连 URL 发一个请求（跟随 S3 预签名 302 用）；返回 ['status','body','headers','error'] */
    public static function httpFetch(string $url, bool $follow = false, string $method = 'GET', int $timeout = 30): array
    {
        $responseHeaders = [];
        $ch = curl_init($url);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_FOLLOWLOCATION => $follow,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$responseHeaders): int {
                $responseHeaders[] = rtrim($line, "\r\n");

                return strlen($line);
            },
        ]);

        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);

        return ['status' => $status, 'body' => (string)$body, 'headers' => $responseHeaders, 'error' => $error];
    }

    /** 幂等准备应用：骨架 → 依赖 → 本插件 → app-setup.php（装各插件 + 改写配置） */
    public static function ensure(): void
    {
        $root = self::root();

        if ( ! is_file($root . '/vendor/autoload.php') ) {
            self::mkdir(dirname($root));
            self::run(
                'composer create-project workerman/webman ' . escapeshellarg($root) . ' --no-interaction --prefer-dist --no-progress',
                dirname($root)
            );
        }

        self::requirePackages($root);

        if ( ! is_dir($root . '/vendor/erikwang2013/aetherupload-webman') ) {
            self::run('composer config repositories.aetherupload path ' . escapeshellarg(self::repoRoot()) . ' --no-interaction', $root);

            // 不关脚本：webman 的 post-package-install（support\Plugin::install）就是真实用户的安装路径，
            // 插件必须能在 composer 进程里自己完成安装（Install::install() 会在未绑定时自绑 webman 适配器，
            // 读不到宿主 groups 时回落插件自带配置）。E2E 要跑的正是这条路。
            //
            // -W（--with-all-dependencies）：全新 workerman/webman 骨架把 symfony/translation 锁在 v8，
            // 而本插件声明 ^5.4||^6.0||^7.0 —— 不带 -W 时 composer 会以「lock 里已固定」为由直接失败
            // （CI 靠骨架缓存躲过了这一条，缓存失效/本地新装都会撞上）。
            self::run('composer require erikwang2013/aetherupload-webman:@dev -W --no-interaction --no-progress', $root);
        }

        // 只跑一遍就够：Install::install() 在宿主 config() 读不到 groups/root_dir 时会回落到
        // 插件自带的 config/app.php，因此即便是全新应用（插件配置是本次才复制进去的），
        // storage/app/aetherupload/<group_dir> 也能建出来 —— PartialResource::createGroupSubDir()
        // 用的是非递归 mkdir，父目录必须已存在，否则之后每次 preprocess 都只回笼统的 upload_error。
        self::setup($root);
    }

    public static function start(): void
    {
        if ( self::isUp() && self::runningMode() === self::mode() ) {
            return;
        }

        // 服务在跑但不是本次模式起的：配置在 worker 启动时载入内存，换模式必须重启，
        // 否则会拿旧配置（local/s3）跑出新结论 —— 那是最难查的一类假结果
        if ( self::isUp() ) {
            self::stop();

            $deadline = microtime(true) + 15;

            while ( self::isUp() && microtime(true) < $deadline ) {
                usleep(200000);
            }

            if ( self::isUp() ) {
                throw new RuntimeException(
                    '端口 ' . self::port() . ' 上的 webman 不是本次模式（' . self::mode() . '）起的，且停止失败；'
                    . '请手动 php start.php stop（应用目录 ' . self::root() . '）或用 AETHERA_E2E_PORT 换端口'
                );
            }
        }

        // -d 让 master 进程后台常驻；注意它即使绑定端口失败也可能 exit 0（子进程里才报错），
        // 所以必须自己探活，并把 start 的输出一起带进错误信息
        $output = self::run('php start.php start -d', self::root(), true);

        $deadline = microtime(true) + 30;

        while ( microtime(true) < $deadline ) {
            if ( self::isUp() ) {
                file_put_contents(self::modeMarker(), self::mode());

                return;
            }
            usleep(200000);
        }

        throw new RuntimeException(
            'webman 未在 30s 内就绪，端口 ' . self::port() . "\n--- start.php 输出 ---\n" . $output
            . self::tail(self::root() . '/runtime/logs/stdout.log')
            . self::tail(self::root() . '/runtime/logs/webman.log')
        );
    }

    public static function stop(): void
    {
        if ( ! is_file(self::root() . '/start.php') ) {
            return;
        }

        // 服务不是本次进程起的（复用了上一次的）也要收干净，否则下一次 start 会因端口占用失败
        self::run('php start.php stop', self::root(), true);
    }

    public static function isUp(): bool
    {
        $socket = @fsockopen('127.0.0.1', self::port(), $errno, $errstr, 1);

        if ( $socket === false ) {
            return false;
        }

        fclose($socket);

        // 端口通还不够：worker 必须已经加载完路由（否则第一个请求会 404/超时）
        $response = self::http('GET', '/aetherupload-e2e-readiness-probe');

        return $response['status'] === 404;
    }

    /** 起一个最简 HTTP 请求（只给就绪探测与错误诊断用，业务请求走测试类里的 curl） */
    private static function http(string $method, string $path): array
    {
        $response = self::httpFetch(self::baseUrl() . $path, false, $method, 5);

        return ['status' => $response['status'], 'body' => $response['body']];
    }

    // ------------------------------------------------------------------ 准备

    /** 装 E2E 应用自己的依赖（不进主包 composer.json）；缺什么装什么 */
    private static function requirePackages(string $root): void
    {
        $installed = (string)@file_get_contents($root . '/composer.json');

        // webman/console：app/command 下的命令壳继承 Symfony Command，php webman 也要它
        // webman/redis：插件适配器走 support\Redis，秒传（第 8 条）必须有它
        foreach ( ['webman/console', 'webman/redis'] as $package ) {
            if ( strpos($installed, '"' . $package . '"') !== false ) {
                continue;
            }

            // 同样走真实安装路径（脚本里的 support\Plugin::install 负责把插件配置复制进 config/plugin/）
            self::run('composer require ' . $package . ' --no-interaction --no-progress', $root);
            self::setup($root);
        }
    }

    /** 在应用进程里执行 app-setup.php 改写配置（要用 webman 的全局函数，必须在应用里跑） */
    private static function setup(string $root): void
    {
        self::run('php ' . escapeshellarg(__DIR__ . '/app-setup.php'), $root);
    }

    // ------------------------------------------------------------------ 进程

    private static function run(string $command, string $cwd, bool $ignoreFailure = false): string
    {
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open($command, $descriptors, $pipes, $cwd, null);

        if ( ! is_resource($process) ) {
            throw new RuntimeException('无法执行：' . $command);
        }

        $stdout = (string)stream_get_contents($pipes[1]);
        $stderr = (string)stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);

        if ( $code !== 0 && ! $ignoreFailure ) {
            throw new RuntimeException(
                "命令失败（exit {$code}）：{$command}\n" . substr($stdout . $stderr, -4000)
            );
        }

        return $stdout . $stderr;
    }

    private static function mkdir(string $dir): void
    {
        if ( ! is_dir($dir) && ! mkdir($dir, 0755, true) && ! is_dir($dir) ) {
            throw new RuntimeException('无法创建目录：' . $dir);
        }
    }

    private static function tail(string $file, int $lines = 20): string
    {
        if ( ! is_file($file) ) {
            return '';
        }

        $content = (string)file_get_contents($file);
        $all = explode("\n", $content);

        return "\n--- " . $file . " ---\n" . implode("\n", array_slice($all, -$lines)) . "\n";
    }

    // ------------------------------------------------------------------ S3 私有

    /** 起一个本地服务端（缓存二进制优先，其次 docker 镜像）；都没有返回 false（调用方安静跳过） */
    private static function startLocalS3Server(): bool
    {
        $cache = self::s3CacheDir();

        if ( is_file($cache . '/minio') && is_executable($cache . '/minio') ) {
            $dataDir = $cache . '/minio-data';
            self::mkdir($dataDir);
            self::spawnS3Server(
                'minio',
                ['MINIO_ROOT_USER' => self::s3Key(), 'MINIO_ROOT_PASSWORD' => self::s3Secret()],
                [$cache . '/minio', 'server', '--address', '127.0.0.1:' . self::s3Port(), $dataDir]
            );

            return true;
        }

        if ( is_file($cache . '/weed') && is_executable($cache . '/weed') ) {
            $dataDir = $cache . '/weed-data';
            self::mkdir($dataDir);

            // 身份表只给一个带凭据的身份、不配 anonymous：服务端必须验签，正例才不是空证明
            file_put_contents($cache . '/s3.json', json_encode([
                'identities' => [[
                    'name'        => 'e2e',
                    'credentials' => [['accessKey' => self::s3Key(), 'secretKey' => self::s3Secret()]],
                    'actions'     => ['Admin', 'Read', 'Write', 'List', 'Tagging'],
                ]],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            self::spawnS3Server('weed', [], [
                $cache . '/weed', 'server', '-dir=' . $dataDir,
                '-s3', '-s3.port=' . self::s3Port(), '-s3.config=' . $cache . '/s3.json',
                '-master.port=19333', '-volume.port=18080', '-filer.port=18888',
            ]);

            return true;
        }

        if ( self::dockerImageExists('minio/minio') ) {
            $dataDir = $cache . '/minio-docker-data';
            self::mkdir($dataDir);
            self::run('docker rm -f ' . escapeshellarg(self::S3_CONTAINER) . ' >/dev/null 2>&1; docker run -d --name ' . escapeshellarg(self::S3_CONTAINER)
                . ' -p ' . self::s3Port() . ':9000 -v ' . escapeshellarg($dataDir) . ':/data'
                . ' -e MINIO_ROOT_USER=' . escapeshellarg(self::s3Key())
                . ' -e MINIO_ROOT_PASSWORD=' . escapeshellarg(self::s3Secret())
                . ' minio/minio server /data', $cache, true);
            file_put_contents(self::s3PidFile(), json_encode([
                'kind' => 'docker',
                'container' => self::S3_CONTAINER,
                'log' => '',
                'owner' => getmypid(),
                'started_at' => date('c'),
            ]));

            return true;
        }

        return false;
    }

    /**
     * 给服务端单独开一个进程组（setsid），收尾时整组收——SeaweedFS 会再 fork 出 master/volume/filer。
     *
     * env 赋值必须写在 setsid 之前：`setsid A=b cmd` 里 setsid 会把 "A=b" 当成要执行的程序名。
     * setsid 自己会 fork，所以 `$!` 拿到的不一定是服务端本体（往往是转瞬即逝的中间进程）——
     * 让服务端 exec 之前先把自己的 pid 写出来，收尾时按这个 pid 整组收。
     */
    private static function spawnS3Server(string $kind, array $env, array $argv): void
    {
        self::mkdir(self::s3CacheDir());

        // 上次跑崩留下的 pidfile 会让 stopS3Server() 去 kill 一个不相干的 pid
        @unlink(self::s3PidFile());

        $realPidFile = self::s3CacheDir() . '/server.pid';
        @unlink($realPidFile);

        $command = '';
        foreach ( $env as $name => $value ) {
            $command .= $name . '=' . escapeshellarg($value) . ' ';
        }
        $command .= 'setsid sh -c ' . escapeshellarg(
            'echo $$ > ' . escapeshellarg($realPidFile) . '; exec ' . implode(' ', array_map('escapeshellarg', $argv))
        );

        $log = self::s3ServerLogPath();
        $output = self::run($command . ' > ' . escapeshellarg($log) . ' 2>&1 < /dev/null & echo $!', self::s3CacheDir(), true);
        $launcher = (int)trim($output);

        if ( $launcher <= 0 ) {
            throw new RuntimeException('S3 服务端启动命令没有返回 pid：' . trim($output));
        }

        // sh 写自己的 pid 是异步的，轮询一小会儿；拿不到就退回 launcher（至少收尾时不会误杀）
        $pid = 0;

        for ( $i = 0; $i < 40 && $pid <= 0; $i++ ) {
            usleep(50000);
            $pid = (int)trim((string)@file_get_contents($realPidFile));
        }

        @unlink($realPidFile);

        // owner：起这个服务端的进程（phpunit 本进程）。缓存目录是共享的，收尾只能收自己的
        // 或启动者已死的孤儿 —— 否则并发跑同一套件时会把人家的服务端带走（对方跑出假红）
        file_put_contents(self::s3PidFile(), json_encode([
            'kind' => $kind,
            'pid' => $pid > 0 ? $pid : $launcher,
            'log' => $log,
            'owner' => getmypid(),
            'started_at' => date('c'),
        ]));
    }

    private static function awaitS3Server(int $timeout = 60): void
    {
        $deadline = microtime(true) + $timeout;

        while ( microtime(true) < $deadline ) {
            if ( self::s3ServerAvailable() ) {
                return;
            }

            usleep(300000);
        }

        throw new RuntimeException(
            'S3 服务端 ' . $timeout . 's 内未就绪（' . self::s3Endpoint() . '）' . self::tail(self::s3ServerLogPath(), 30)
        );
    }

    private static function s3PidFile(): string
    {
        return self::s3CacheDir() . '/server.json';
    }

    private static function s3ServerLogPath(): string
    {
        return self::s3CacheDir() . '/server.log';
    }

    /** pidfile 内容（都是本次起的服务端才有）；没起过返回 null */
    private static function s3ServerMeta(): ?array
    {
        if ( ! is_file(self::s3PidFile()) ) {
            return null;
        }

        $meta = json_decode((string)file_get_contents(self::s3PidFile()), true);

        return is_array($meta) ? $meta : null;
    }

    /** 进程是否还在（只看「启动者死没死」；pid 被复用会误判成活 —— 那就保守地不收，不误杀） */
    private static function processAlive(int $pid): bool
    {
        return trim(self::run('ps -o pid= -p ' . $pid, self::s3CacheDir(), true)) !== '';
    }

    /** 缓存目录里的 mc 二进制（lead 从 minio/mc 镜像里抽出来的那个）；没有返回 null */
    private static function mcBinary(): ?string
    {
        $binary = self::s3CacheDir() . '/mc';

        return is_file($binary) && is_executable($binary) ? $binary : null;
    }

    /** MC_HOST_<别名> 形式：scheme://KEY:SECRET@host[:port] —— 不落盘、不依赖 mc 配置目录 */
    private static function mcHostUrl(string $key, string $secret): string
    {
        $endpoint = self::s3Endpoint();

        if ( strpos($endpoint, '://') === false ) {
            $endpoint = 'http://' . $endpoint;
        }

        return preg_replace('#^(https?://)#', '$1' . rawurlencode($key) . ':' . rawurlencode($secret) . '@', $endpoint, 1);
    }

    private static function dockerImageExists(string $image): bool
    {
        return self::runCapture(['docker', 'image', 'inspect', $image], [])['code'] === 0;
    }

    /** 探活：返回 HTTP 状态码，连不上返回 0 */
    private static function probe(string $url): int
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 3]);
        curl_exec($ch);

        return (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    }

    /**
     * 跑命令并分开收 stdout/stderr（二进制安全：mc cat 的字节不能被 stderr 污染）。
     * $env 非空时并进当前环境再传给子进程（proc_open 的 env 参数是替换而非合并）。
     */
    private static function runCapture(array $command, array $env): array
    {
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open($command, $descriptors, $pipes, null, $env === [] ? null : array_merge(getenv(), $env));

        if ( ! is_resource($process) ) {
            throw new RuntimeException('无法执行：' . implode(' ', $command));
        }

        $stdout = (string)stream_get_contents($pipes[1]);
        $stderr = (string)stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return ['code' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
    }

    /** 记录「当前跑着的是哪种模式起的」；配置改写不会影响已启动的 worker，靠它决定要不要重启 */
    private static function modeMarker(): string
    {
        return self::root() . '/runtime/aetherupload-e2e-mode';
    }

    private static function runningMode(): string
    {
        return (string)@file_get_contents(self::modeMarker());
    }
}
