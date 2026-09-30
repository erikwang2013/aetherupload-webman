# S3 兼容存储支持 — 设计文档

- **日期**：2026-10-01
- **状态**：已获 erik 确认（2026-10-01 对话批准）
- **范围**：内核新增可切换存储驱动；默认 `local` 行为逐字节不变；新增 `s3` 驱动，兼容一切讲 S3 协议的厂商（AWS / GCS / IBM COS / Oracle / Cloudflare R2 / Wasabi / Backblaze B2 / DO Spaces / Linode / Vultr / Hetzner / IONOS / 阿里云 OSS / 腾讯云 COS / 华为云 OBS / 移动云 / 青云 / MinIO / Ceph / Swift(s3api) / 各存储硬件厂商 S3 网关 等，共 40+ 家）。

## 1. 背景与目标

现状：分块 → 本地 `.part` 累积 → 完成时 `PartialResource::rename()` 把成品 rename 到 `root_dir/group_dir/subdir/<md5>.<ext>` → `savedPath = group_subdir_名` 写 Redis（秒传）→ `display/download` 读本地文件或交 nginx（X-Accel-Redirect）。

**接入点只有三处，分块暂存/`groups`/`clean` 命令/Install 全部不动**：

| 现状 | 改动 |
|---|---|
| `PartialResource::rename()` 本地 rename | → `Storage::driver()->publish()` |
| `Resource::exists()/delete()` | → 走 driver |
| `ResourceController` 读本地文件 / X-Accel | → driver `url()` 非 null 时 302 预签名；null 时原逻辑不动 |

**关键决策（已确认）**：
1. **内置最小 S3 客户端**（手写 SigV4 + HTTP），**零 composer 新依赖**；`ext-curl` 为 S3 模式的软依赖（`suggest` + 构造时报错）。
2. 成品文件在本地完整存在 → `hash_file('sha256')` 预算 payload 哈希，走**标准签名**，不用 aws-chunked 流式签名（各家 S3 兼容实现最易翻车处）。
3. 读路径走 **302 预签名 URL**，不做 PHP 代理流（九个适配器的响应工厂只支持本地 `file(path)`，流式代理成本不成比例）。
4. driver 是**全局单键**（`storage.driver`），不做 per-group 覆盖、不做本地/S3 并存双读。

## 2. 非目标（v1 明确不做）

- 浏览器/客户端直传 S3（presigned PUT per chunk）——协议级改造，另立项目。
- PHP 流式代理下载（私网 MinIO 场景需自行反代暴露 S3 端点，文档注明）。
- S3 版 `aetherupload:build`（需 ListObjectsV2）与 S3 对象生命周期清理（用户桶策略自理）。
- 已有本地文件向 S3 的自动迁移；切换 driver 后旧秒传记录 7 天内自然失效。
- 多 driver 并存 / per-group driver 覆盖。

## 3. 架构

```
src/Storage/
  StorageInterface.php   publish / exists / delete / url 四个方法
  LocalStorage.php       默认驱动，行为 = 今天的 rename/file_exists/unlink（url 恒 null）
  S3Storage.php          实现 StorageInterface，配置校验 + 键构造 + 组合 S3Client
  S3Client.php           协议层：SigV4 装配 + HTTP(curl) + 重试 + multipart 编排
  SignatureV4.php        纯函数签名（可对 AWS 官方测试向量单测）
  Storage.php            门面：Storage::driver() 按 config('storage.driver') 造实例
```

- 放 `src/Storage/`（**不是** `src/Contract/`）：`Contract/` 的 11 个接口是**宿主能力**，存储驱动是内核内部服务，两者性质不同，宿主契约数量保持 11 不变。
- 内核纯度：`src/Storage/` 不 `use` 任何宿主类，只经 `Runtime` 门面。
- **门面不缓存实例**：`Storage::driver()` 每次按 `Runtime::config()` 现造（构造成本 = 数组解析，可忽略）。绕过 ConfigMapper 单例 → 测试改配置无需 `resetConfigMapper()`，也没有常驻进程/协程下的单例串扰。
- 测试注入：`Storage::use(?StorageInterface $driver)` / `Storage::reset()` 静态钩子（仿 `Runtime::bind/reset` 既有先例），单测可塞 fake driver 测 Resource/ResourceController 分支。

