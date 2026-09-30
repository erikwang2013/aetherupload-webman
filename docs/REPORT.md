# AetherUpload 测试报告

**日期**: 2026-09-25
**运行环境**: PHP 8.0 / 8.3 / 8.4（php:*-cli-alpine, Docker）· PHPUnit 9.6（PHP 8.0 上 composer 解析到的版本）与 10.5（8.1+）· pcov 1.0.12 覆盖率驱动
**测试命令**:
```bash
vendor/bin/phpunit --no-coverage                      # 单元套件

# 其它 PHP 版本（本机 PHP 二进制不可靠时一律用 Docker）：
docker run --rm -v $PWD:/app -w /app php:8.0-cli-alpine sh -c \
  "php -r \"copy('https://getcomposer.org/composer-stable.phar','/tmp/c.phar');\" && \
   php /tmp/c.phar update --no-interaction --prefer-dist -q && php vendor/bin/phpunit --no-coverage"

# 带覆盖率。注意：本镜像里 `docker-php-ext-enable pcov` 会失败（它扫不到 pecl 装进去的 pcov.so），
# 且 pecl.php.net 常返回 "No releases available"，所以直接从源码构建：
docker run --rm -v $PWD:/app -w /app php:8.3-cli-alpine sh -c '
  apk add --no-cache $PHPIZE_DEPS
  wget -qO- https://github.com/krakjoe/pcov/archive/refs/tags/v1.0.12.tar.gz | tar xz -C /tmp
  cd /tmp/pcov-1.0.12 && phpize && ./configure --enable-pcov && make -s && make install
  printf "extension=pcov.so\npcov.enabled=1\npcov.directory=/app/src\n" > /usr/local/etc/php/conf.d/zz-pcov.ini
  cd /app && php vendor/bin/phpunit --coverage-text --coverage-clover /app/clover.xml'
```

---

# 一、单元套件（测试替身，不加载任何框架）

## 测试结果

| 指标 | 数值 |
|---|---|
| 测试总数 | **237** |
| 断言总数 | **660** |
| 失败 / 错误 | 0 |
| 警告 | 0 |

实测两端的 PHP 版本（**PHP 8.0 与 8.4 各跑一遍全绿**，PHP 8.0 用 PHPUnit 9.6、8.4 用 10.5）；
CI 的 `phpunit` job 跑 8.0 / 8.1 / 8.2 / 8.3 / 8.4 五版矩阵，外加 `php -l` 逐文件语法检查。

## 代码覆盖率

`phpunit.xml.dist` 的 `<source>` 覆盖整个 `src/`，但单元套件**只加载 webman 一个适配器**
（`composer.json` 不 require 任何框架），其余八个适配器在单测里必然是 0 —— 把它们混进一个数字
没有意义，因此按两栏看：

| 范围 | 行覆盖率（PHP 8.3 + PHPUnit 10.5 + pcov） |
|---|---|
| **单测可及**：`src/` 根目录 15 个文件 + `Contract/` + `Kernel/` + `Console/` + `Adapter/Webman/` | **81.68% (700/857)** |
| 八个非 webman 适配器：`Adapter/{Native,Laravel,ThinkPhp,Symfony,Slim,Hyperf,Yii,Yii3}/` | 0%（单测不加载）→ 由各自端到端套件覆盖，见下文 |

