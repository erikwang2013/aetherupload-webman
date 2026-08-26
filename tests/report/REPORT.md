# AetherUpload 单元测试报告

**日期**: 2026-08-27
**运行环境**: PHP 8.0–8.4 (php:*.-cli-alpine, Docker) / PHPUnit 9.6 (PHP 8.0) 与 10.5 (PHP 8.1+) / pcov 覆盖率驱动
**测试命令**:
```bash
docker run --rm -v $PWD:/app -w /app php:8.3-cli-alpine php vendor/bin/phpunit --no-coverage
# 带覆盖率：
docker run --rm -v $PWD:/app -w /app php:8.3-cli-alpine sh -c \
  "apk add --no-cache \$PHPIZE_DEPS >/dev/null; pecl install pcov >/dev/null; docker-php-ext-enable pcov >/dev/null; \
   php vendor/bin/phpunit --coverage-text --coverage-html tests/report/coverage --log-junit tests/report/junit.xml"
# 全版本矩阵（composer 按各 PHP 版本解析依赖）：
for v in 8.0 8.1 8.2 8.3 8.4; do
  docker run --rm -v $PWD:/app -w /app php:$v-cli-alpine sh -c \
    "php -r \"copy('https://getcomposer.org/composer-stable.phar','/tmp/composer.phar');\" && \
     php /tmp/composer.phar update --no-interaction --prefer-dist -q && php vendor/bin/phpunit --no-coverage"
done
```
> 本机 PHP 二进制损坏（段错误），必须使用 Docker 运行。

## 测试结果（PHP 8.0–8.4 全绿）

| 指标 | 数值 |
|---|---|
| 测试总数 | **175** |
| 断言总数 | 346 |
| 失败 / 错误 | 0（全部 5 个 PHP 版本） |
| 警告 | 0（错误路径警告已在源头 `@` 抑制，兼容 PHPUnit 9.6 的 warning→exception 行为） |

## 代码覆盖率（src/ 全部 14 个类，PHP 8.3 + PHPUnit 10.5 + pcov）

| 指标 | 覆盖率 |
|---|---|
| 行覆盖率 | **96.99%** (387/399) |
| 方法覆盖率 | 90.54% (67/74) |
| 类覆盖率 | 71.43% (10/14) |

| 模块 | 方法 | 行 |
|---|---|---|
| ConfigMapper | 85.71% | 97.14% |
| ExamplePageTrait | 100% | 100% |
| Header | 100% | 100% |
| Install | 100% | 100% |
| MimeType | 100% | 100% |
| PartialResource | 84.21% | 92.98% |
| RedisSavedPath | 75.00% | 92.86% |
| Resource | 100% | 100% |
| ResourceController | 100% | 100% |
| Responser | 100% | 100% |
| SavedPathResolver | 100% | 100% |
| SimpleValidateTrait | 100% | 100% |
| UploadController | 66.67% | 97.67% |
| Util | 100% | 100% |

## 未覆盖路径与原因

1. **RedisSavedPath::exists / set 的异常分支**（'exists error'/'write error'）— 依赖 Redis 桩返回 1/0 之外的值，桩按 predis 契约只返回 1/0，分支不可达（桩层面已保证正确性）。
2. **PartialResource 少量分支**（append 写入失败路径等）— 真实文件系统下难以稳定构造 IO 失败。
3. **UploadController 少量行** — 秒传清理等交叉分支的极少数组合。
4. **ConfigMapper::set 未知属性异常** — 防御性守卫，正常调用不可达。

## 本轮（PHP 8 兼容 + 安全加固）修复内容

1. **[已修复] PHP 8.0 兼容**：测试中所有 `ReflectionProperty` 私有属性访问补充 `setAccessible(true)`（PHP 8.0 必需，8.1+ 为 no-op）；错误路径警告源头 `@` 抑制（Header::write/read、copy_dir 桩），兼容 PHPUnit 9.6 的 warning→exception 默认行为。
2. **[已修复] PartialResource::getCompletePath()/getGroupSubDirPath()** 补充 `base_path()` 前缀，不再依赖进程 CWD（移除测试中的 chdir hack）。
3. **[已修复] UploadController::saveChunk**：`$chunk` 缺失时判空（原会 fatal）；追加分块前增量校验大小上限；`getKey` 移入 try 并校验 hash 格式（防 Redis 字段注入）。
4. **[已修复] UploadController::preprocess** 失败时清理孤儿 .part/header 文件。
5. **[已修复] MimeType::search** array_search 严格判空（消除 `?: null` 对 falsy 键的误判）。
6. **[已修复] ResourceController::download** newName 清洗 CRLF/路径分隔符（防 header 注入）。
7. **[已修复] UploadController 构造** translation path 缺失时兜底到 `base_path()/resource/translations`（防 PHP 8 数组偏移告警）。
8. **[已修复] ConfigMapper::set** 未知属性抛异常（消除 PHP 8.2 动态属性弃用面）。
9. **[已修复] helpers.php** 函数包 `function_exists` 守卫（防与其他包函数重名 fatal）。

## 测试文件清单（tests/，15 个文件，命名空间 AetherUpload\Tests）

UtilTest(21)、MimeTypeTest(12)、SavedPathResolverTest(11)、ConfigMapperTest(8)、HeaderTest(9)、ResponserTest(4)、SimpleValidateTraitTest(9)、HelpersTest(4)、ResourceTest(5)、PartialResourceTest(29)、RedisSavedPathTest(15)、UploadControllerTest(31)、ResourceControllerTest(11)、ExamplePageTraitTest(3)、InstallTest(3)。

## 产物

- `tests/report/junit.xml` — JUnit XML（CI 集成用）
- `tests/report/coverage/` — HTML 覆盖率报告（浏览器打开 index.html）
- `tests/report/REPORT.md` — 本摘要
