# AetherUpload 单元测试契约（Harness Contract）

本库是 webman 插件，运行在宿主 webman 应用中。单元测试不引入 webman/framework，
用测试替身隔离宿主框架依赖。**编写测试前必读本文档。**

## 运行

```bash
# 本机 PHP 已损坏(段错误)，必须用 Docker 运行：
docker run --rm -v $PWD:/app -w /app php:8.3-cli-alpine php vendor/bin/phpunit --no-coverage
```

PHPUnit 10.5，配置见 `phpunit.xml.dist`。测试文件放 `tests/` 根目录，类名 `*Test`，
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
| `copy_dir/remove_dir` | 真实递归复制/删除 |

## TestState 关键 API（命名空间 `AetherUpload\Tests\Support`）

- `TestState::reset()` — setUp 时调用，恢复默认配置+清空全部状态+重置 basePath 为随机临时目录
- `TestState::set('a.b.c', $value)` / `get('a.b.c', $default)` — 读写嵌套配置
- 配置前缀常量：完整 key 为 `plugin.erikwang2013.aetherupload-webman.app.<属性>`，
  例如 `resource_maxsize` 写为 `TestState::set('plugin.erikwang2013.aetherupload-webman.app.groups.file.resource_maxsize', 100)`
- `TestState::$request = new Request([...inputs...], [...files...])` — 注入当前请求
- `TestState::$events` — `Webman\Event\Event::emit` 记录 `['name'=>, 'data'=>]`
- `TestState::$redisHash` / `$redisExpireCalls` — Redis 桩存储（也可用 `resetRedis()`）
- `TestState::$translationResources` / `$locale` — 记录值

**默认配置**（bootstrap 已装载）：`instant_completion=false`、`lax_mode=false`、
`root_dir=storage/app/aetherupload`、`resource_subdir_rule=month`、`chunk_size=1000000`、
`forbidden_extensions=[php,part,html,shtml,htm,shtm,xhtml,xml,js,jsp,asp,java,py,sh,bat,exe,dll,cgi,htaccess,reg,aspx,vbs]`、
组 `file`：`group_dir=file`、`resource_maxsize=104857600`、
`resource_extensions=[jpg,jpeg,png,gif,webp,bmp,svg,pdf,doc,docx,xls,xlsx,ppt,pptx,txt,zip,rar,7z,mp4,mp3,wav]`。

## 替身类（composer classmap 自动加载，勿重复定义）

- `Webman\Http\Request` — `new Request(array $inputs=[], array $files=[])`，方法
  `input($name,$default=null)` / `file($name)` / `all()`
- `support\Redis` — 内存哈希，返回值镜像 predis：`hexists`→1/0、`hget`→?string、
  `hset`→1(新)/0(覆盖)、`hdel`→1/0、`del`→1、`expire`→true
- `support\Translation::addResource(...)` — 记录参数，不加载文件
- `Webman\Event\Event::emit($name,$data)` — 记录到 `TestState::$events`
- `ResponseStub` — `$status`/`$headers`/`$body`/`$type`(`raw|json|file|download`)/
  `$filePath`/`$fileName`；断言用 `getStatusCode()`/`getHeaderLine($k)`/`getBody()`/`$type`

## 上传分块测试要点（UploadController）

- 文件对象只需提供 `isValid()` 与 `getRealPath()`，可 `new class {...}` 匿名类，
  或用 `FileObject` 式小助手类（若需要可自建 `tests/Support/` 下，命名空间 `AetherUpload\Tests\Support`）
- 分块文件落在 `base_path()/root_dir/group_dir/group_subdir/` 下；测试先 `mkdir -p`
- `ConfigMapper` 为单例，`setUp` 里 `TestState::reset()` 后重新 `ConfigMapper::set(...)` 或
  直接 `TestState::set(...)` 均可生效（ConfigMapper 每次 get 走 config()）
- 秒传/事件相关：`instant_completion=true` 时 `RedisSavedPath::set` 写入后，
  `TestState::$redisHash['aetherupload_resource'][$key]` 可断言

## 注意事项

- 不 mock 被测类自身（Resource/PartialResource 等用真实实例+临时文件系统）
- 涉及真实文件的操作统一用 `TestState::$basePath` 下的临时目录，`tearDown` 清理
- 断言错误消息：`trans()` 返回 key 本身，如 `assertSame('invalid_resource_size', $e->getMessage())`
