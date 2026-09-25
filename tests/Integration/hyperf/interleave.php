<?php

/**
 * 协程交错探针 —— 在独立进程里跑，把观测值以 JSON 打到 stdout，断言全部留在 phpunit 那边
 * （见 CoroutineIsolationTest）。独立进程的原因：swoole 的协程运行时与 PHPUnit 同处一室时，
 * 进程会在退出时段错误，并让之后同一个进程里的 curl 开始「连接被拒」——那些噪音与本用例要证的
 * 「协程隔离」无关。
 *
 * 用法：php tests/Integration/hyperf/interleave.php
 * 输出：AETHERA_PROBE_JSON:{"trace":[...],"seen":{...},"errors":[...],"applied":true}
 */

require __DIR__ . '/../../../vendor/autoload.php';
// 目录名是小写 hyperf，与命名空间大小写不一致，PSR-4 自动加载找不到，这里显式引入
require_once __DIR__ . '/App.php';

use AetherUpload\ConfigMapper;
use AetherUpload\Runtime;
use AetherUpload\Tests\Integration\Hyperf\App;
use Hyperf\Context\Context;
use Psr\Http\Message\ServerRequestInterface;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;

App::bootKernel();

/** 直接探 ContextStore 用的键（绕过内核，证明端口本身是协程局部的） */
const PROBE_KEY = 'aetherupload.e2e.isolation-probe';

/** 两个协程各自设一个不同的语种：语种也落在 Runtime::context() 里，读错一样能看出来 */
const LOCALE_A = 'zh-CN';
const LOCALE_B = 'en';

/** 构造一个「服务端已经放进本协程 Context」的 PSR-7 请求（对应 Server::initRequestAndResponse） */
function request(string $group): ServerRequestInterface
{
    return (new \Hyperf\HttpMessage\Server\Request('POST', '/aetherupload/preprocess'))
        ->withParsedBody(['group' => $group])
        ->withQueryParams([]);
}

$trace = [];
$seen = [];
$errors = [];
$aAppliedOk = false;

/*
 * 时序（全部由 Channel 硬同步，不含 sleep，因此不是「碰运气」）：
 *   A: apply(file)  → 通知 main → 等 B 的 apply 完成 → 读自己的配置
 *   main: 收到 A 的通知 → 起 B
 *   B: apply(video) → 通知 A → 等 A 读完 → 读自己的配置
 * 于是「B 已经 apply 完」必然早于「A 开始读」—— 任何共享存储都会让 A 读到 B 的配置。
 *
 * 协程体里不做断言、异常也一律记进 $errors 由调用方处理，免得变成难懂的 fatal。
 */
\Swoole\Coroutine\run(static function () use (&$trace, &$seen, &$errors, &$aAppliedOk) {
    $aApplied = new Channel(1);
    $bApplied = new Channel(1);
    $aRead = new Channel(1);
    $done = new Channel(2);

    // ---- 协程 A：分组 file（resource_maxsize 104857600）----
    Coroutine::create(static function () use (&$trace, &$seen, &$errors, $aApplied, $bApplied, $aRead, $done) {
        try {
            Context::set(ServerRequestInterface::class, request('file'));
            ConfigMapper::applyGroupConfig('file');
            Runtime::contextStore()->set(PROBE_KEY, 'A');
            Runtime::context()->locale = LOCALE_A;
            $trace[] = 'A:apply';
            $aApplied->push(true);

            // 等 B 也 apply 完才读：让「读到别人的配置」必然发生，而不是碰运气
            $bApplied->pop(5);

            $seen['A'] = [
                'group'            => ConfigMapper::get('group'),
                'group_dir'        => ConfigMapper::get('group_dir'),
                'resource_maxsize' => ConfigMapper::get('resource_maxsize'),
                'probe'            => Runtime::contextStore()->get(PROBE_KEY),
                'locale'           => Runtime::context()->locale,
                'ctx'              => spl_object_hash(Runtime::context()),
            ];
            $trace[] = 'A:read';
        } catch ( Throwable $e ) {
            $errors[] = 'A: ' . $e->getMessage();
        }

        $aRead->push(true);
        $done->push('A');
    });

    // 等 A 完成 apply 阶段
    $aAppliedOk = $aApplied->pop(5) === true;

    // ---- 协程 B：分组 video（resource_maxsize 20971520）----
    Coroutine::create(static function () use (&$trace, &$seen, &$errors, $bApplied, $aRead, $done) {
        try {
            Context::set(ServerRequestInterface::class, request(App::PROBE_GROUP));
            ConfigMapper::applyGroupConfig(App::PROBE_GROUP);
            Runtime::contextStore()->set(PROBE_KEY, 'B');
            Runtime::context()->locale = LOCALE_B;
            $trace[] = 'B:apply';
        } catch ( Throwable $e ) {
            $errors[] = 'B(apply): ' . $e->getMessage();
        }

        $bApplied->push(true);

        $aRead->pop(5);

        try {
            $seen['B'] = [
                'group'            => ConfigMapper::get('group'),
                'group_dir'        => ConfigMapper::get('group_dir'),
                'resource_maxsize' => ConfigMapper::get('resource_maxsize'),
                'probe'            => Runtime::contextStore()->get(PROBE_KEY),
                'locale'           => Runtime::context()->locale,
                'ctx'              => spl_object_hash(Runtime::context()),
            ];
            $trace[] = 'B:read';
        } catch ( Throwable $e ) {
            $errors[] = 'B(read): ' . $e->getMessage();
        }

        $done->push('B');
    });

    $done->pop(5);
    $done->pop(5);
});

echo 'AETHERA_PROBE_JSON:' . json_encode([
    'trace'   => $trace,
    'seen'    => $seen,
    'errors'  => $errors,
    'applied' => $aAppliedOk,
]) . "\n";
