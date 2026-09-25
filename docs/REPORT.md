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
（`composer.json` 不 require 任何框架），其余七个适配器在单测里必然是 0 —— 把它们混进一个数字
没有意义，因此按两栏看：

| 范围 | 行覆盖率（PHP 8.3 + PHPUnit 10.5 + pcov） |
|---|---|
| **单测可及**：`src/` 根目录 15 个文件 + `Contract/` + `Kernel/` + `Console/` + `Adapter/Webman/` | **81.68% (700/857)** |
| 七个非 webman 适配器：`Adapter/{Native,Laravel,ThinkPhp,Symfony,Slim,Hyperf,Yii}/` | 0%（单测不加载）→ 由各自端到端套件覆盖，见下文 |

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
4. **`helpers.php`（2/4）**：`function_exists` 守卫的假分支不可达。
5. **`RedisSavedPath::exists/set` 的异常分支**、**`PartialResource` 的 IO 失败路径**、
   **`ConfigMapper::set` 的未知属性守卫**：真实文件系统 / predis 契约下不可达，属防御性代码。
6. **七个非 webman 适配器（1410 行）**：单测里 0%，因为它们的框架依赖根本不在 `require` 里。
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

「4 条共享流程」来自 `tests/Integration/FlowAssertions.php`，八个宿主跑的是**同一份断言**：

1. `preprocess → 3 个分块 → 完成` 全链路，含 `.part` / `_header` 的中间态检查与最终 `savedPath` 格式
2. 错误路径：错误 hash、`resource_name[]=1` 这类类型越界输入不返回 500
3. 事件探针：监听器抛异常**不得**冒泡，响应仍为 200 且记录型监听器仍被调用
4. 秒传：`instant_completion=true` 重传同 hash 直接回 `savedPath`，不建新 `.part`

外加 `display` / `download` 的**逐字节相等**与 `X-Content-Type-Options`、`Content-Disposition` 校验。

各适配器 harness 用**各自专属的 redis 库**（webman 7 · laravel 4 · thinkphp 5 · hyperf 3 · slim 6 · symfony 8 · yii 9 · native 10）：
它们会清理自己的 `aetherupload:*` 键，共库时并行跑会让秒传用例偶发失败。

> 端到端套件不进单测计数：主 `phpunit.xml.dist` 已 `<exclude>tests/Integration</exclude>`，
> CI 里每个适配器一条独立 job（PHP 8.2；六个框架的当前版本都要求 ≥ 8.1，原生 PHP 那条不需要装任何框架）。

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
