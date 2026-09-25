<?php

namespace AetherUpload\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * 七个框架共享的端到端（真框架 + 真 HTTP）断言。
 *
 * 各框架只实现两件事：
 *   - send()         发一个真实请求，返回 ['status' => int, 'body' => string, 'headers' => string[]]
 *   - appBasePath()  宿主应用根目录（磁盘断言要直接看文件系统）
 * 另可覆盖 routes()/groupName()/probeLogPath()/supportsInstantCompletion()（见各自默认实现）。
 *
 * 断言清单（对应方案里的 8 条，编号保留以便对照）：
 *   [1] preprocess 返回合法字段，并在磁盘上建出 .part 与内容为 '0' 的 _header
 *   [2] 3 个分块逐块 error===0，末块给出 savedPath，.part 与 header 消失
 *   [3] display 响应体与上传字节逐字节相等，且带 X-Content-Type-Options: nosniff
 *   [4] download 响应体逐字节相等，Content-Disposition 含新文件名
 *   [5] 错误 hash 的完整上传被服务端拒绝（error 为 upload_error 的译文）
 *   [6] 语种未命中时 error 必须是字面 key 'upload_error'（证明「未命中返回 key」在真实宿主翻译器上成立）
 *   [7] 监听器抛异常不影响上传响应，且后续监听器仍被调用（事件探针）
 *   [8] 秒传：同 group+hash 的第二次 preprocess 直接回 savedPath 且不建新 .part（需要 redis）
 *
 * 前提（由各框架的 harness 保证，trait 不负责）：
 *   - 宿主应用已装好本插件，且 groupName() 分组的 event_upload_complete 为 true（第 7 条）；
 *     秒传开关由 harness 按 redis 可用性决定（第 8 条）；
 *   - 宿主 default_timezone 与测试进程一致，否则 groupSubDir 会在跨月边界漂；
 *   - 宿主没有 probeLocale() 语种的翻译、也没有 fallback 到已加载语种，否则第 6 条测不到「未命中」。
 */
trait FlowAssertions
{
    /** translations/en/messages.php 里 upload_error 的原文，第 5 条按它断言 */
    const UPLOAD_ERROR_MESSAGE = 'Error: error occurs during upload';

    /** 未翻译语种探针用的 locale（宿主不得加载该语种的 messages，也不得 fallback 到已加载语种） */
    const PROBE_LOCALE = 'zz';

    /** 上传分块数：43 字节的 GIF 切成 3 块 */
    const CHUNK_TOTAL = 3;

    /** @var string|null 本次用例的上传字节（每个测试方法用一份独占内容，见 gifBytes()） */
    private $gifFixture;

    /** 发一个请求：$files 形如 ['resource_chunk' => ['name' => 'chunk.bin', 'content' => $bytes]] */
    abstract protected function send(string $method, string $path, array $post = [], array $files = [], array $headers = []): array;

    /** 宿主应用根目录 */
    abstract protected function appBasePath(): string;

    // ------------------------------------------------------------------ 钩子

    /** 路由表：名字 => 路径。前端与路由文件都从配置读，默认值即插件默认配置 */
    protected function routes(): array
    {
        return [
            'preprocess' => '/aetherupload/preprocess',
            'uploading'  => '/aetherupload/uploading',
            'display'    => '/aetherupload/display',
            'download'   => '/aetherupload/download',
        ];
    }

    /** 测试用的分组名 */
    protected function groupName(): string
    {
        return 'file';
    }

    /** 分组目录名（默认配置里 group_dir 与分组名同名） */
    protected function groupDir(): string
    {
        return $this->groupName();
    }

    /** 上传根目录（root_dir 默认值，改了 root_dir 的 harness 覆盖此方法） */
    protected function uploadRoot(): string
    {
        return rtrim($this->appBasePath(), '/\\') . '/storage/app/aetherupload';
    }

    /** 事件探针日志路径；返回 null 表示该框架没布置探针 ⇒ 第 7 条跳过（如实报告，不放宽断言） */
    protected function probeLogPath(): ?string
    {
        return null;
    }

    /** 秒传是否可用（需要 redis）；false ⇒ 第 8 条跳过 */
    protected function supportsInstantCompletion(): bool
    {
        return false;
    }

    /** 跳过第 8 条时给出的原因（写清缺什么） */
    protected function instantCompletionSkipReason(): string
    {
        return '宿主未提供可用 redis；需要在 job 里起 redis service 才能验证秒传（第 8 条）';
    }

    // ------------------------------------------------------------- 测试方法