### 3.1 冻结接口（团队照此施工，不得擅改）

```php
namespace AetherUpload\Storage;

interface StorageInterface
{
    /** 把本地成品文件落到存储。目标已存在（同 hash 去重）时删本地、不重复写。失败抛 \Exception(trans('rename_resource_fail')) */
    public function publish(string $localPath, string $groupDir, string $groupSubDir, string $name): void;

    /** 目标是否存在。S3: HEAD 200→true / 404→false / 其它状态抛 \Exception */
    public function exists(string $groupDir, string $groupSubDir, string $name): bool;

    /** 删除目标。S3 DELETE 幂等（缺失也算成功）；local 与今天 Resource::delete() 语义一致 */
    public function delete(string $groupDir, string $groupSubDir, string $name): bool;

    /** 可直发的 URL（S3: 预签名 GET，$responseParams 合并为 response-content-* 查询参数）；local 恒 null */
    public function url(string $groupDir, string $groupSubDir, string $name, array $responseParams = []): ?string;
}
```

```php
final class Storage
{
    public static function driver(): StorageInterface;            // 读 storage.driver：local|s3，未知值抛 \Exception("Unknown storage driver ...")
    public static function use(?StorageInterface $driver): void;  // 测试注入
    public static function reset(): void;
}
```

```php
final class SignatureV4
{
    /** 各步拆开，便于对 AWS 官方向量逐段断言。
     *  $headers 形如 ['host' => 'example.com', 'x-amz-date' => '...']，键为小写；参与签名前按 key 排序。
     *  $amzDate 形如 20150830T123600Z；$date 为其前 8 位（20150830），scope = "{date}/{region}/{service}/aws4_request"。 */
    public static function canonicalRequest(string $method, string $uri, string $query, array $headers, string $payloadHash): string;
    public static function stringToSign(string $amzDate, string $date, string $region, string $service, string $canonicalRequest): string;
    /** 返回原始二进制签名密钥（hash_hmac 链） */
    public static function signingKey(string $secretKey, string $date, string $region, string $service): string;
    /** 装配 Authorization 头（含 SignedHeaders 与 Signature=） */
    public static function authorizationHeader(string $method, string $uri, string $query, array $headers, string $payloadHash, string $accessKey, string $secretKey, string $region, string $service, string $amzDate): string;
    /** 返回完整查询串（X-Amz-Algorithm/Credential/Date/Expires/SignedHeaders/Signature），供 URL 拼接 */
    public static function presignQuery(string $method, string $uri, string $query, array $headers, string $accessKey, string $secretKey, string $region, string $service, string $amzDate, int $expires, string $payloadHash = 'UNSIGNED-PAYLOAD'): string;
}
```

```php
final class S3Client
{
    /** $config = storage.s3.*；$transport 测试注入，默认 curl 实现
     *  $transport(array $req): array => ['status'=>int, 'headers'=>array, 'body'=>string]
     *  $req = ['method'=>, 'url'=>, 'headers'=>array, 'body'=>string|resource, 'bodyFile'=>?string(路径流式发送), 'timeout'=>['connect'=>10,'lowSpeed'=>60]] */
    public function __construct(array $config, ?callable $transport = null);

    public function key(string $groupDir, string $groupSubDir, string $name): string; // prefix + gd/sub/name
    public function put(string $key, string $localPath, string $contentType): void;
    public function multipart(string $key, string $localPath, string $contentType): void;
    public function head(string $key): bool;
    public function delete(string $key): void;
    public function presign(string $key, int $ttl, array $responseParams = []): string;
    public function uploadMultipartOrPut(string $key, string $localPath, string $contentType): void; // 按 multipart_threshold + filesize 选路
}
```

