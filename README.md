# AetherUpload-Webman  

> 官网 / Website: [https://package.erik.xyz/aetherupload/](https://package.erik.xyz/aetherupload/)

![以太兽 — AetherUpload 项目宠物](docs/js/aetherupload-pet.svg)

[中文](README.md) · [English](docs/i18n/README.en.md) · [한국어](docs/i18n/README.ko.md) · [Русский](docs/i18n/README.ru.md) · [Deutsch](docs/i18n/README.de.md) · [Français](docs/i18n/README.fr.md) · [Español](docs/i18n/README.es.md) · [Português](docs/i18n/README.pt.md) · [हिन्दी](docs/i18n/README.hi.md) · [العربية](docs/i18n/README.ar.md) · [বাংলা](docs/i18n/README.bn.md) · [Bahasa Indonesia](docs/i18n/README.id.md) · [日本語](docs/i18n/README.ja.md)

本项目移植自广受好评的 Laravel 大文件上传扩展包 [AetherUpload-Laravel](https://github.com/peinhu/AetherUpload-Laravel)，并为 [webman](https://www.workerman.net/webman) 的常驻进程模型重写了配置读取、并发处理与存储层。

**它解决什么**

浏览器直接上传大文件，绕不开三个难题：超过 `post_max_size` 就传不上去；网络一断就得从头再来；同样的文件被反复传输。AetherUpload 的做法是——在浏览器里把文件切片，逐块追加到服务端的一个临时文件，落盘时用文件内容的 md5 命名。于是**上传、断线续传、秒传、去重、完整性校验**共用同一套机制，全程不把整个文件读进内存，也不需要为「文件在哪」维护一张数据库表。

**它的形状**

一个 composer 包，**内核与宿主框架解耦**：同一份代码在 webman、原生 PHP（无框架）、Laravel、ThinkPHP、Symfony、Slim、Hyperf、Yii2、Yii3 下可用（见 [支持的框架](#支持的框架)）。宿主的配置、路由、控制台命令、语言文件与前端脚本由安装 / 发布命令分发；不依赖数据库；Redis 只在开启秒传时才需要，属可选依赖。

![示例页面](http://wx2.sinaimg.cn/mw690/69e23056gy1fho6ymepjlg20go0aknar.gif) 

# 项目结构

```text
aetherupload-webman/
├── src/                          插件源码
│   ├── Runtime.php                 宿主绑定点（静态门面）：进程级只持有不可变绑定，未绑定时明确报错
│   ├── RequestContext.php          每请求 / 每协程的可变状态（分组配置快照、已加载语种），常驻进程下并发请求不串组
│   ├── Contract/                   11 个接口（配置 / 翻译 / 请求 / 上传文件 / 响应 / Redis / 事件 / 路径 / 文件系统 / 上下文 / 适配器）
│   ├── Kernel/                     内核侧默认实现：AbstractAdapter、PrefixedConfig、Filesystem、NullRedis、PhpFileTranslator、ClientRedis…
│   ├── Console/                    三个命令的业务逻辑（Runner）+ 无控制台约定宿主的现成入口，九种命令壳共用同一份
│   ├── Adapter/                    九个适配器：Webman / Native / Laravel / ThinkPhp / Symfony / Slim / Hyperf / Yii / Yii3
│   ├── Storage/                    可选存储驱动（storage.driver = local | s3）：只管成品落地与读取，分块暂存不经这里
│   │   ├── StorageInterface.php    驱动接口：publish / exists / delete / url
│   │   ├── LocalStorage.php        默认驱动：行为与历史版本逐字节一致（url 恒 null）
│   │   ├── S3Storage.php           s3 驱动：配置校验 + 对象键构造，组装 S3Client（root_dir 不泄漏进桶）
│   │   ├── S3Client.php            内置最小 S3 客户端：SigV4 装配 + curl 传输 + 重试 + multipart 编排
│   │   ├── SignatureV4.php         纯函数 SigV4 签名（以 AWS 官方测试向量为正确性锚）
│   │   └── Storage.php             门面：按 storage.driver 现造驱动，不缓存
│   ├── UploadController.php        上传入口：preprocess（预处理 / 秒传判定）与 saveChunk（分块写入）
│   ├── ResourceController.php      展示与下载入口：display / download，支持交给 nginx 直发
│   ├── PartialResource.php         分块文件本体：路径拼装、逐块追加、重命名、大小与类型校验
│   ├── Header.php                  断点状态文件：只存一个 chunkIndex
│   ├── Resource.php                成品文件对象，作为参数注入「上传完成」事件
│   ├── RedisSavedPath.php          秒传索引：每条记录一个 key，独立 TTL
│   ├── SavedPathResolver.php       存储路径编解码（三段式无状态寻址）
│   ├── ConfigMapper.php            配置单例 + 每请求分组配置快照
│   ├── MimeType.php                mime ↔ 扩展名映射，落盘前按白名单复核
│   ├── Util.php                    临时名生成、路径安全校验、资源删除
│   ├── Install.php                 安装 / 卸载：向宿主应用分发配置与资源
│   ├── Responser.php               JSON 响应封装
│   ├── SimpleValidateTrait.php     极简必填校验
│   ├── ExamplePageTrait.php        示例页
│   ├── Pet.php                     项目宠物形态与上传状态映射（示例页使用）
│   └── helpers.php                 模板助手：aetherupload_display_link / aetherupload_download_link
├── commands/                     webman 的控制台壳（安装时复制到 app/command，逻辑在 src/Console）
│   ├── AetherUploadListGroups.php        php webman aetherupload:groups   列出分组并创建对应目录
│   ├── AetherUploadBuildRedisHashes.php  php webman aetherupload:build    按磁盘现状重建秒传索引
│   └── AetherUploadCleanUpDirectory.php  php webman aetherupload:clean N  按 mtime 清理过期临时文件
├── config/
│   ├── app.php                   webman 插件配置：分组、路由、中间件与各类开关
│   ├── aetherupload.php          框架无关的同一份配置，供其余八个适配器作基线（两条路径由 ConfigParityTest 锁死一致）
│   └── route.php                 四条路由 + 各自的中间件挂载点
├── docs/                         文档、图片与前端脚本
│   ├── aetherupload-architecture.svg  设计架构图
│   ├── aetherupload-design.svg        设计思路图
│   ├── aetherupload-lifecycle.svg     上传生命周期图
│   ├── HARNESS.md                     单元测试契约（编写测试前必读）
│   ├── REPORT.md                      某次全量测试与覆盖率的摘要报告
│   ├── i18n/                          多语言 README 与三张设计图的译文（12 种语言，译文导航见 TRANSLATING.md）
│   └── js/                            前端资源（发布到 <文档根>/vendor/aetherupload/js）
│       ├── aetherupload-all.js        打包版：核心 + zepto + spark-md5
│       ├── aetherupload-core.js       核心逻辑：算 hash、切片、上传、进度、断线重试
│       ├── aetherupload-pet.svg       项目宠物「以太兽」，同时用作示例页图标与站点图标
│       └── pet-expressions/           12 种宠物形态，示例页按上传状态切换
├── translations/{zh,en}/         多语言（发布到宿主的 translations 路径下 aetherupload/）
├── views/example.blade.php       示例页源码，接入时可直接参考
├── tests/
│   ├── *.php                     PHPUnit 单元用例（宿主用替身，PHP 8.0–8.4，PHPUnit 9.6 与 10.5 双版本）
│   └── Integration/<fw>/         端到端套件，每个框架一套：真装框架、真跑上传链路（webman 走 phpunit.xml 起真服务、另有 phpunit-s3.xml 的 S3 模式套件，其余八个走各自的 ci.sh）
├── uploads/                      遗留目录，插件运行时不使用
└── composer.json
```

> `src/` 根目录下的文件（控制器、分块、断点、秒传索引、路径编解码、错误响应、示例页与宠物形态映射…）都与框架无关：它们不引用任何宿主命名空间，配置 / 翻译 / 请求 / 响应 / Redis / 事件全部经 `Runtime` 取端口。
> 唯一的例外是 `Install.php` —— `composer require` 触发的安装脚本跑在宿主应用起来之前，`Runtime` 还没被绑上，它在那种情况下会兜底自绑 webman 适配器并回落到包内的 `config/app.php`。

> 安装前这些都是包内文件；`composer require` 之后由 `Install.php` 按上表标注的映射，把配置、命令、前端脚本与语言文件分发到宿主应用的对应位置。

# 设计架构

![AetherUpload-Webman 架构图](docs/aetherupload-architecture.svg)

- **客户端**：引入一个 `aetherupload-all.js` 即可（内含 zepto 与 spark-md5），它负责算 md5、切片、进度条与断线自动重试。
- **服务端**：`UploadController` 只有两个入口（预处理、分块写入）；`PartialResource` 管路径、追加与重命名，`Header` 只记 chunkIndex；配置经 `ConfigMapper` 按请求快照，避免常驻进程下并发请求互相串组。
- **存储与运维**：磁盘上永远只有三类文件——`*.part` 分块、`_header/` 断点、`<md5>.<ext>` 成品；Redis 仅在开启秒传时使用；开启 `x_accel_redirect` 后由 nginx 直发文件，顺带补上 Range 与断点下载。成品也可改落 S3 兼容对象存储（`storage.driver = s3`，见下文「可选对象存储（S3 兼容）」）。

# 设计思路

![AetherUpload-Webman 设计思路](docs/aetherupload-design.svg)

图中三条决策之外，还有一条独立的选择：**秒传索引做成可选依赖 + 每条记录独立 TTL**。不装 Redis 的站点照样能上传（只是秒传失效）；装了 Redis 的站点，每条记录用 `SETEX` 各自过期——既不共享过期时间，也不会因为持续上传而无限膨胀；升级后旧 hash 记录仍可回退读取。一致性交给 `aetherupload:build`（每日重建）与 `aetherupload:clean`（按 mtime 回收临时文件）。

# 上传生命周期

![AetherUpload-Webman 上传生命周期](docs/aetherupload-lifecycle.svg)

主路径只有四步：**预处理 → 分块（循环）→ 最后一块校验 → 落盘**。预处理建空的 `.part` 并把 `chunkIndex=0` 写进 header；每个分块的顺序固定为「校验 → 追加 → 回写 chunkIndex」；只有最后一块才会校验大小与 MIME、重算整份 md5、改名成 `<md5>.<ext>` 并写秒传索引。

两条旁路值得单独说明：

- **秒传命中**：预处理阶段发现相同 md5 已有成品，直接返回其路径，一个分块都不用传。
- **中断与恢复**：重发同一序号会被幂等跳过；序号跳变或分块被截断只返回错误，**不会清理** `.part` 与 header，所以弱网下客户端可以持续重试直到补上缺的那一块。真正会清理的只有两种情况——最后一块校验不通过（整份丢弃），以及页面关闭后由 cron 跑 `aetherupload:clean` 按 mtime 回收。

# 可选对象存储（S3 兼容）

成品文件默认落本地磁盘（`storage.driver = local`，与历史版本行为逐字节一致）；也可以切换为 S3 兼容对象存储——`endpoint / region / path_style` 三件配置适用于一切讲 S3 协议的存储，AWS 与各家公有云、MinIO / Ceph 等自托管通吃。

```php
// 本插件配置文件（webman：config/plugin/erikwang2013/aetherupload-webman/app.php）
'storage' => [
    'driver' => 's3',                     // local（默认）| s3
    's3' => [
        'endpoint'   => '',               // 留空 = AWS 默认（按 region 推导）；自托管填自家端点
        'region'     => 'us-east-1',
        'bucket'     => '',
        'access_key' => '',
        'secret_key' => '',
        'path_style' => true,             // MinIO / Ceph 等自托管必须 true；AWS 等公有云可用 false
        'prefix'     => '',               // 对象键前缀（可空）
        'multipart_threshold' => 104857600, // 超过此大小走 multipart（默认 100MB，分片固定 64MB）
        'payload_signing' => 'hash',       // 签名时 payload 哈希形态：hash（默认，签真实内容哈希）| unsigned（发 UNSIGNED-PAYLOAD；华为云 OBS 等只接受该形式的实现需要它）
    ],
],
```

- **零新增 composer 依赖**：内置最小 S3 客户端（SigV4 标准签名 + multipart 上传 + 302 预签名 URL），仅 S3 模式需要 `ext-curl`（composer `suggest` 里的软依赖）。
- **读路径**：`display` / `download` 返回 302 跳转到预签名 URL——`Content-Disposition` 与内联黑名单（如 svg 强制转为附件）语义照常保留。
- **保持本地语义**：分块暂存、`aetherupload:groups` / `build` / `clean` 仍作用于本地文件；`x_accel_redirect` 只对 local 驱动生效，s3 模式下不适用（302 预签名优先）。
- **厂商范围**：设计覆盖 AWS 与 40+ 家 S3 兼容厂商，逐家对照（含来源与置信度标注）见 [docs/S3-COMPATIBILITY.md](docs/S3-COMPATIBILITY.md)。注意：Azure Blob 原生不是 S3 协议（需经 S3 网关）；OpenStack Swift 需启用 s3api。华为云 OBS 等只接受 `UNSIGNED-PAYLOAD` 形式的实现，把 `storage.s3.payload_signing` 设为 `unsigned` 即可。
- **端到端验证**：`vendor/bin/phpunit -c tests/Integration/webman/phpunit-s3.xml`（需本机有 S3 兼容服务端；缺则相关用例如实跳过，不假装通过）。

# 功能特性
- [x] 百分比进度条  
- [x] 文件类型限制  
- [x] 文件大小限制  
- [x] 多语言支持  
- [x] 资源分组配置  
- [x] 上传完成事件   
- [x] 同步上传 *①*  
- [x] 断线续传 *②*  
- [x] 文件秒传 *③*  
- [x] 自定义中间件 *④*  
- [x] 自定义路由   
- [x] 宽松模式

*①：同步上传相比异步上传，在上传带宽足够大的情况下速度稍慢，但同步可在上传同时进行文件的拼合，而异步因文件块上传完成的先后顺序不确定，需要在所有文件块都完成时才能拼合，将会导致异步上传在接近完成时需等待较长时间。同步上传每次只有一个文件块在上传，在单位时间内占用服务器的内存较少，相比异步方式可支持更多人同时上传。*  

*②：断线续传和断点续传不同，断线续传是指遇到断网或无线网络不稳定时，在不关闭页面的情况下，上传组件会定时自动重试，一旦网络恢复，文件会从未上传成功的那个文件块开始继续上传。断线续传在刷新页面或关闭后重开是无法续传的，之前上传的部分已成为无效文件。*  

*③：文件秒传需服务端Redis和客户端浏览器支持(FileReader、File.slice())，两者缺一则秒传功能无法生效。默认关闭，需在配置文件中开启。*  

*④：结合自定义中间件，可对已上传资源的访问、下载行为进行权限控制。*


# 支持的框架

内核（分块、续传、秒传、校验、寻址）与宿主框架解耦，同一份包在下列宿主下可用，每个都有**真装框架、真跑完整上传链路**的端到端测试兜底：

| 宿主 | 接入方式 | 端到端测试 |
|---|---|---|
| webman | 原生支持（`composer require` 即自动分发配置/路由/命令/前端脚本） | `tests/Integration/webman/` |
| **原生 PHP（无框架）** | 一行 `Bootstrap::handle()`，路由由本包按配置分发 | `tests/Integration/native/` |
| Laravel | ServiceProvider + `vendor:publish` | `tests/Integration/laravel/` |
| ThinkPHP | `app/service.php` 里注册 Service | `tests/Integration/thinkphp/` |
| Symfony | Bundle + 路由资源导入 | `tests/Integration/symfony/` |
| Slim | 一行 `Bootstrap::create()` | `tests/Integration/slim/` |
| Hyperf | `ConfigProvider` | `tests/Integration/hyperf/` |
| Yii2 | 应用 `bootstrap` 里挂 Bootstrap | `tests/Integration/yii/` |
| Yii3 | 入口脚本一行 `Bootstrap::bind()` + 路由文件展开 `Bootstrap::routes()` | `tests/Integration/yii3/` |

> 包名里的 `webman` 是历史原因（本插件最初只支持 webman），不影响在其余宿主下使用。

## 通用两步

无论哪个宿主，装完包之后有两条**必做**动作（webman 由安装脚本自动完成，其余宿主需要手动跑对应命令）：

1. **建存储目录**：`aetherupload:groups` —— 创建 `root_dir`、`_header` 与各分组目录。
   **不建就一定失败**：内核的 `createGroupSubDir()` 是非递归 `mkdir`，父目录缺失时直接返回 false，而错误会被统一翻译成笼统的 `upload_error`，排查时很难看出是目录问题。
2. **发布文件**：`aetherupload:publish`（Laravel 用 `vendor:publish --tag=aetherupload-*`）—— 把语言文件与前端 `js` 放进宿主可访问的位置。

> 命令名的分隔符跟着各框架的控制台约定：webman / Laravel / ThinkPHP / Symfony / Hyperf / Slim / 原生 PHP 用 `:`，**Yii2 与 Yii3 用 `/`**（`php yii aetherupload/groups`）。下面每家的示例都是可直接复制运行的写法。

## 各框架接入

**原生 PHP（无框架）**

任何跑在 PHP 上的应用都能直接用 —— 不装框架、不装中间件、不注册服务，入口脚本一行：

```php
// public/index.php（FPM / Apache / nginx+php-fpm 的入口脚本）
require __DIR__ . '/../vendor/autoload.php';

exit(\AetherUpload\Adapter\Native\Bootstrap::handle([
    'base_path' => dirname(__DIR__),
    'config'    => require __DIR__ . '/../config/aetherupload.php',   // 省略则用包内默认值
    'redis'     => static fn () => new \Redis(),                       // 秒传需要，可选
]));
```

```bash
php bin/aetherupload aetherupload:groups     # 建目录
php bin/aetherupload aetherupload:publish    # 发布语言文件与前端 js
```

`bin/aetherupload` 也是三行，自己放一份即可：

```php
#!/usr/bin/env php
<?php
require __DIR__ . '/../vendor/autoload.php';
\AetherUpload\Adapter\Native\Bootstrap::bind(require __DIR__ . '/../config/aetherupload.php', dirname(__DIR__));
exit((new \AetherUpload\Console\Application())->run());
```

> **路由不用自己写**：`Bootstrap::handle()` 按配置里的 `route_preprocess` / `route_uploading` / `route_display` / `route_download` 分发当前请求，未命中 404、方法不对 405 并给出 `Allow`。
> **中间件**：配置里的 `middleware_*` 在这一宿主下是**无参可调用对象**，返回响应对象即短路 —— 权限不够直接 `return Runtime::response()->text('forbidden', 403);`，返回别的值一律无视并继续（四号脚注说的权限控制就是这么接）。
> **内置服务器**：`php -S 127.0.0.1:8080 -t public public/index.php` 即可。要让内置服务器自己发 `public/` 里的静态文件（发布出来的前端 js 走这条），在 `handle()` 之前加一句 `if (is_file(__DIR__ . parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH))) { return false; }`。
> **不用内置函数兜底**：请求读 `$_GET`/`$_POST`/`$_FILES`（PHP 不额外过滤，内核的类型守卫照常生效），响应由 `NativeResponse::send()` 一次性 `header()` + `echo` 发出，没有框架的中间层。

**Laravel**

```php
// bootstrap/providers.php（Laravel 11+）或 config/app.php 的 providers 数组
AetherUpload\Adapter\Laravel\AetherUploadServiceProvider::class,
```
```bash
php artisan vendor:publish --tag=aetherupload-config         # 发布 config/aetherupload.php
php artisan vendor:publish --tag=aetherupload-translations   # 发布语言文件
php artisan vendor:publish --tag=aetherupload-assets         # 发布前端 js
php artisan aetherupload:groups
```
> 本包 composer.json **不含** `extra.laravel.providers`（各框架依赖互斥，无法写死自动发现），因此必须手动注册 provider。
> 配置合并是**浅合并**：应用一旦发布了 `config/aetherupload.php`，其中的 `groups` 会**整体替换**插件默认值——新增分组时请把默认分组一并抄进去。

**ThinkPHP**

```php
// app/service.php
return [ \AetherUpload\Adapter\ThinkPhp\AetherUploadService::class ];
```
```bash
php think aetherupload:groups
php think aetherupload:publish
```
> 同样注意 `groups` 是整体替换；另外 `config/route.php` 与 `config/lang.php` 是**必需文件**，缺失会让框架在 `array_merge`/`array_change_key_case` 处收 null 并抛 TypeError。

**Symfony**

```php
// config/bundles.php
AetherUpload\Adapter\Symfony\AetherUploadBundle::class => ['all' => true],
```
```yaml
# config/routes.yaml（Symfony 的 bundle 路由必须由应用侧 import，没有自动加载机制）
aetherupload:
    resource: '@AetherUploadBundle/Resources/config/routes.php'
    type: php
```
> `Configuration` 树声明了全部配置键，未声明的键会让容器启动失败（不是静默忽略）。

**Slim**

```php
// public/index.php
$app = \AetherUpload\Adapter\Slim\Bootstrap::create([
    'config'    => require __DIR__ . '/../config/aetherupload.php',   // 省略则用包内默认值
    'base_path' => dirname(__DIR__),
    'redis'     => static fn () => new Predis\Client(['database' => 0]),   // 秒传需要
]);
$app->run();
```
> Slim 的 PSR-7 响应不可变，内核产物由 `Bootstrap::handler()` 那层落成真 PSR-7（**唯一的转换点**，放中间件里是穿不过去的）。
> Slim 没有控制台约定，本包给出四条命令的现成入口类，入口脚本要自己放一份（四行）：
> ```php
> // bin/aetherupload
> require __DIR__ . '/../vendor/autoload.php';
> \AetherUpload\Adapter\Slim\Bootstrap::bind(require __DIR__ . '/../config/aetherupload.php', dirname(__DIR__));
> exit((new \AetherUpload\Adapter\Slim\Console\Application())->run());
> ```
> ```bash
> php bin/aetherupload aetherupload:groups     # 另有 publish / build / clean
> ```
> 需要 `symfony/console`（本包 require-dev / suggest）。

**Hyperf**

```php
// config/config.php —— 本包未写 extra.hyperf.config（会与其它框架的自动发现机制相互干扰，
// 待独立验过后再补），因此在这里显式展开 ConfigProvider（路由、命令、publish 键都由它给出）
return [
    // …
] + (new \AetherUpload\Adapter\Hyperf\ConfigProvider())();
```
```bash
php bin/hyperf.php aetherupload:groups
php bin/hyperf.php aetherupload:publish
```
> 需要 `ext-swoole`。另外两个开发期注意点：`xdebug.mode=profile` 会让 Hyperf 冷启动**静默退出**（`ClassLoader::init()` 里 exit 255，无任何 PHP 报错），CI 与本地请设 `XDEBUG_MODE=off`；swoole 的 `Coroutine\run()` 不能与 PHPUnit 同进程，协程相关测试要把探针放到独立进程里跑。

**Yii2**

```php
// 应用配置
return [
    'bootstrap' => ['aetherupload' => \AetherUpload\Adapter\Yii\Bootstrap::class],
    'params'    => ['aetherupload' => require __DIR__ . '/aetherupload.php'],   // 可基于 publish 生成的样例
    'components' => [
        // 前置条件：必须写在 components 下。顶层同名键会被 Yii 忽略，
        // 那样 enableStrictParsing 不生效，路由的 verb 约束会被绕过（GET 打到只声明 POST 的动作）
        'urlManager' => ['enableStrictParsing' => true],
        // 秒传需要；控制台应用也要配，否则 aetherupload/build 会报 Unknown component ID: redis
        'redis' => [ /* … */ ],
    ],
];
```
```bash
php yii aetherupload/groups     # 注意：Yii 的控制台分隔符是 "/"，写成 aetherupload:groups 会报 Unknown command
php yii aetherupload/publish
```
> 发布落点是**文档根**下的 `vendor/aetherupload/js`（webman 的文档根是 `public/`，Yii 的是 `web/`）：示例页引用的是绝对路径 `/vendor/aetherupload/js/…`，语义是"文档根"，所以 `YiiPaths::assetPath()` 优先取宿主的 `@webroot` 别名，取不到时退回 `<app>/web`。

**Yii3**

Yii3（yiisoft/* 那一代）与 Yii2 是两套东西：PSR-7 / PSR-15 / PSR-11 容器 + `yiisoft/config` 配置组，没有全局函数、也没有 `Yii::$app`。接入要动三个文件：

```php
// 1) public/index.php —— 绑定必须早于 runner（runner 会构建容器、跑 bootstrap）
require __DIR__ . '/../vendor/autoload.php';

\AetherUpload\Adapter\Yii3\Bootstrap::bind(
    require __DIR__ . '/../config/aetherupload.php',   // 由 aetherupload/publish 生成
    $root = dirname(__DIR__),
    ['redis' => static fn () => new \Predis\Client(['database' => 0])]   // 秒传需要，可选
);

(new \Yiisoft\Yii\Runner\Http\HttpApplicationRunner($root))->run();
```

```php
// 2) config/common/routes.php —— 四条业务路由（配置显式传入，见下）
return [
    // …宿主自己的路由…
    ...\AetherUpload\Adapter\Yii3\Bootstrap::routes(require __DIR__ . '/aetherupload.php'),
];
```

```php
// 3) config/common/params.php —— 控制台命令
'yiisoft/yii-console' => ['commands' => \AetherUpload\Adapter\Yii3\Bootstrap::consoleCommands()],
```

```bash
php yii aetherupload/groups     # 同样注意：Yii3 的控制台分隔符也是 "/"
php yii aetherupload/publish
```

> **不需要改中间件栈**：四条路由的 action 直接收 `ServerRequestInterface`（yiisoft/middleware-dispatcher 按类型注入），适配器在 action 内登记与清理当前请求。
> `Bootstrap::routes()` 的配置是**显式入参**：路由文件在容器构建期就被 `require`，那一刻 `Runtime` 还没绑定，读 `Runtime::config()` 必然抛「尚未绑定宿主适配器」。
> 控制台入口（`yii`）同样先 `Bootstrap::bind()`；命令表交给 `yiisoft/yii-console` 的 params。
> 发布落点：`resource/translations/aetherupload`（语言文件）与 `public/vendor/aetherupload/js`（前端资源）—— Yii3 应用模板的文档根就是 `public/`。
> 配置里的 `middleware_*` 在 Yii3 下是 yiisoft/router 认识的形态（PSR-15 中间件类名、实例，或 `function ($request, $handler)` 可调用对象），挂到对应路由上。

**webman**

```bash
# 在 webman 项目根目录执行
composer require erikwang2013/aetherupload-webman
```
> webman 是唯一**零配置**的宿主：`composer require` 时由 `Install.php` 自动分发配置、路由、命令、语言文件与前端脚本，并建好存储目录。装完直接访问 `http://域名/aetherupload` 即为示例页。
>
> 提示：更改相关配置选项请编辑 `config/plugin/erikwang2013/aetherupload-webman/app.php`。

> 其余八个宿主必须先注册适配器，再跑「通用两步」。**webman 也提供同样的命令**（`php webman aetherupload:groups` / `aetherupload:publish`），只是安装时已自动跑过一遍。

# 使用  
**文件上传**  

参考示例文件及注释，在需要上传大文件的页面引入相应文件及代码。

**分组配置**  

在本插件配置文件的`groups`下新增分组，运行`php webman aetherupload:groups`自动创建对应目录。  
```php
'video' => [
    'group_dir'                    => 'video',
    'resource_maxsize'             => 0,
    'resource_extensions'          => [],
    'event_before_upload_complete' => false, 
    'event_upload_complete'        => false,
],
```
在前端通过调用`setGroup('分组名')`方法指定上传分组，注意分组名必须已经存在，且**不能包含下划线**（它参与存储路径编码，含下划线会导致该分组下的资源无法被定位，配置与上传阶段即会失败）。

**添加秒传功能（需Redis及浏览器支持）**  

参考Webman文档Redis部分，安装所需依赖。  
安装Redis并启动服务。  
安装predis包`composer require predis/predis`。  
在`config/redis.php`中设置`client`为`predis`。  
在本插件配置文件中将`instant_completion`设置为`true`。

*提示：在Redis中维护了一份与实际资源文件对应的秒传清单，实际资源文件的增删造成的变化均需要同步到秒传清单中，否则会产生脏数据。  
扩展包已包含新增部分，当需要删除资源文件时，使用者需手动调用对应方法删除文件及秒传清单中的记录。* 
```php
\AetherUpload\Util::deleteResource($savedPath); //删除对应的资源文件
\AetherUpload\Util::deleteRedisSavedPath($savedPath); //删除对应的Redis秒传记录
``` 
*两者均为幂等操作：文件或秒传记录已不存在时同样返回true。* 

**自定义中间件**  

参考Webman文档路由中间件部分，创建你的中间件并将你编写的中间件名称填入配置文件对应部分。  
```php
'middleware_download' => [app\middleware\MiddlewareA::class,app\middleware\MiddlewareB::class],
```  
可使用此功能对文件进行上传、访问、下载行为的权限控制。

**自定义路由**  

在本插件配置文件下编辑`'route_uploading' => '/aetherupload/uploading'`等选项，在前端调用`setUploadingRoute('/aetherupload/uploading')`等方法。  
文件访问、下载路由编辑后，可直接访问，无需调用前端方法。
 
**上传完成事件**  

分为上传完成前、上传完成后事件，参考Webman文档常用组件Event事件部分。  
在`config/event.php`中为`aetherupload.before_upload_complete`及`aetherupload.upload_complete`配置对应的事件处理类。  
在本插件配置文件中将`groups`下相应选项设置为`true`。 
```php
'event_before_upload_complete' => true, 
'event_upload_complete'        => true,
```
可使用此功能在上传完成前、上传完成后进行额外处理。

**宽松模式**  

在本插件配置文件下编辑`'lax_mode' => true,`，在前端调用`setLaxMode(true)`方法。  
通过上传前跳过计算hash，可缩短总耗时。此选项开启后，无法进行秒传和完整性校验。

**多语言**  

前端检测浏览器语言后自动设置，目前支持中、英。
  
**使用方便的控制台命令**  

`php webman aetherupload:groups` 列出所有分组并自动创建对应目录  
`php webman aetherupload:build` 在Redis中重建资源文件的秒传清单  
`php webman aetherupload:clean 2` 清除2天前的无效临时文件  

# 优化建议
* **（推荐）设置每天自动清除无效的临时文件**  
由于上传流程存在意外终止的情况，如在传输过程中强行关闭页面或浏览器，将会导致已产生的文件部分成为无效文件，占据大量的存储空间，我们可以使用crontab的定时任务功能来定期清除它们。  
在Linux中运行`crontab -e`命令，确保文件中包含这行代码：  
```php
0 0 * * * php /项目根目录的绝对路径/webman aetherupload:clean 1> /dev/null 2>&1  
```  

* **设置每天自动重建Redis中的秒传清单**  
不恰当的处理和某些极端情况可能使秒传清单中出现脏数据，从而影响到秒传功能的准确性，重建秒传清单可消除脏数据，恢复与实际资源文件的同步。  
在Linux中运行`crontab -e`命令，确保文件中包含这行代码：  
```php
0 0 * * * php /项目根目录的绝对路径/webman aetherupload:build 1> /dev/null 2>&1  
```  

* **（可选）启用nginx内部重定向，支持大文件断点续传与视频拖动**  
webman的文件响应不实现HTTP Range，大文件下载会整包下发，视频无法拖动进度条，且整个传输期间占用一个worker进程。若部署在nginx下，可开启本插件的`x_accel_redirect`选项，文件改由nginx直接发送，worker立即释放，并支持Range（断点续传、视频拖动）。  
在本插件配置文件下编辑`'x_accel_redirect' => true,`，并在nginx配置中为内部前缀添加location，`alias`指向项目上传根目录的绝对路径（注意结尾的斜杠）：  
```nginx
location /internal-aetherupload/ {
    internal;
    alias /项目根目录的绝对路径/storage/app/aetherupload/;
}
```
该location必须保留`internal`，防止外部绕过程序直接访问资源文件；修改`root_dir`后`alias`需同步调整。  

* **提高分块临时文件读写速度（仅对PHP生效）**  
利用Linux的tmpfs文件系统，来达到将上传的分块临时文件放到内存中快速读写的目的，通过以空间换时间，提升读写效率，将会**额外占用**部分内存（约1个分块大小）。  
将php.ini中上传临时目录`upload_tmp_dir`项的值设置为`"/dev/shm"`，重启服务。  

* **提高分块临时文件读写速度（对系统临时目录生效）**  
利用Linux的tmpfs文件系统，来达到将上传的分块临时文件放到内存中快速读写的目的，通过以空间换时间，提升读写效率，将会**额外占用**部分内存（约1个分块大小）。  
执行以下命令：    
`mkdir /dev/shm/tmp`  
`chmod 1777 /dev/shm/tmp`  
`mount --bind /dev/shm/tmp /tmp`  

# 兼容性

| 功能 | IE | Edge | Firefox | Chrome | Safari |
|:---:|:---:|:---:|:---:|:---:|:---:|
| 上传 | 10+ | 12+ | 3.6+ | 6+ | 5.1+ |
| 秒传 | 10+ | 12+ | 3.6+ | 6+ | 6+ |

# 安全性
AetherUpload在上传前使用白名单+黑名单的形式进行文件后缀名过滤，上传后再检查文件的Mime-Type类型。白名单直接限制了保存文件扩展名，黑名单默认屏蔽了常见的可执行文件扩展名，来阻止上传恶意文件，安全起见白名单一栏不应留空。  

虽然做了诸多安全工作，但恶意文件上传是防不胜防的，建议正确设置上传目录权限，确保相关程序对资源文件没有执行权限。

## 项目宠物表情形态 · 以太兽

| 表情 | 表情 |
|:---:|:---:|
| ![笑](docs/js/pet-expressions/smile.svg) **笑** `:emoji-smile:` | ![欢乐](docs/js/pet-expressions/joy.svg) **欢乐** `:emoji-joy:` |
| ![哭](docs/js/pet-expressions/cry.svg) **哭** `:emoji-cry:` | ![沮丧](docs/js/pet-expressions/depressed.svg) **沮丧** `:emoji-depressed:` |
| ![惊恐](docs/js/pet-expressions/terrified.svg) **惊恐** `:emoji-terrified:` | ![愤怒](docs/js/pet-expressions/angry.svg) **愤怒** `:emoji-angry:` |
| ![撒娇](docs/js/pet-expressions/coquettish.svg) **撒娇** `:emoji-coquettish:` | ![卖萌](docs/js/pet-expressions/cute.svg) **卖萌** `:emoji-cute:` |
| ![讨好](docs/js/pet-expressions/pleasing.svg) **讨好** `:emoji-pleasing:` | ![敲打](docs/js/pet-expressions/knocking.svg) **敲打** `:emoji-knocking:` |
| ![捂脸](docs/js/pet-expressions/facepalm.svg) **捂脸** `:emoji-facepalm:` | ![吐舌头](docs/js/pet-expressions/tongue.svg) **吐舌头** `:emoji-tongue:` |

> 12 种彩色动态形态：笑 / 欢乐 / 哭 / 沮丧 / 惊恐 / 愤怒 / 撒娇 / 卖萌 / 讨好 / 敲打 / 捂脸 / 吐舌头。每个 SVG 自带表情、情绪特效与整体动效，并遵循 `prefers-reduced-motion`。

示例页把形态接入为上传状态指示器：待机、计算哈希、上传中、重试、秒传成功、上传成功、上传失败会切换对应形态；点击宠物可循环预览全部 12 种。资源随 `docs/js` 一起发布到 `<文档根>/vendor/aetherupload/js/pet-expressions/`。

代码侧可直接使用 `\AetherUpload\Pet`：`Pet::url('smile')` 返回发布后的资源 URL，`Pet::expressionForState('uploading')` 返回状态对应的形态，`Pet::names()` / `Pet::expressions()` 列出全部形态。

## 视觉资产授权 / Visual Assets License

本项目的项目宠物、图片、图标、表情（包括 `docs/js/pet-expressions/` 等目录下的
SVG / PNG）**不是开源资产**，版权归作者所有。未经书面许可不得复制、修改、再分发或
用于商业用途；商业使用需取得商业授权，联系 **erik@erik.xyz**。详见
[ASSETS-LICENSE.md](ASSETS-LICENSE.md)。

> 代码部分仍遵循仓库原有 `LICENSE`；第三方素材（如明确标注的 Go Gopher 等）仍遵循其原许可证。