    /** [1]-[4] 完整流程：preprocess → 3 块 → display → download */
    public function testFullUploadFlow(): void
    {
        $this->assertFullUploadFlow();
    }

    /** [5]-[6] 错误 hash 与输入类型边界 */
    public function testErrorPaths(): void
    {
        // [5] 错误 hash 的完整上传必须被拒
        $bad = $this->uploadGif('en', md5('aetherupload-e2e-wrong-hash'));
        $this->assertSame(self::UPLOAD_ERROR_MESSAGE, $bad['last']['error'], '[5] 错误 hash 上传必须报 upload_error');
        $this->assertSame('', $bad['last']['savedPath'], '[5] 错误 hash 上传不得给出 savedPath');
        $this->assertFileDoesNotExist($this->partPath($bad['subdir'], $bad['temp']), '[5] 失败后 .part 必须被清理');
        $this->assertFileDoesNotExist($this->headerPath($bad['temp']), '[5] 失败后 header 必须被清理');

        // [5] 输入类型边界：resource_name 传数组不能把服务端打成 500
        $bytes = $this->gifBytes();
        $response = $this->send('POST', $this->path('preprocess'), [
            'resource_name' => [1],
            'resource_size' => strlen($bytes),
            'group'         => $this->groupName(),
            'resource_hash' => md5($bytes),
        ]);
        $this->assertNotSame(500, $response['status'], '[5] 数组型 resource_name 不得导致 500：' . substr($response['body'], 0, 400));
        $this->assertSame(200, $response['status'], '[5] 数组型 resource_name 应由校验分支返回 200');

        // [6] 未翻译语种：必须回落到字面 key，而不是宿主翻译器的 id（如 aetherupload::messages.upload_error）
        $probe = $this->uploadGif(self::PROBE_LOCALE, md5('aetherupload-e2e-wrong-hash'));
        $this->assertSame('upload_error', $probe['last']['error'], '[6] 翻译未命中时必须返回 key 本身');
    }

    /** [7] 事件探针：监听器抛异常不冒泡，后续监听器仍执行 */
    public function testListenerExceptionDoesNotBubble(): void
    {
        $log = $this->probeLogPath();

        if ( $log === null ) {
            $this->markTestSkipped('该框架未布置事件探针（probeLogPath() 返回 null），第 7 条未验证');
        }

        if ( is_file($log) && ! unlink($log) ) {
            $this->fail('无法清空事件探针日志：' . $log);
        }

        $flow = $this->uploadGif();

        $this->assertSame(0, $flow['last']['error'], '[7] 监听器抛异常不得影响上传响应');
        $this->assertNotSame('', $flow['last']['savedPath'], '[7] 上传仍须成功完成');
        $this->assertFileExists($log, '[7] 记录型监听器未被调用（日志未生成）');
        $this->assertStringContainsString('aetherupload.upload_complete', (string)file_get_contents($log), '[7] 记录型监听器未被调用');
    }

    /** [8] 秒传 */
    public function testInstantCompletion(): void
    {
        if ( ! $this->supportsInstantCompletion() ) {
            $this->markTestSkipped($this->instantCompletionSkipReason());
        }

        $first = $this->uploadGif();
        $savedPath = $first['last']['savedPath'];

        $this->assertNotSame('', $savedPath, '[8] 首次上传必须落盘');
        $this->assertFileExists($this->completePath($first['subdir'], $first['hash']), '[8] 首次上传的文件必须存在');

        // 同 group + 同 hash 再 preprocess：必须命中秒传
        $pre = $this->preprocess($first['bytes'], $first['hash'], 'en');

        $this->assertSame(0, $pre['error'], '[8] 秒传 preprocess 必须成功');
        $this->assertSame($savedPath, $pre['savedPath'], '[8] 秒传必须直接回已存在的 savedPath');
        $this->assertFileDoesNotExist($this->partPath($pre['groupSubDir'], $pre['resourceTempBaseName']), '[8] 秒传不得新建 .part');
        $this->assertFileDoesNotExist($this->headerPath($pre['resourceTempBaseName']), '[8] 秒传不得新建 header');
    }

    // --------------------------------------------------------------- 断言实现