### 3.2 写路径

`PartialResource::rename($completeName)` 改为一行：`Storage::driver()->publish($this->realPath, $this->groupDir, $this->groupSubDir, $completeName);`

- **LocalStorage::publish** = 今天的语义原样搬入：目标存在 → 删本地（去重）；否则 `rename()`。`root_dir` 参与本地路径（现状不变）。
- **S3Storage::publish** = `head(key)` 存在 → 删本地（同 hash 内容相同，省一次上传）；否则 PUT（>threshold 走 multipart）→ 删本地 `.part`。对象键 **不含 `root_dir`**（那是本地磁盘概念）。
- 失败时抛 `trans('rename_resource_fail')`（既有 i18n 键、已在 KNOWN_ERROR_MESSAGES 白名单）→ UploadController 现有 catch 逻辑清理 `.part` 并返回错误，**不写 Redis 秒传记录**。
- **语义差异（有意）**：本地模式 publish 失败 `.part` 被 cleanup，客户端需整传重试；S3 模式下 multipart 的**分片级重试**（每片 3 次）缓解大部分瞬时失败，整文件重传仍是兜底（与现状一致，不引入新的续传语义）。

### 3.3 读路径

`ResourceController::display()/download()`：先算

```php
$url = Storage::driver()->url($gd, $sub, $name, $dispositionParams);
if ($url !== null) { return Runtime::response()->text('', 302)->withHeader('Location', $url)->withHeader('X-Content-Type-Options', 'nosniff'); }
// 否则：现有 x_accel_redirect / file() / download() 分支一字不动
```

- `$dispositionParams` 仅在内联黑名单命中（display）或 download 改名时给出：`['response-content-disposition' => 'attachment; filename="<sanitized>"; filename*=UTF-8\'\'<rawurlencode>']`（filename 部分已过既有 `preg_replace` 消毒 + `addcslashes` 防头注入）。
- 对象 Content-Type 在 publish 时由 `mime_content_type($localPath)` 写入（上传链路已算过一次 mime，重复读取成本可忽略）。
- `x_accel_redirect` 与 s3 driver **互斥**：driver=s3 时预签名优先，x_accel 只对 local 生效（文档注明）。
- nosniff 只能加在 302 响应上；S3 对象响应头由 S3 服务端决定（302 之后浏览器直连 S3 取对象，按对象真实 Content-Type 处理，异源反而更隔离）。**已知限制，文档注明**。

### 3.4 Resource 模型

- `exists()` → `Storage::driver()->exists(...)`；`delete()` → driver，失败抛既有 `delete_resource_fail`。
- `path`/`realPath` 属性保持现状（本地语义，X-Accel/local 分支与既有测试依赖）。**S3 模式下 `realPath` 不指向真实文件**，`upload_complete` 事件监听者应向用 group/name/`Resource::exists()`，文档注明。

## 4. 配置（三处同步：`config/app.php`、`config/aetherupload.php`、Symfony `Configuration` 树）

```php
'storage' => [
    'driver' => 'local',                       // local | s3
    's3' => [
        'endpoint'   => '',                    // '' = AWS 默认（按 region 推导）。各家示例（以厂商文档为准）：
                                               //   阿里云 OSS   s3.oss-{region}.aliyuncs.com
                                               //   腾讯云 COS   cos.{region}.myqcloud.com
                                               //   华为云 OBS   obs.{region}.myhuaweicloud.com
                                               //   Cloudflare R2  {account}.r2.cloudflarestorage.com（region 填 auto）
                                               //   Wasabi s3.{region}.wasabisys.com · B2 s3.{region}.backblazeb2.com
                                               //   DO {region}.digitaloceanspaces.com · Oracle {ns}.compat.objectstorage.{region}.oraclecloud.com
                                               //   IBM COS s3.{region}.cloud-object-storage.appdomain.cloud · MinIO/Ceph 自填
        'region'     => 'us-east-1',
        'bucket'     => '',
        'access_key' => '',
        'secret_key' => '',
        'path_style' => true,                  // MinIO/Ceph/Swift-s3api 等自托管必须 true；AWS/部分公有云可 false
        'prefix'     => '',                    // 对象键前缀（可空）
        'multipart_threshold' => 104857600,    // 超过走 multipart（单次 PUT 硬上限 5GB）
    ],
],
```

