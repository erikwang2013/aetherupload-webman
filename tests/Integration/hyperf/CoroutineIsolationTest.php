<?php

namespace AetherUpload\Tests\Integration\Hyperf;

use PHPUnit\Framework\TestCase;

/**
 * 协程隔离 —— Hyperf 这一家的重点，也是唯一不能靠「照抄 webman 一份」蒙过去的端口。
 *
 * 背景：swoole 在**同一个 worker 进程**里用协程交错处理多个请求。任何进程级静态数组存的
 * 「当前分组配置 / 当前语种」都会被下一个请求覆盖。内核因此把这些可变状态全部放进
 * Runtime::context()（RequestContext），由 AdapterInterface::contextStore() 决定隔离边界；
 * Hyperf 的实现是 Hyperf\Context\Context（按协程 id 取 ArrayObject）。
 *
 * 两条互补的证明：
 *   1. testInterleavedCoroutinesReadOwnGroupConfig —— 真起协程并**强制交错**（Channel 硬同步，
 *      不含 sleep 与猜时序），两个协程各读自己的分组配置。跑在独立进程里（interleave.php），
 *      原因见那个文件顶部：swoole 协程运行时和 PHPUnit 同进程会让进程退出时段错误。
 *   2. testConcurrentRequestsKeepOwnGroupConfig —— 真 HTTP 并发打真服务（单 worker、开 file hook
 *      的真交错），用「分组 × resource_size」的裁决矩阵判断每个请求读到了谁的上限。
 */
class CoroutineIsolationTest extends TestCase
{
    /** video 分组的 resource_maxsize（见 App::patchConfigs），file 是默认的 104857600 */
    const PROBE_MAXSIZE = 20971520;

    /** 大于 PROBE_MAXSIZE、小于 file 的 104857600：只有读错分组配置才会被拒 */
    const CROSSING_SIZE = 30000000;

    protected function setUp(): void
    {
        if ( ! extension_loaded('swoole') ) {
            // 如实报告：没有 swoole 就没有协程，这一条在本机未验证
            $this->markTestSkipped('本机 PHP 没有 ext-swoole，无法起协程，协程隔离用例未在本机验证');
        }
    }

    // ------------------------------------------------------------------ 用例一：强制交错的协程

    /**
     * 协程 A（分组 file）与协程 B（分组 video）交错执行，各自读回自己的分组配置、语种与 ContextStore。
     *
     * 隔离性由 interleave.php 里的 Channel 时序保证：B 的 apply 必然早于 A 的 read，
     * 于是只要存储是共享的，A 一定会读到 video。若 A 读到 file，只可能是隔离成立了。
     * 观测值由子进程原样打回，断言全部在这边 —— 子进程不自行判定通过与否。
     */
    public function testInterleavedCoroutinesReadOwnGroupConfig(): void
    {
        $result = App::php(App::repoRoot() . '/tests/Integration/hyperf/interleave.php');

        $this->assertSame(
            0,
            $result['code'],
            "协程探针进程异常退出（exit {$result['code']}）：\n" . $result['stderr'] . $result['stdout']
        );

        $payload = $this->parseProbeJson($result['stdout']);

        $this->assertSame([], $payload['errors'], '协程执行期出错：' . implode(' | ', $payload['errors']));
        $this->assertTrue($payload['applied'], '协程 A 未在 5s 内完成 apply');

        // 先证明「真的交错了」：B 的 apply 落在 A 的 apply 与 A 的 read 之间。
        // 少了这一条，下面的断言可能只是因为两个协程先后跑完而侥幸通过。
        $this->assertSame(
            ['A:apply', 'B:apply', 'A:read', 'B:read'],
            $payload['trace'],
            '协程未按预期交错（B 必须在 A 开始读之前就 apply 完），本用例失去证明力'
        );

        $this->assertArrayHasKey('A', $payload['seen'], '协程 A 未产出观测值');
        $this->assertArrayHasKey('B', $payload['seen'], '协程 B 未产出观测值');
        $a = $payload['seen']['A'];
        $b = $payload['seen']['B'];

        $this->assertSame('file', $a['group'], '协程 A 读到了别的协程的分组');
        $this->assertSame('file', $a['group_dir'], '协程 A 的 group_dir 被协程 B 覆盖');
        $this->assertSame(104857600, $a['resource_maxsize'], '协程 A 的 resource_maxsize 被协程 B 覆盖');

        $this->assertSame(App::PROBE_GROUP, $b['group'], '协程 B 读到了别的协程的分组');
        $this->assertSame(App::PROBE_GROUP, $b['group_dir'], '协程 B 的 group_dir 被协程 A 覆盖');
        $this->assertSame(self::PROBE_MAXSIZE, $b['resource_maxsize'], '协程 B 的 resource_maxsize 被协程 A 覆盖');

        // 端口本身是协程局部的：同一时刻两个协程往同一个键写的值互不可见
        $this->assertSame('A', $a['probe'], 'ContextStore 不是协程局部的：协程 A 读到了协程 B 写的值');
        $this->assertSame('B', $b['probe'], 'ContextStore 不是协程局部的：协程 B 读到了协程 A 写的值');

        // 语种也落在执行上下文里：交错时不能互相串
        $this->assertSame('zh-CN', $a['locale'], '协程 A 的语种被协程 B 覆盖');
        $this->assertSame('en', $b['locale'], '协程 B 的语种被协程 A 覆盖');

        // 执行上下文本身也是两份（Runtime::context() 按 contextToken 换份）
        $this->assertNotSame($a['ctx'], $b['ctx'], '两个协程共用了一个 RequestContext');
    }