| 模块 | 行 | 模块 | 行 |
|---|---|---|---|
| ExamplePageTrait | 100% | PartialResource | 92% |
| Header | 95% | RedisSavedPath | 96.88% |
| Install | 91.18% | Resource | 100% |
| ConfigMapper | 94% | ResourceController | 100% |
| UploadController | 100% | Responser | 100% |
| Util | 95.35% | SavedPathResolver | 100% |
| MimeType | 100% | SimpleValidateTrait | 100% |
| helpers.php | 50% | Runtime | 86.67% |
| Kernel/Filesystem | 82.76% | Kernel/PrefixedConfig | 100% |
| Kernel/AbstractAdapter | 40% | Kernel/UploadedFile | 75% |
| Kernel/PhpFileTranslator | 100% | Kernel/BasePaths | 100% |
| Kernel/ClientRedis | 80% | Kernel/ArrayContextStore | 100% |
| Console/BuildRedisHashesRunner | 88.89% | Console/CleanUpDirectoryRunner | 82.35% |
| **Console/ListGroupsRunner** | **0%** | **Console/Application + RunnerCommand** | **0%** |
| Adapter/Webman/* | 66.67–100% | | |

> 本轮把 Slim 与原生 PHP 共用的三个端口（`PhpFileTranslator` / `ClientRedis` / `BasePaths`）与
> 控制台入口（`Console/Application` + `RunnerCommand`）搬到了内核侧，因此**分母从 777 行涨到 857 行**。
> 前三个已由新增的 `tests/SharedPortsTest.php` 盖住；后两个是 Symfony Console 的命令壳，
> 由各适配器的端到端套件真跑（原生 PHP 与 Slim 的 `ci.sh` 都会实际执行 `aetherupload:groups` 等命令）。

## 未覆盖路径与原因

1. **`Console/ListGroupsRunner` 整类未覆盖**（0/34）：它的行为是「读 `groups` 配置 → 建目录 → 打印」，
   单测里三条路径都有更直接的覆盖（`ConfigMapperGroupNameTest`、`InstallRootDirTest`），
   整条命令只在端到端套件（各框架 `aetherupload:groups`）里被真跑。这是一个已知缺口，不是有意的豁免。
2. **`Kernel/NullRedis`（0/12）、`NullEventDispatcher`（0/1）**：不装 Redis 的宿主用的空实现，
   单测里 webman 适配器绑的是真实的 `support\Redis` 桩。要覆盖得专门给「无 Redis 宿主」造一组用例。
3. **`Kernel/AbstractAdapter`（2/5）**：默认实现被各适配器覆写，基类自身只走到少数行。
3b. **`Kernel/Psr7{Response,Request,UploadedFile}` 与 `Kernel/Psr14Events`（第九个适配器一轮新增）**：
   它们是 Slim 与 Yii3 共用的 PSR-7 / PSR-14 胶水。根 `composer.json` 不 require `psr/http-message`
   （不该为一个内核侧共享件把框架侧依赖引进来），单测加载不到它们 —— 由两条端到端套件真跑覆盖
   （Slim 18 用例 + Yii3 7 用例）。
4. **`helpers.php`（2/4）**：`function_exists` 守卫的假分支不可达。
5. **`RedisSavedPath::exists/set` 的异常分支**、**`PartialResource` 的 IO 失败路径**、
   **`ConfigMapper::set` 的未知属性守卫**：真实文件系统 / predis 契约下不可达，属防御性代码。
6. **八个非 webman 适配器**：单测里 0%，因为它们的框架依赖根本不在 `require` 里。
7. **`Console/Application`（0/40）与 `Console/RunnerCommand`（0/13）**：Symfony Console 的命令壳。
   单测里刻意不加载它们（命令类方法签名不兼容是**加载期 fatal**，会掀掉整个 PHPUnit 进程，见 `docs/HARNESS.md`），
   改由各适配器的端到端套件真跑 —— 原生 PHP 与 Slim 的 `ci.sh` 都会执行 `list` / `groups` / `publish` / `clean` / `build` 五条命令。

---

# 二、端到端套件（真装框架，真跑上传链路）

| 框架 | 入口 | 用例 |
|---|---|---|
| webman | `vendor/bin/phpunit -c tests/Integration/webman/phpunit.xml`（起真 webman 服务，curl 打真 HTTP） | 4 条共享流程 |
| 原生 PHP | `bash tests/Integration/native/ci.sh`（无框架可装：临时应用三个文件 + `php -S` + curl） | 4 + 4 |
| Laravel | `bash tests/Integration/laravel/ci.sh`（orchestra/testbench 起真宿主） | 4 + 2 |
| ThinkPHP | `bash tests/Integration/thinkphp/ci.sh` | 4 + 2 |
| Symfony | `bash tests/Integration/symfony/ci.sh` | 4 + 12 |
| Slim | `bash tests/Integration/slim/ci.sh` | 4 + 14 |
| Hyperf | `bash tests/Integration/hyperf/ci.sh`（需 ext-swoole） | 4 + 3 |
| Yii2 | `bash tests/Integration/yii/ci.sh` | 4 + 4 |
| Yii3 | `bash tests/Integration/yii3/ci.sh`（真装 yiisoft/* 骨架 + 真 HttpApplicationRunner；另有 `php -S` + curl 冒烟） | 4 + 3 |

「4 条共享流程」来自 `tests/Integration/FlowAssertions.php`，九个宿主跑的是**同一份断言**：

1. `preprocess → 3 个分块 → 完成` 全链路，含 `.part` / `_header` 的中间态检查与最终 `savedPath` 格式
2. 错误路径：错误 hash、`resource_name[]=1` 这类类型越界输入不返回 500
3. 事件探针：监听器抛异常**不得**冒泡，响应仍为 200 且记录型监听器仍被调用
4. 秒传：`instant_completion=true` 重传同 hash 直接回 `savedPath`，不建新 `.part`

外加 `display` / `download` 的**逐字节相等**与 `X-Content-Type-Options`、`Content-Disposition` 校验。

各适配器 harness 用**各自专属的 redis 库**（webman 7 · laravel 4 · thinkphp 5 · hyperf 3 · slim 6 · symfony 8 · yii 9 · native 10 · yii3 11）：
它们会清理自己的 `aetherupload:*` 键，共库时并行跑会让秒传用例偶发失败。

> 端到端套件不进单测计数：主 `phpunit.xml.dist` 已 `<exclude>tests/Integration</exclude>`，
> CI 里每个适配器一条独立 job（PHP 8.2；各框架的当前版本都要求 ≥ 8.1，Yii3 的应用模板要求 8.2+，原生 PHP 那条不需要装任何框架）。

---

# 三、本轮（框架无关内核 + 八个适配器）修掉的具体缺陷

详见 `git log`，每条都有对应回归用例。

| 缺陷 | 影响 | 回归用例 |
|---|---|---|
| 宽松模式 100% 失败：空 `resource_hash` 未过路径校验 → `invalid_operation` | P0，功能整条失效 | `LaxModeUploadTest` |
| `aetherupload:clean` 按文件名前缀解析时间戳（临时名改成随机 hex 后失效）| P0，实测 1856/20000 个活跃文件被判"旧"删除 → 改用 `filemtime()` | `CleanUpDirectoryCommandTest` |
| `aetherupload:build` 先清空索引再扫描，遇 `.DS_Store` 之类垃圾文件即中止 | P0，秒传索引被清空 | `BuildRedisHashesCommandTest` |
| 三个控制台命令在 symfony/console 7 下缺 `execute(): int` | 加载即致命错误 | 子进程加载栅栏 |
| `ConfigMapper` 单例在常驻进程 / 协程下跨请求串组 | 并发请求读到别人的分组配置 | `RequestIsolationTest` |
| `Install` 忽略 `root_dir`；未绑定适配器时 `install()` / `uninstall()` 抛「尚未绑定宿主适配器」 | 自定义根目录建错位置；`composer require` 与 `composer remove` 都失败 | `InstallRootDirTest` / `InstallFreshAppTest` |
| `X-Accel-Redirect` 重复包含 `root_dir` | nginx 直发 404 | `XAccelRedirectTest` |
| `PartialResource::createGroupSubDir()` 的 `is_dir` → `mkdir` TOCTOU | 并发下目录创建竞态 | 协程级复现（Hyperf） |

---

# 四、测试文件清单（tests/，29 个文件，命名空间 `AetherUpload\Tests`）

`UploadControllerTest(31)`、`PartialResourceTest(29)`、`UtilTest(21)`、`RedisSavedPathTest(20)`、
`MimeTypeTest(12)`、`ResourceControllerTest(11)`、`SavedPathResolverTest(11)`、`XAccelRedirectTest(10)`、
`HeaderTest(9)`、`SimpleValidateTraitTest(9)`、`ConfigMapperTest(8)`、`InputTypeBoundaryTest(6)`、
`ResourceTest(5)`、`ConfigMapperGroupNameTest(4)`、`GroupSubDirValidationTest(4)`、`HelpersTest(4)`、
`InstallRootDirTest(4)`、`LaxModeUploadTest(4)`、`ResponserTest(4)`、`ConfigParityTest(3)`、
`ExamplePageTraitTest(3)`、`InstallFreshAppTest(3)`、`InstallTest(3)`、`PartialResourceCheckSizeTest(3)`、
`RequestIsolationTest(3)`、`BuildRedisHashesCommandTest(2)`、`CleanUpDirectoryCommandTest(2)`、
`UploadResumeStateTest(2)`、`SharedPortsTest(7)`

共享脚手架：`tests/Support/{TestState,ResponseStub,UploadFixtures}.php` 与 `tests/Stubs/`（`Webman\Http\Request`、
`support\Redis`、`support\Translation`、`Webman\Event\Event` 的替身）。契约见 `docs/HARNESS.md`。

---

# 五、产物

```bash
docker run ... php vendor/bin/phpunit \
  --coverage-text --coverage-html docs/report/coverage --log-junit docs/report/junit.xml
```

- `docs/report/junit.xml` — JUnit XML（CI 集成用，**.gitignore 已忽略**）
- `docs/report/coverage/` — HTML 覆盖率报告（浏览器打开 index.html，同样忽略）
- `docs/REPORT.md` — 本摘要（入库）

---

# 六、第九个适配器（Yii3）一轮的产物与证据

Yii3（yiisoft/* 那一代）与仓库里的 Yii2 适配器没有共用代码：PSR-7 / PSR-15 / PSR-11 容器 +
`yiisoft/config` 配置组，没有全局函数、也没有 `Yii::$app`。本轮新增：

- `src/Adapter/Yii3/`：`Yii3Adapter`（显式配置 + 每请求 PSR-7 请求即上下文身份）、`Bootstrap`
  （`bind()` / `routes()` / `consoleCommands()`）、`AetherUploadController`（四条路由动作，
  内核产物在此**唯一一次**落成真 PSR-7）、四个端口子类与四个控制台命令壳。
- 内核侧复用：`Kernel/Psr7Response`、`Kernel/Psr7Request`、`Kernel/Psr7UploadedFile`、`Kernel/Event` +
  `Kernel/Psr14Events`（Slim 侧保留同名子类，行为一字未改）。
- `tests/Integration/yii3/`（真装骨架 + 真 runner + 真 redis + `php -S` 冒烟）、CI 新增 `integration-yii3` job。

证据（本机 PHP 8.5.6 / PHPUnit 10.5.64）：

| 验证 | 命令 | 结果 |
|---|---|---|
| 内核搬迁回归闸门 | `vendor/bin/phpunit -c tests/Integration/slim/phpunit.xml` | 搬迁前 `OK (18 tests, 95 assertions)`，搬迁后**同一条命令同样的数字** |
| 单元套件 | `vendor/bin/phpunit --no-coverage` | `238 tests, 666 assertions`（与搬迁前一致；3 条 deprecation 是 PHP 8.5 对测试里 `ReflectionProperty::setAccessible()` 的提示，非本轮引入） |
| Yii3 端到端 | `bash tests/Integration/yii3/ci.sh` | `OK (7 tests, 67 assertions)`；404 / 405 + `Allow` / 宿主自有路由 / 四条控制台命令 / 真 HTTP 冒烟均实测 |
| 无 redis 时的诚实降级 | `AETHERA_E2E_REDIS=0 bash tests/Integration/yii3/ci.sh` | 秒传那条 `Skipped`，`aetherupload/build` 断言走反向分支（必须报错而不是假装成功） |

新增的「上传对象不产生拷贝」断言是**反例验证过**的：把 `Kernel/Psr7UploadedFile::getRealPath()` 的
「本地 uri 原样使用」分支短路掉，探针记录的副本数立刻从 0 变成 1，用例转红；恢复后转绿。

---

# 七、S3 兼容存储支持一轮（2026-10-01）

**特性**：内核新增可切换存储驱动 `storage.driver = local | s3`。默认 `local` 行为逐字节不变；
`s3` 驱动为**零 composer 新依赖**的内置最小 S3 客户端（SigV4 标准签名 + multipart + 预签名 URL），
经 `endpoint / region / path_style` 三件配置覆盖 AWS 与各家 S3 兼容厂商（公有云与 MinIO/Ceph 等自托管）。
完成时成品「落地」到 S3（本地 `.part` 随之清理）、读取走 302 预签名（`response-content-disposition`
保留展示/下载语义）；分块暂存、`groups`/`clean`/`build`、Install 保持本地语义。
设计：`docs/superpowers/specs/2026-10-01-s3-storage-design.md`；计划：`docs/superpowers/plans/2026-10-01-s3-storage.md`。

## 测试结果

| 套件 | 命令 | 结果 |
|---|---|---|
| 单元（宿主 PHP 8.5.6 / PHPUnit 10.5） | `vendor/bin/phpunit --no-coverage` | **286 tests, 899 assertions, 0 failures**（含 S3 特性 44 例 + payload_signing 增量 3 例；3 条 deprecation 为既有 `setAccessible`：`TestState.php:119` + `ConfigMapperTest.php:39/:101`，PHP 8.5-only） |
| 单元 × PHP 8.0（PHPUnit 9.6 解析线） | 新副本 `platform.php 8.0.30` → docker `php:8.0-cli-alpine` | **OK (286 tests, 899 assertions)** |
| 单元 × PHP 8.4（PHPUnit 10.5 解析线） | 新副本 `platform.php 8.4.0` → docker `php:8.4-cli-alpine` | **OK (286 tests, 899 assertions)** |
| S3 端到端（真 MinIO + 真 webman + 真 HTTP） | `vendor/bin/phpunit -c tests/Integration/webman/phpunit-s3.xml` | **9 tests, 82 assertions, 0 failures**（冷启动起真 MinIO，多轮复跑） |
| webman local 端到端（既有流程回归） | `vendor/bin/phpunit -c tests/Integration/webman/phpunit.xml` | **4 tests, 48 assertions** |
| Symfony 端到端（含 `SymfonyConfigParityTest` 运行时 parity） | `bash tests/Integration/symfony/ci.sh` | **OK (16 tests, 130 assertions)** |
| native（无框架宿主）端到端 | `bash tests/Integration/native/ci.sh` | **OK (8 tests, 60 assertions)** |

PHP 8.1/8.2/8.3 由 CI 矩阵覆盖；CI 的 MinIO 步骤本机无法执行（见「未验证」）。

## 证据锚点

- **SigV4 正确性**：AWS 官方测试向量三例（get-vanilla / get-header-value-trim / post-x-www-form-urlencoded）
  的 canonical request、string-to-sign、签名**逐字节全等**（`tests/SignatureV4Test.php` 内嵌官方向量；
  lead 与 tester 各自独立复算）。
- **字节级校验用 `mc`**（独立于被测客户端，非自证）：桶内对象与源文件 md5 相等；键布局
  `file/202610/<md5>.<ext>`（无 root_dir 泄漏）；**146B/197B 对象 ETag 带 `-1` 且 `X-Amz-Mp-Parts-Count`
  佐证 = 真 multipart，43B 为单次 PUT** —— `multipart_threshold` 的 `>` 边界端到端实证。
- **验签负例**：错误 secret 被真 MinIO 拒绝（`The request signature we calculated does not match`）——
  服务端确在验签，正例不是空证明。
- **红证据性质**（reviewer 校准后的表述）：**实现移除即红、恢复即绿（消融 + 变异双证），既有 237 例零误伤**。
  红态 `283/652, 38E/8F`（46 = 283 − 237，恰为新用例；既有 237 全绿）↔ 绿态 `283/873`；消融副本经
  `ReflectionClass` 自证加载路径；reviewer 另做 3 处独立变异（SigV4 空行、200 内嵌 `<Error>`、切片哈希
  回退为整文件）逐条先红后绿并逐字节还原。
- **如实披露**：① 不主张时间序「先红后绿」——实现与测试文件写于同一时间窗（src/Storage 01:50:30–01:52:56、
  `S3ClientTest.php` 01:51:17）；44 例原始红定格 01:08–01:28，但放行消息登记 01:50:37 与 coder 首写
  01:50:30 有 7 秒交叉，**记「未验证」**。② 第 12 例（分片切片哈希）**无实现前原始红灯**，以定向消融 B +
  01:54 重建快照（S3ClientTest 13 例全红）为证——不写成「12 例均有原始红灯」。
- **覆盖边界（分层设计，非漏测）**：`S3Client::curlTransport()` 的真实 curl 路径无单测覆盖
  （单测全走注入 transport）；宿主环境类问题只能由 E2E 兜底——下表的 curl_close 缺陷即此边界实证。
- **快照绑定**：`src/Storage/S3Client.php` 最终版 md5 `6898331ded27a5f946e090c741d2bff3` /
  sha256 `8733a0d3…`（含 payload_signing 增量）；其前版 `88c6e259…` 为厂商核验前冻结件——S3 E2E 9/82、
  reviewer 逐树复核与 8.0/8.4 矩阵曾绑定该版并通过；最终版以本轮（payload_signing 后）复跑为证。

## 本轮修掉的缺陷（每条红→绿）

| 缺陷 | 影响 | 证据 |
|---|---|---|
| **E2E 首轮红：`curl_close()`（PHP 8.5 弃用、8.0 起空操作）被 webman error handler 转为 ErrorException → transport 抛 → 重试 3 次（200/400ms）后 publish 失败**（单测抓不到：FakeS3Transport 不过 curl；CLI 下 deprecation 不抛） | S3 模式下每个上传第 3 块必败（upload_error） | 假 S3 端点只见 3 次同秒 HEAD、零 PUT，0.694s ≈ 重试睡眠；`php -r` 复现 deprecation→异常；删除该调用后 S3 E2E 9/9 |
| **Symfony 8 移除 `Request::get()`**：混合解析（framework-bundle 7.4 + http-foundation 8.1）下 `SymfonyRequest::input()` 抛 `UndefinedMethodError`（**存量缺陷**，非本特性引入） | Symfony 宿主 4 条共享流程全 500 | repro 500→200；`ci.sh` 4 failures → `OK (16, 130)`；等价性经 vendor v8.1.7 源码逐条核对（`InputBag::get()` 对标量默认值/数组值有硬约束，`has()+all()[$key]` 为逐字等价移植） |
| **跨月时间炸弹**：`tests/Support/UploadFixtures.php` 硬编码 `'202609'`，10 月起 3 条既有用例必红（**存量**；独立提交 `f36598a`） | 基线非全绿、验收不可达 | 修复前 `238/653/3F` → 修复后 `238/666/0`（666 与历史绿色计数吻合）；改为按内核同一条规则现算 `Util::generateSubDirName()` |
| **异常消息凭据擦除（加固）**：`S3Client::fail()` 双层擦除（配置 `access_key` 裸串 ≥8 字符 + `<AWSAccessKeyId>` 标签）；首版仅擦标签被探针打回（漏非 XML 兜底与 `<Message>` 内联两形态） | 网关错误页/内联回显可能把 key id 带进日志 | 六例探针 ALL CLEAN（Code/Message/状态/key 全保留）；reviewer 原样复跑独立复验 |
| **harness 三项（reviewer 报、联调实测定罪）**：① `stopS3Server()` 无所有权校验（共享缓存目录的 server.json → 并发运行互杀服务端）② 骨架 `pid_file` 为字面绝对路径（`rewrite()` 把 `base_path()` 求值落盘 + 骨架覆盖式复制 → 跨目录 pid 污染，实测副本把 pid 写进默认目录）③ webman 2.x 的 HTTP 服务端在 `config/process.php`，而 app-setup 给 `server.php` 加 listen → 18787 与 8787 双监听 | 并发/隔离运行必假红（实测 `Errors: 1, Skipped: 4`；"Address already in use: 8787"） | 修复：pid_file/status/stdout 全改 `$root/runtime/...` 并显式撤销旧 listen/count；process.php 走**定点行改写**（避开 Monolog `__set_state` 致命坑、形态不符即抛）；server.json 记 `owner=getmypid()`、stop 校验不符不杀（孤儿回收为显式开关 `AETHERA_E2E_S3_RECLAIM_ORPHAN=1`，默认不碰）。验收：默认实例 S3 `9/82` + local `4/48`（lead 第一手复跑同数）；隔离实例（`…-iso` + 18788）并存冒烟全绿且 stop 只停自己；所有权五分支 + 孤儿清理经真 MinIO 验证 |
| **payload_signing 第 9 键（厂商核验驱动）**：华为云 OBS 官方 FAQ 规定 SigV4 仅接受 `UNSIGNED-PAYLOAD`/STREAMING 形式，而客户端签真实 payload 哈希 → 「兼容华为云」不成立 | OBS 用户上传必失败（其余厂商用默认值零影响） | 红 3 例（`3 tests, 13 assertions, 3 failures`：三臂同秒对照隔离 payload 位、multipart 四请求全覆盖、非法值约束）→ 绿 `3 tests, 25 assertions`；`payload_signing = hash（默认）| unsigned` 三处配置同步；全集 `286/899` |

## 厂商兼容文档轮（同日）

- **`docs/S3-COMPATIBILITY.md` 落盘**：三路并行核验全球云 14 家 + 欧亚/中国云 15 家 + 企业存储/自托管/备份 16 项，合并为 46 行对照表（含子项），逐行附**来源 URL 与置信度**（官方/二手/推断），另附 12 条「未能核实清单」与「按配置键横向要点」。核验驱动出两个落地结论：**华为云 OBS 需 `payload_signing=unsigned`**（已实现）；**阿里云 OSS、腾讯 COS（2024 后建桶）、华为云 OBS 必须 `path_style=false`**。另发现 Rakuten Cloud 已停服、NIFTY 现属 FJcloud-V、Fastly 确有 S3 兼容对象存储、Azure Blob 原生非 S3（需网关，列有三个真实网关方案）。
- **配套文档同步**：13 份 README（中文 + 12 语言）新增「可选对象存储」小节与结构树段；`AGENTS.md` / `CLAUDE.md` / `docs/HARNESS.md` / `composer.json`（description+keywords）/ 两个 config 注释同步；三张 SVG 补 Storage 驱动层（XML 校验通过、text 节点 40/40/37 只增不减；**Chrome headless 渲染目检通过**——架构图新增「对象存储（S3 兼容）」节点、生命周期图新增 `driver = s3` 分支、设计图第一列叙事更新为「存储可切换 → 接对象存储 → 读取走 302」，均无碰撞）。
- **如实说明**：本环节中途遭遇 agent 后端 `402 Insufficient Balance` 中断（vendor-a 核验、doc-i18n-2 位置校正、doc-diagrams 视觉验证三个协作者先后失败）。恢复后：vendor-a 段 14 家由 lead 用官方来源逐一补齐核验；i18n 六份错位由 lead 脚本校正；其余断点由 lead 直接完成。这是环境事件，不影响上述证据链的事实性。

## 未验证 / 已知限制

- **对照表 40+ 家厂商为「协议级兼容 + 文档级核验」**（来源与置信度逐行标注）；端到端仅在真 MinIO 实测。
  GCS 32 片 multipart 上限、OBS 的 unsigned 要求、OSS 拒绝 chunked 三条落地结论未在真实厂商账号实测。
- **CI 的 MinIO 步骤**：GH runner 可直连 Docker Hub，但本机网络无法执行 GH Actions，该步骤本机未跑
  （YAML 结构已检、本地等价流程全绿）。本机服务端为 **Go 源码构建的 MinIO**
  （dl.min.io 与各镜像源的服务端二进制均已下架）。
- **PHP 8.1/8.2/8.3** 本机未实跑（8.0/8.4 已跑，覆盖 PHPUnit 9.6/10.5 两条解析线）。
- `UploadController::fail()` 以 `KNOWN_ERROR_MESSAGES`（键名）比对翻译文本、永远比不中——**既有已知限制**；
  存储驱动错误到客户端一律呈 `upload_error`（服务端异常链保留原始原因）。
- S3 对象的 `aetherupload:build` 索引重建与清理仍为本地语义；S3 对象生命周期建议用桶策略；
  `nosniff` 只加在 302 响应（对象响应头由 S3 服务端决定）。
- `tests/Integration/hyperf/*` 仍有 3 处 `curl_close()`（非本特性 diff；CI 跑 PHP 8.2 不受影响；
  PHP 8.5 本机跑该套件会踩同一 deprecation）。
- `access_key` < 8 字符时裸串擦除层不启用（代码注释已写明；真实 AWS key 20 字符、MinIO 默认 10）。

## 产物

本特性与测试见本次提交（16 改 + 13 新）；设计/计划见 `docs/superpowers/{specs,plans}/2026-10-01-s3-storage*.md`。