- 不开放预签名 TTL（常量 300s）与 part size（常量 64MB）：没有真实调优场景，S3 硬性下限 5MB 由固定 part size 天然满足（非末片恒为 64MB）。
- `ConfigParityTest`（单元）的 required-keys 守护列表加 `'storage'`；Symfony 侧 parity 由 `tests/Integration/symfony/SymfonyConfigParityTest.php` 锁（如为硬编码列表需同步加键）。
- 校验（S3 模式构造函数内，边界处 fail-fast）：`bucket`/`region`/`access_key`/`secret_key` 非空，`driver` ∈ {local,s3}，错误消息明确指出缺哪个键（不含凭据值）。

## 5. 错误处理与重试

- 幂等操作（PUT / HEAD / DELETE / UploadPart）网络异常或 5xx / 429 重试至多 3 次，退避 200ms×attempt。4xx 不重试。
- `CompleteMultipartUpload` 返回 **HTTP 200 但体内嵌 `<Error>`** 视为失败（经典陷阱）；失败时先 HEAD 对象，存在→视为成功（响应丢失场景），否则 AbortMultipartUpload（best-effort，不掩盖原始异常）再抛。
- 异常消息：`S3 {OP} failed: {status} {Code} {Message} (key=...)`；**绝不携带凭据 / Authorization 头 / 签名**。
- 超时：connect 10s、无总超时、低速守卫（<1KB/s 持续 60s 中止）——大文件 PUT 不被总超时截断。
- 键编码：S3 canonical URI **不做双重编码**（S3 特有规则，与其它 AWS 服务不同）；本方案键空间为 `prefix/group_dir/subdir/<md5>.<ext>`，各段均经既有白名单校验（`isSafePathComponent` / 配置可信），特殊字符用例仍要进单测。

## 6. 安全

- 签名正确性是本特性的核心安全面：`SignatureV4` 以 **AWS 官方 sig-v4 测试向量**（aws-samples/aws-sig-v4-test-suite，内嵌子集并注明出处）为正确性锚。
- 凭据只存在于配置数组；异常、日志、HTTP 错误体一律不外泄；`var_dump/print_r` 全库无。
- 预签名 URL 含签名查询串——只出现在 302 Location 与返回体外的 URL() 调用点，不落日志。
- 路径注入：对象键各段来自服务端校验过的值（`SavedPathResolver::decode` 白名单 + 配置），无客户端原始输入直拼。

## 7. 测试与验证（红-绿证据标准）

### 7.1 单元（tests/ 根目录，替身隔离）

| 用例 | 锚点/红在哪 |
|---|---|
| `SignatureV4Test` | AWS 官方向量（get-vanilla / get-utf8 / post-x-www-form-urlencoded / normalize-path / 预签名示例）逐段断言 canonical/stringToSign/signature |
| `S3ClientTest` | 注入 fake transport：逐字节断言 method/URL/Authorization/x-amz-content-sha256/Content-Length；multipart 编排（initiate→parts→complete XML）；**200 内嵌 Error 陷阱**；重试矩阵（网络×2 后成功=3 次调用；403 不重试）；HEAD 404→false、403→throw；prefix 键构造；presign 查询参数集 |
| `S3StorageTest` | publish 去重（exists→删本地、零 PUT）；multipart 选路（阈值）；url 的 responseParams 合并；配置缺失的 fail-fast 消息 |
| `LocalStorageTest` | publish 语义 = 今天的 `PartialResource::rename`（存在→删本地；否则 rename）；exists/delete/url(null) |
| `StorageTest` | driver 选择；未知 driver 报错；use/reset 注入 |
| `ResourceControllerStorageTest` | fake s3 driver：display/download 返回 302+Location+disposition；fake local：现有分支不变 |
| `ConfigParityTest`（改） | `storage` 键三处一致（**真红**：键加进 config 前该用例必失败） |