    /** 从子进程 stdout 里取出探针那一行 JSON */
    private function parseProbeJson(string $stdout): array
    {
        foreach ( array_reverse(explode("\n", $stdout)) as $line ) {
            if ( strpos($line, 'AETHERA_PROBE_JSON:') !== 0 ) {
                continue;
            }

            $payload = json_decode(substr($line, strlen('AETHERA_PROBE_JSON:')), true);

            $this->assertIsArray($payload, '探针输出不是合法 JSON：' . $line);

            return $payload;
        }

        $this->fail('探针没有输出观测值：' . $stdout);
    }

    // ------------------------------------------------------------------ 用例二：真 HTTP 并发

    /**
     * 真 HTTP 并发：curl_multi 同时打 8 个 preprocess，四种「分组 × resource_size」组合各两次。
     *
     * 组合设计（file 上限 104857600、video 上限 20971520）：
     *   file  + 30000000   → 放行    读错成 video 才会被拒
     *   video + 30000000   → 拒绝    读错成 file 才会放行
     *   video +  1000000   → 放行    证明 video 不是「一律拒绝」
     *   file  + 200000000  → 拒绝    证明 file 不是「一律放行」
     * 前两行互为对照：只有「每个请求读到了自己分组的上限」才能同时成立。
     *
     * 断言只压裁决（error 是否为 0），不压错误原文：内核 fail() 只把「未翻译的 key」
     * 原样透出，其余一律回 upload_error 的译文，措辞属于内核行为、不是本适配器的契约。
     *
     * swoole 的 file hook（bin/hyperf.php 里的 DefaultOption::hookFlags()，全开）让 preprocess 里的
     * 文件操作会让出协程，单 worker（server.php: worker_num=1）下这些请求是真的交错执行的。
     *
     * 四组裁决先**串行**跑一遍再并发跑一遍：
     *   - 串行那一遍本身就是「读到自己的分组配置」的判据（30M 放行 / 30M 拒绝 互为对照）；
     *   - 顺带把该分组的当日目录建出来 —— 内核 PartialResource::createGroupSubDir()（src 里，不在
     *     本适配器可改范围）是 file_exists→mkdir 的 TOCTOU：冷目录上并发首传时，抢输的那个协程
     *     mkdir 返回 false → 直接 upload_error。那是内核的竞态，与本用例要证的「分组配置隔离」
     *     无关，混进来只会让这条用例时红时绿。
     */
    public function testConcurrentRequestsKeepOwnGroupConfig(): void
    {
        $this->assertTrue(App::isUp(), 'HTTP 服务未就绪，无法验证并发请求的分组隔离');

        /** @var array<int,array{0:string,1:int,2:bool}> [分组, resource_size, 是否应放行] */
        $cases = [
            ['file', self::CROSSING_SIZE, true],
            [App::PROBE_GROUP, self::CROSSING_SIZE, false],
            [App::PROBE_GROUP, 1000000, true],
            ['file', 200000000, false],
        ];

        // ---- 第一遍：串行（同时也是建目录的一遍）----
        foreach ( $cases as $i => $case ) {
            [ $group, $size, $accepted ] = $case;

            $this->assertVerdict(
                self::preprocess($group, $size, 'serial-' . $i),
                $group,
                $size,
                $accepted,
                '串行请求'
            );
        }

        // ---- 第二遍：8 个并发，同样的四组裁决各来两次 ----
        $multi = curl_multi_init();
        $handles = [];

        foreach ( array_merge($cases, $cases) as $i => $case ) {
            [ $group, $size ] = $case;

            $ch = self::preprocessHandle($group, $size, 'concurrent-' . $i);
            $handles[] = [$ch, $case];
            curl_multi_add_handle($multi, $ch);
        }

        do {
            $status = curl_multi_exec($multi, $running);
        } while ( $running && $status === CURLM_OK );

        foreach ( $handles as [ $ch, $case ] ) {
            [ $group, $size, $accepted ] = $case;

            $body = (string)curl_multi_getcontent($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_multi_remove_handle($multi, $ch);
            curl_close($ch);

            $this->assertVerdict(
                ['status' => $code, 'body' => $body],
                $group,
                $size,
                $accepted,
                '并发请求'
            );
        }

        curl_multi_close($multi);
    }

    /** 单发一个 preprocess，返回 [status, body] */
    private static function preprocess(string $group, int $size, string $tag): array
    {
        $ch = self::preprocessHandle($group, $size, $tag);
        $body = (string)curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        return ['status' => $status, 'body' => $body];
    }

    /** 建好一个还没加进 multi 的 preprocess 句柄（resource_hash 用 tag 保证每次都不一样） */
    private static function preprocessHandle(string $group, int $size, string $tag)
    {
        $ch = curl_init(App::baseUrl() . '/aetherupload/preprocess');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query([
                'resource_name' => 'concurrent.gif',
                'resource_size' => $size,
                'group'         => $group,
                'resource_hash' => md5($tag . '#' . $group . '#' . $size),
            ]),
            CURLOPT_TIMEOUT        => 30,
        ]);

