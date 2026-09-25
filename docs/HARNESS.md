# AetherUpload 单元测试契约（Harness Contract）

本库是框架无关的上传内核（`src/` 根目录 + `src/Contract/` + `src/Kernel/`）加七个宿主适配器
（`src/Adapter/<Framework>/`），内核只经 `Runtime` 门面与 11 个契约同宿主交谈。单元测试**不引入任何框架**，
用测试替身隔离宿主依赖，因此一份用例同时覆盖八个宿主的内核行为。**编写测试前必读本文档。**

> **本文档只管单元测试。** 仓库里还有第二套测试体系：`tests/Integration/` 下是**端到端套件**，
> 每个框架一套（webman / laravel / thinkphp / symfony / slim / hyperf / yii），
> 真装框架、真跑 `preprocess → 分块 → 完成 → 下载`，用来证明"在该框架下确实能用"——
> 这一点替身证明不了。入口是 `vendor/bin/phpunit -c tests/Integration/webman/phpunit.xml`（webman，
> 起真服务 + curl）或其余七个宿主各目录下的 `ci.sh`（本地与 CI 共用同一条路），
> 共享断言在 `tests/Integration/FlowAssertions.php`，新框架只需实现 `send()` 与 `appBasePath()`。
> 主 `phpunit.xml.dist` 已 `<exclude>tests/Integration</exclude>`，两套互不干扰。

`tests/bootstrap.php` 在加载测试前做两件事：绑定 `WebmanAdapter`（`Runtime::bind()`），并定义一组
**全局函数桩**（下表）——适配器把内核端口代理到这些桩上，桩再从 `TestState` 取值。
所以「改 `TestState` ⇒ 内核可见」这条注入链路是完整的，既有断言一行没动。
另外七个适配器（含原生 PHP）**单元测试完全不加载**（它们的 composer 依赖不在 require 里），只在各自的
`tests/Integration/<fw>/` 里被真实框架加载和执行。

## 运行

```bash
vendor/bin/phpunit --no-coverage

# 需要验证其它 PHP 版本（8.0/8.4 等）时才用 Docker：
docker run --rm -v $PWD:/app -w /app php:8.3-cli-alpine php vendor/bin/phpunit --no-coverage
```

PHPUnit 10.5（PHP 8.1+；PHP 8.0 上 composer 会解析到 9.6，CI 矩阵同此），配置见
`phpunit.xml.dist`。测试文件放 `tests/` 根目录，类名 `*Test`，
命名空间 `AetherUpload\Tests`，继承 `PHPUnit\Framework\TestCase`。

## 全局函数桩（bootstrap 已定义，测试内直接使用）

| 函数 | 行为 |
|---|---|
| `config($key, $default=null)` | 读 `TestState::$config`，支持点号嵌套 key |
| `trans($key)` | 原样返回 `$key`（断言错误消息时直接断言 key 字符串） |
| `locale($locale=null)` | 记录到 `TestState::$locale` |
| `base_path()` | 返回 `TestState::$basePath`（默认 sys_get_temp_dir 下随机目录） |
| `request()` | 返回 `TestState::$request` |
| `response($body='', $status=200, $headers=[])` | 返回 `ResponseStub` |
| `json($data, $status=200, $headers=[])` | 返回 `ResponseStub`（body 为 json_encode） |
| `remove_dir($dir)` | 真实递归删除 |

> `copy_dir` 的全局替身**已移除**：生产代码改走 `Runtime::filesystem()->copyDir()`（不覆盖已存在文件），
> 原先的替身是无条件覆盖，与 webman 的 `copy_dir` 默认语义不一致，会让「安装幂等」测出假行为。

## TestState 关键 API（命名空间 `AetherUpload\Tests\Support`）

- `TestState::reset()` — setUp 时调用，恢复默认配置+清空全部状态+重置 basePath 为随机临时目录
- `TestState::set('a.b.c', $value)` / `get('a.b.c', $default)` — 读写嵌套配置
- 配置前缀常量：完整 key 为 `plugin.erikwang2013.aetherupload-webman.app.<属性>`，
  例如 `resource_maxsize` 写为 `TestState::set('plugin.erikwang2013.aetherupload-webman.app.groups.file.resource_maxsize', 100)`