    /**
     * [1]-[4]：完整流程断言。返回本轮的中间量，供第 7/8 条复用。
     */
    protected function assertFullUploadFlow(): array
    {
        $bytes = $this->gifBytes();
        $expectSubDir = date('Ym');

        // ---- [1] preprocess ----
        $pre = $this->preprocess($bytes, md5($bytes), 'en');

        $this->assertSame(0, $pre['error'], '[1] preprocess 必须成功：' . json_encode($pre, JSON_UNESCAPED_UNICODE));
        $this->assertSame(1000000, $pre['chunkSize'], '[1] chunkSize 必须是配置值');
        $this->assertSame($expectSubDir, $pre['groupSubDir'], '[1] 按月生成的子目录');
        $this->assertNotSame('', $pre['resourceTempBaseName'], '[1] 必须给出临时基名');
        $this->assertSame('gif', $pre['resourceExt'], '[1] 扩展名必须透传');

        $temp = $pre['resourceTempBaseName'];
        $subdir = $pre['groupSubDir'];

        $this->assertFileExists($this->partPath($subdir, $temp), '[1] preprocess 后必须出现 .part');
        $this->assertFileExists($this->headerPath($temp), '[1] preprocess 后必须出现 _header 文件');
        $this->assertSame('0', file_get_contents($this->headerPath($temp)), '[1] header 初始内容必须是 "0"');

        // ---- [2] 3 个分块 ----
        $last = [];
        foreach ( $this->chunks($bytes) as $index => $chunk ) {
            $last = $this->parseJsonResponse($this->send('POST', $this->path('uploading'), [
                'chunk_total'            => self::CHUNK_TOTAL,
                'chunk_index'            => $index + 1,
                'resource_temp_basename' => $temp,
                'resource_ext'           => 'gif',
                'group_subdir'           => $subdir,
                'group'                  => $this->groupName(),
                'resource_hash'          => md5($bytes),
            ], ['resource_chunk' => ['name' => 'chunk.gif', 'content' => $chunk]]));

            $this->assertSame(0, $last['error'], '[2] 第 ' . ($index + 1) . ' 块必须成功：' . json_encode($last, JSON_UNESCAPED_UNICODE));
        }

        $savedPath = $last['savedPath'];
        $this->assertMatchesRegularExpression('/^file_\d{6}_[0-9a-f]{32}\.gif$/', $savedPath, '[2] savedPath 形态');
        $this->assertFileDoesNotExist($this->partPath($subdir, $temp), '[2] 完成后 .part 必须消失');
        $this->assertFileDoesNotExist($this->headerPath($temp), '[2] 完成后 header 必须消失');

        $complete = $this->completePath($subdir, md5($bytes));
        $this->assertFileExists($complete, '[2] 完成后必须落成正式文件名（磁盘名 = 实算 md5，不是 savedPath）');
        $this->assertSame($bytes, file_get_contents($complete), '[2] 落盘内容必须与上传字节一致');

        // ---- [3] display ----
        $display = $this->send('GET', $this->path('display') . '/' . $savedPath);
        $this->assertSame(200, $display['status'], '[3] display 状态码');
        $this->assertSame($bytes, $display['body'], '[3] display 响应体必须与上传字节逐字节相等');
        $this->assertSame('nosniff', $this->header($display['headers'], 'X-Content-Type-Options'), '[3] display 必须带 nosniff');

        // ---- [4] download ----
        $download = $this->send('GET', $this->path('download') . '/' . $savedPath . '/new.gif');
        $this->assertSame(200, $download['status'], '[4] download 状态码');
        $this->assertSame($bytes, $download['body'], '[4] download 响应体必须与上传字节逐字节相等');
        $this->assertStringContainsString('new.gif', $this->header($download['headers'], 'Content-Disposition'), '[4] Content-Disposition 必须带新文件名');

        return [
            'bytes'     => $bytes,
            'hash'      => md5($bytes),
            'subdir'    => $subdir,
            'temp'      => $temp,
            'savedPath' => $savedPath,
            'last'      => $last,
        ];
    }

    // ------------------------------------------------------------- 流程助手

    /** 走一遍 preprocess + 3 块；$hash 用于构造「错误 hash」场景，$locale 用于「未翻译」探针 */
    protected function uploadGif(string $locale = 'en', ?string $hash = null): array
    {
        $bytes = $this->gifBytes();
        $hash = $hash ?: md5($bytes);

        $pre = $this->preprocess($bytes, $hash, $locale);
        $this->assertSame(0, $pre['error'], 'preprocess 失败：' . json_encode($pre, JSON_UNESCAPED_UNICODE));

        $last = [];
        foreach ( $this->chunks($bytes) as $index => $chunk ) {
            $last = $this->parseJsonResponse($this->send('POST', $this->path('uploading'), [
                'chunk_total'            => self::CHUNK_TOTAL,
                'chunk_index'            => $index + 1,
                'resource_temp_basename' => $pre['resourceTempBaseName'],
                'resource_ext'           => 'gif',
                'group_subdir'           => $pre['groupSubDir'],
                'group'                  => $this->groupName(),
                'resource_hash'          => $hash,
                'locale'                 => $locale,
            ], ['resource_chunk' => ['name' => 'chunk.gif', 'content' => $chunk]]));
        }

        return [
            'bytes'     => $bytes,
            'hash'      => $hash,
            'subdir'    => $pre['groupSubDir'],
            'temp'      => $pre['resourceTempBaseName'],
            'savedPath' => (string)($last['savedPath'] ?? ''),
            'last'      => $last,
        ];
    }