        return $ch;
    }

    /** 压裁决：只有「每个请求读到的是自己分组的上限」才能让四组同时成立 */
    private function assertVerdict(array $response, string $group, int $size, bool $accepted, string $kind): void
    {
        $label = "{$kind} group={$group} resource_size={$size}";

        $this->assertSame(200, $response['status'], "（{$label}）状态码异常：" . substr($response['body'], 0, 300));

        $json = json_decode($response['body'], true);
        $this->assertIsArray($json, "（{$label}）响应不是 JSON：" . substr($response['body'], 0, 300));
        $this->assertArrayHasKey('error', $json, "（{$label}）响应缺少 error 字段：" . substr($response['body'], 0, 300));

        if ( $accepted ) {
            $this->assertSame(
                0,
                $json['error'],
                "（{$label}）应放行；只有读到了别的分组的上限才会被拒："
                . json_encode($json, JSON_UNESCAPED_UNICODE)
            );

            return;
        }

        $this->assertNotSame(
            0,
            $json['error'],
            "（{$label}）应被拒；只有读到了别的分组的上限才会放行："
            . json_encode($json, JSON_UNESCAPED_UNICODE)
        );
        $this->assertSame('', $json['savedPath'], "（{$label}）被拒的请求不该给出 savedPath");
    }
}