### 7.2 端到端（webman harness + 真 MinIO）

- MinIO 由 docker 起（本地与 CI 同一条路），bucket 用 `minio/mc` 容器创建；**对象字节验证用 `mc`**（独立于我们自己的客户端，避免自证）。
- 用例：① driver=s3 全链路 → 桶内对象与源文件字节相等、`.part` 已清、savedPath 格式不变 ② `curl -L` 跟随 302 预签名 → 逐字节相等 ③ download 改名 → `mc stat`/响应头含 Content-Disposition ④ 秒传二次上传不建新 `.part` ⑤ **小阈值强制 multipart 真跑** ⑥ 内联黑名单（svg）→ attachment。
- **红证据**：实现落地前跑同一套 S3 用例 → 桶里没有对象（真红），再实现转绿。
- 默认 local 配置下**全部既有 E2E 用例（九宿主）不动全绿**——反向断言本地路径零回归。
- CI：`integration-webman` job 加 `minio/minio` service container；本地 `ci.sh`/bootstrap 无 MinIO 时跳过 S3 组并明示 Skipped（不假装通过）。

### 7.3 兼容矩阵（既有红线不破）

PHP 8.0–8.4（无 enum/readonly/never/trait 常量）、PHPUnit 9.6 与 10.5 双版本、内核无宿主类（grep 证明）、composer 依赖零新增。

## 8. 文件所有权（团队施工边界）

| 角色 | 独占文件 |
|---|---|
| coder | `src/Storage/*`（6 新文件）、`src/Resource.php`、`src/PartialResource.php`、`src/ResourceController.php`、`config/app.php`、`config/aetherupload.php`、`src/Adapter/Symfony/Configuration.php` |
| tester | `tests/*.php`（新增与更新 ConfigParityTest）、必要时 `tests/Support/` |
| harness | `tests/Integration/webman/*`、`.github/workflows/ci.yml` |
| reviewer | 只读 |
| lead（我） | spec/plan、最终 E2E、`docs/REPORT.md`、git 提交 |

- 任何写入者**不跑 git 状态变更命令**；提交由 lead 统一做。
- 测试先行：tester 的红用例先于 coder 合入并留存红输出（ConfigParity / 302 分支 / E2E 对象缺失）。
- 不确定的结论明确写"未验证"；不弱化断言换绿。

## 9. 验收标准

1. 既有单元套件全绿且**零改动**（local 默认路径零回归）。
2. 新增单元用例全绿；`SignatureV4` 官方向量全过。
3. MinIO 端到端 6 用例全绿，含 multipart 真跑与 `mc` 独立字节验证。
4. 红证据留存：① ConfigParity storage 键 ② 302 分支 ③ E2E 桶内无对象（实现前）。
5. PHP 8.0–8.4 + PHPUnit 9.6/10.5 兼容；composer 依赖零新增；`ext-curl` 仅 suggest。
6. `src/Storage/` 无宿主类引用（grep 证据）。

## 10. 升级路径（记录在案，本期不做）

- S3 版 `aetherupload:build`：ListObjectsV2 列桶按 `<md5>.<ext>` 重建索引。
- PHP 代理流式下载：需九个响应工厂支持流式响应，私网 MinIO 场景的完整解。
- per-group driver 覆盖：`groups.<name>.storage` 优先于全局。
- 客户端直传 S3：协议级演进，独立立项。