- `TestState::$request = new Request([...inputs...], [...files...])` — 注入当前请求
- `TestState::$events` — `Webman\Event\Event::emit` 记录 `['name'=>, 'data'=>]`
- `TestState::$redisStrings` / `$redisStringExpire` — 秒传记录（一条记录一个 key，`setex` 写入）
- `TestState::$redisHash` / `$redisExpireCalls` — 旧版 hash 存储的残留，仅供 legacy 回退用例
- `resetRedis()` 清空以上四者；`resetConfigMapper()` 见下文 ConfigMapper 单例说明
- `TestState::$translationResources` / `$locale` — 记录值

**默认配置**（bootstrap 已装载）：`instant_completion=false`、`lax_mode=false`、
`root_dir=storage/app/aetherupload`、`resource_subdir_rule=month`、`chunk_size=1000000`、
`forbidden_extensions=[php,part,html,shtml,htm,shtm,xhtml,xml,js,jsp,asp,java,py,sh,bat,exe,dll,cgi,htaccess,reg,aspx,vbs]`、
组 `file`：`group_dir=file`、`resource_maxsize=104857600`、
`resource_extensions=[jpg,jpeg,png,gif,webp,bmp,svg,pdf,doc,docx,xls,xlsx,ppt,pptx,txt,zip,rar,7z,mp4,mp3,wav]`。

## 替身类（composer classmap 自动加载，勿重复定义）

- `Webman\Http\Request` — `new Request(array $inputs=[], array $files=[])`，方法
  `input($name,$default=null)` / `file($name)` / `all()`
- `support\Redis` — 内存字符串存储（秒传记录），返回值镜像 predis：`get`→?string（缺失为 null）、
  `exists`→1/0、`setex`→true（同时记录 TTL）、`del`→1/0、`expire`→true、`keys($glob)`→string[]
- `support\Redis` 的另一半 `hexists`/`hget`/`hdel` 支撑 legacy hash 回退用例
- `support\Translation::addResource(...)` — 记录参数，不加载文件
- `Webman\Event\Event::emit($name,$data)` — 记录到 `TestState::$events`
- `ResponseStub` — `$status`/`$headers`/`$body`/`$type`(`raw|json|file|download`)/
  `$filePath`/`$fileName`；断言用 `getStatusCode()`/`getHeaderLine($k)`/`getBody()`/`$type`

## 上传分块测试要点（UploadController）

- 文件对象只需提供 `isValid()` 与 `getRealPath()`，可 `new class {...}` 匿名类，
  或用 `FileObject` 式小助手类（若需要可自建 `tests/Support/` 下，命名空间 `AetherUpload\Tests\Support`）
- 分块文件落在 `base_path()/root_dir/group_dir/group_subdir/` 下；测试先 `mkdir -p`
- `ConfigMapper` 是**缓存配置的单例**（首次实例化时读一次 `config()`），改配置后必须调用
  `TestState::resetConfigMapper()`，否则被测代码读到的还是旧值：
  `TestState::reset(); TestState::normalizeConfig(); TestState::resetConfigMapper();`
  （`set()` 的 key 用完整点号路径 `plugin.erikwang2013.aetherupload-webman.app.<属性>`）
- 秒传/事件相关：`instant_completion=true` 时断言存储形态请走公开 API
  （`RedisSavedPath::get(RedisSavedPath::getKey($group, $hash))`）；具体 key 前缀/TTL/legacy
  回退的断言集中在 `tests/RedisSavedPathTest.php`，其它测试不要绑定存储细节

## 注意事项

- 不 mock 被测类自身（Resource/PartialResource 等用真实实例+临时文件系统）
- 涉及真实文件的操作统一用 `TestState::$basePath` 下的临时目录，`tearDown` 清理
- 断言错误消息：`trans()` 返回 key 本身，如 `assertSame('invalid_resource_size', $e->getMessage())`
- 上传相关的分块/请求脚手架直接复用 `tests/Support/UploadFixtures.php`（trait），别重写一遍
- `commands/` 下的类不在 composer autoload 里，需 `require_once`；且它们继承 Symfony Console 的
  `Command`，方法签名不兼容（如漏了 `execute(): int`）是**加载期 fatal**，会连带掀掉整个 PHPUnit 进程。
  测试命令类时先在子进程里 `require` 一次做可加载性兜底，见 `tests/CleanUpDirectoryCommandTest.php`
- 文件大小/`file_exists` 受 PHP stat 缓存影响：`fopen('ab')`+`fwrite` 或 `stream_copy_to_stream`
  之后同一进程内 `filesize()` 可能返回旧值。需要验证"读到真实大小"的场景，在写入前后显式
  `clearstatcache(true, $path)` 来构造缓存已被预热的确定性前置条件