    protected function preprocess(string $bytes, string $hash, string $locale = 'en'): array
    {
        return $this->parseJsonResponse($this->send('POST', $this->path('preprocess'), [
            'resource_name' => 'probe.gif',
            'resource_size' => strlen($bytes),
            'group'         => $this->groupName(),
            'resource_hash' => $hash,
            'locale'        => $locale,
        ]));
    }

    /**
     * 最小合法 GIF89a（1x1），43 字节。每个测试方法拿到一份独占内容（同一方法内多次调用返回值相同）。
     *
     * 内容必须独占，否则秒传会把测试互相带偏：内核在 saveChunk 里先查 redis 记录
     * （src/UploadController.php 的「instant completion」短路分支），同一 hash 的第二次上传直接回
     * savedPath 并跳过完成流程 —— 于是第 7 条的事件永不触发、第 8 条的「首次上传」根本不存在；
     * 上一轮跑测留下的 redis 记录（TTL 7 天）也会让第 1 条断言看不到 .part。随机化的正是调色板 6 字节，
     * 各框架的 harness 可以再补一道「用例前清掉 aetherupload:* 记录」（webman 侧见 App::flushInstantCompletionKeys()）。
     * 结构（GIF8 魔数、块布局）不变，仍是合法 GIF。
     */
    protected function gifBytes(): string
    {
        if ( $this->gifFixture === null ) {
            $this->gifFixture = "GIF89a\x01\x00\x01\x00\x80\x00\x00" . random_bytes(6)
                . "\x21\xf9\x04\x01\x00\x00\x00\x00"
                . "\x2c\x00\x00\x00\x00\x01\x00\x01\x00\x00"
                . "\x02\x02\x44\x01\x00"
                . "\x3b";
        }

        return $this->gifFixture;
    }

    /** 把字节切成 CHUNK_TOTAL 块（大小尽量均分） */
    protected function chunks(string $bytes): array
    {
        $size = (int)ceil(strlen($bytes) / self::CHUNK_TOTAL);
        $chunks = str_split($bytes, $size);

        $this->assertCount(self::CHUNK_TOTAL, $chunks, 'fixture 必须正好切成 ' . self::CHUNK_TOTAL . ' 块');

        return $chunks;
    }

    // ------------------------------------------------------------- 路径与解析

    protected function path(string $name): string
    {
        return $this->routes()[$name];
    }

    protected function partPath(string $subdir, string $temp): string
    {
        return $this->uploadRoot() . '/' . $this->groupDir() . '/' . $subdir . '/' . $temp . '.gif.part';
    }

    protected function headerPath(string $temp): string
    {
        return $this->uploadRoot() . '/_header/' . $temp;
    }

    /**
     * 完成态文件在磁盘上的路径。注意落盘名与 savedPath 不是一回事：
     * savedPath 是 URL 身份（file_<Ym>_<hash>.gif，SavedPathResolver::encode），磁盘上是
     * Util::getFileName($resourceRealHash, $ext) = <服务端实算 md5>.<ext>。
     * 这里要的是后者，所以传 $hash（内容正确时即 md5($bytes)）。
     */
    protected function completePath(string $subdir, string $hash): string
    {
        return $this->uploadRoot() . '/' . $this->groupDir() . '/' . $subdir . '/' . $hash . '.gif';
    }

    /** 解析响应；非 200 或非 JSON 直接失败并带上原文，避免断言里堆砌噪音 */
    protected function parseJsonResponse(array $response): array
    {
        $data = json_decode($response['body'], true);

        if ( ! is_array($data) ) {
            /** @var TestCase $this */
            $this->fail('响应不是合法 JSON（status ' . $response['status'] . '）：' . substr($response['body'], 0, 500));
        }

        return $data;
    }

    /** 取响应头（大小写不敏感，同名取最后一个） */
    protected function header(array $headers, string $name): string
    {
        $found = '';

        foreach ( $headers as $line ) {
            if ( stripos($line, $name . ':') === 0 ) {
                $found = trim(substr($line, strlen($name) + 1));
            }
        }

        return $found;
    }
}
