# AetherUpload-Webman  

<img src="../js/aetherupload-pet.svg" width="120" alt="Aether Beast — the AetherUpload project pet">

[中文](../../README.md) · [English](README.en.md) · [한국어](README.ko.md) · [Русский](README.ru.md) · [Deutsch](README.de.md) · [Français](README.fr.md) · [Español](README.es.md) · [Português](README.pt.md) · [हिन्दी](README.hi.md) · [العربية](README.ar.md) · [বাংলা](README.bn.md) · [Bahasa Indonesia](README.id.md) · [日本語](README.ja.md)

This project is a port of the widely used Laravel large-file upload package [AetherUpload-Laravel](https://github.com/peinhu/AetherUpload-Laravel). The config reader, the concurrency handling, and the storage layer were rewritten for the resident-process model of [webman](https://www.workerman.net/webman).

**What it solves**

Uploading a large file straight from the browser runs into three problems you cannot dodge. Anything past `post_max_size` never gets through. A dropped connection means starting over. And the same file gets transferred again and again. AetherUpload slices the file in the browser and appends each chunk to a temporary file on the server; the finished file is named after the md5 of its own contents. Upload, reconnect resume, instant upload, deduplication, and integrity checking then share one mechanism. The whole file is never read into memory, and no database table is needed to record where a file lives.

**Its shape**

One composer package, with a **core decoupled from the host framework**: the same code runs under webman, Laravel, ThinkPHP, Symfony, Slim, Hyperf, and Yii2 (see [Supported frameworks](#supported-frameworks)). The host's config, routes, console commands, language files, and frontend scripts are distributed by the install / publish commands. There is no database dependency. Redis is needed only when instant upload is enabled, so it stays an optional dependency.

![Example page](http://wx2.sinaimg.cn/mw690/69e23056gy1fho6ymepjlg20go0aknar.gif) 

# Project structure

```text
aetherupload-webman/
├── src/                                  plugin source
│   ├── Runtime.php                       host binding point (static facade): holds only immutable bindings per process, and fails loudly when nothing is bound
│   ├── RequestContext.php                mutable state per request / per coroutine (group config snapshot, loaded locales); concurrent requests never cross groups on a resident process
│   ├── Contract/                         11 interfaces (config / translation / request / uploaded file / response / Redis / events / paths / filesystem / context / adapter)
│   ├── Kernel/                           default kernel-side implementations: AbstractAdapter, PrefixedConfig, Filesystem, NullRedis, NullEventDispatcher…
│   ├── Console/                          business logic (Runner) for the three console commands; the seven frameworks share the same copy behind their command shells
│   ├── Adapter/                          seven adapters: Webman / Laravel / ThinkPhp / Symfony / Slim / Hyperf / Yii
│   ├── UploadController.php              upload entry point: preprocess (preprocessing / instant-upload check) and saveChunk (chunk write)
│   ├── ResourceController.php            display and download entry point: display / download, can hand the file to nginx
│   ├── PartialResource.php               the chunked file itself: path assembly, append per chunk, rename, size and type validation
│   ├── Header.php                        resume state file: stores a single chunkIndex
│   ├── Resource.php                      the finished file object, injected as an argument into the "upload complete" event
│   ├── RedisSavedPath.php                instant-upload index: one key per record, independent TTL
│   ├── SavedPathResolver.php             storage path codec (three-segment stateless addressing)
│   ├── ConfigMapper.php                  config singleton + per-request group config snapshot
│   ├── MimeType.php                      mime ↔ extension map, re-checked against the whitelist before anything is written to disk
│   ├── Util.php                          temporary name generation, path safety checks, resource deletion
│   ├── Install.php                       install / uninstall: distributes config and assets into the host application
│   ├── Responser.php                     JSON response wrapper
│   ├── SimpleValidateTrait.php           minimal required-field validation
│   ├── ExamplePageTrait.php              example page
│   └── helpers.php                       template helpers: aetherupload_display_link / aetherupload_download_link
├── commands/                             webman console shells (copied to app/command on install; the logic lives in src/Console)
│   ├── AetherUploadListGroups.php        php webman aetherupload:groups    list groups and create their directories
│   ├── AetherUploadBuildRedisHashes.php  php webman aetherupload:build     rebuild the instant-upload index from what is on disk
│   └── AetherUploadCleanUpDirectory.php  php webman aetherupload:clean N   reclaim expired temporary files by mtime
├── config/
│   ├── app.php                           webman plugin config: groups, routes, middleware, and the individual switches
│   ├── aetherupload.php                  the same config in a framework-agnostic form, used as the baseline by the other six adapters (ConfigParityTest locks the two files together)
│   └── route.php                         four routes + their middleware attachment points
├── docs/                                 documentation, images, and frontend scripts
│   ├── aetherupload-architecture.svg     architecture diagram
│   ├── aetherupload-design.svg           design rationale diagram
│   ├── aetherupload-lifecycle.svg        upload lifecycle diagram
│   ├── HARNESS.md                        unit test contract (read before writing tests)
│   ├── REPORT.md                         a summary report of one full test and coverage run
│   ├── i18n/                             translated READMEs and diagrams (12 languages; see TRANSLATING.md for the translated navigation)
│   └── js/                               frontend assets (published to <document root>/vendor/aetherupload/js)
│       ├── aetherupload-all.js           bundled build: core + zepto + spark-md5
│       ├── aetherupload-core.js          core logic: hash, slice, upload, progress, reconnect retry
│       └── aetherupload-pet.svg          the project pet "Aether Beast", used as both the example page icon and the favicon
├── translations/{zh,en}/                 translations (published to aetherupload/ under the host's translations path)
├── views/example.blade.php               example page source, ready to copy when integrating
├── tests/
│   ├── *.php                             PHPUnit unit cases (host stubs stand in for the framework; PHP 8.0–8.4, both PHPUnit 9.6 and 10.5)
│   └── Integration/<fw>/                 end-to-end suites, one per framework: a real framework install and a real upload path (webman starts a real server via phpunit.xml, the other six run their own ci.sh)
├── uploads/                              legacy directory, unused at runtime
└── composer.json
```

> The 15 files at the root of `src/` (controllers, chunking, resume state, instant-upload index, path codec, error responses…) are the framework-agnostic upload core. They reference no host namespace: config, translations, requests, responses, Redis, and events all come from ports obtained through `Runtime`.
> The one exception is `Install.php`. The install script runs from `composer require`, before the host application has started, so `Runtime` is not bound yet. In that case it falls back to binding the webman adapter itself and reading the package's own `config/app.php`.

> Before installation all of these are files inside the package. After `composer require`, `Install.php` uses the mapping noted in the tree above to distribute config, commands, frontend scripts, and language files to the matching locations in the host application.

# Architecture

<img src="img/aetherupload-architecture.en.svg" alt="AetherUpload-Webman architecture">

- **Client**: include one script, `aetherupload-all.js` (zepto and spark-md5 are bundled). It computes the md5, slices the file, drives the progress bar, and retries automatically after a dropped connection.
- **Server**: `UploadController` has only two entry points (preprocess, chunk write). `PartialResource` handles paths, appending, and renaming; `Header` stores nothing but chunkIndex. Config is snapshotted per request by `ConfigMapper`, so concurrent requests on a resident process never leak one group's settings into another.
- **Storage and operations**: only three kinds of file ever exist on disk — `*.part` chunks, `_header/` resume state, and finished `<md5>.<ext>` files. Redis is used only when instant upload is enabled. With `x_accel_redirect` on, nginx sends the files directly, which also brings Range and resumable downloads.

# Design rationale

<img src="img/aetherupload-design.en.svg" alt="AetherUpload-Webman design rationale">

Beyond the three decisions in the diagram, there is one further choice made independently: **the instant-upload index is an optional dependency, and every record carries its own TTL**. A site without Redis still uploads fine (instant upload simply stops working). On a site with Redis, each record expires on its own through `SETEX`, so records share no expiry and the index never grows without bound under continuous uploads; records written by an older hash scheme can still be read as a fallback. Consistency is left to `aetherupload:build` (rebuild daily) and `aetherupload:clean` (reclaim temporary files by mtime).

# Upload lifecycle

<img src="img/aetherupload-lifecycle.en.svg" alt="AetherUpload-Webman upload lifecycle">

The main path has only four steps: **preprocess → chunk (loop) → final-chunk validation → commit to disk**. Preprocess creates an empty `.part` file and writes `chunkIndex=0` into the header. Every chunk follows a fixed order: validate → append → write chunkIndex back. Only the final chunk validates the size and the MIME type, recomputes the md5 of the whole file, renames it to `<md5>.<ext>`, and writes the instant-upload index.

Two side paths deserve a separate explanation:

- **Instant-upload hit**: preprocess finds that a finished file with the same md5 already exists. Its path is returned straight away, and not a single chunk is transferred.
- **Interruption and recovery**: resending the same chunk index is skipped idempotently. A jump in the index sequence or a truncated chunk only returns an error; it **does not clean up** the `.part` file or the header, so on a flaky network the client can keep retrying until the missing chunk is filled in. Only two things really clean up — a failed final-chunk validation (the whole file is discarded), and `aetherupload:clean`, run by cron after the page is closed, reclaiming files by mtime.

# Features
- [x] Percentage progress bar  
- [x] File type restrictions  
- [x] File size limits  
- [x] Multi-language support  
- [x] Per-group resource config  
- [x] Upload-complete events   
- [x] Synchronous upload *①*  
- [x] Reconnect resume *②*  
- [x] Instant upload *③*  
- [x] Custom middleware *④*  
- [x] Custom routes   
- [x] Lax mode

*①: Compared with asynchronous upload, synchronous upload is slightly slower when there is plenty of upload bandwidth. But synchronous upload can assemble the file while it is still uploading. Asynchronous upload cannot: the order in which chunks finish is not guaranteed, so it has to wait for every chunk before assembling, which means a long stall near the end. Synchronous upload also keeps only one chunk in flight at a time, so it uses less server memory per unit of time and supports more concurrent uploaders than the asynchronous mode.*  

*②: Reconnect resume is not the same thing as resumable upload. Reconnect resume means that when the network drops or a wireless link turns unstable, the upload component retries on a timer without the page being closed; once the network is back, uploading continues from the first chunk that did not complete. Refreshing the page, or closing and reopening it, breaks the resume — the parts already uploaded are left as orphaned files.*  

*③: Instant upload needs Redis on the server and FileReader / File.slice() in the browser. Without either one, instant upload cannot work. It is off by default and must be enabled in the config file.*  

*④: Together with custom middleware, this gives you permission control over access to and download of uploaded resources.*


# Supported frameworks

The core (chunking, resume, instant upload, validation, addressing) is decoupled from the host framework, so one package works under every framework listed below. Each one is backed by an end-to-end test that **really installs the framework and really runs the full upload path**:

| Framework | Integration | End-to-end test |
|---|---|---|
| webman | Native support (`composer require` distributes config / routes / commands / frontend scripts automatically) | `tests/Integration/webman/` |
| Laravel | ServiceProvider + `vendor:publish` | `tests/Integration/laravel/` |
| ThinkPHP | Register the Service in `app/service.php` | `tests/Integration/thinkphp/` |
| Symfony | Bundle + route resource import | `tests/Integration/symfony/` |
| Slim | One line — `Bootstrap::create()` | `tests/Integration/slim/` |
| Hyperf | `ConfigProvider` | `tests/Integration/hyperf/` |
| Yii2 | Attach a Bootstrap in the application's `bootstrap` | `tests/Integration/yii/` |

> The `webman` in the package name is historical (the plugin originally supported webman only). It does not affect use under the other frameworks.

## The two common steps

Whichever framework you use, two steps are **mandatory** after installing the package (webman runs them automatically from its install script; the other frameworks need the matching commands run by hand):

1. **Create the storage directories**: `aetherupload:groups` — creates `root_dir`, `_header`, and one directory per group.
   **Skip this and uploads always fail**: the core's `createGroupSubDir()` uses a non-recursive `mkdir` and returns false when the parent directory is missing, and that error is translated into a generic `upload_error`, so it is very hard to trace back to a missing directory.
2. **Publish files**: `aetherupload:publish` (on Laravel, `vendor:publish --tag=aetherupload-*`) — puts the language files and the frontend `js` where the host can serve them.

> The separator in command names follows each framework's console convention: webman / Laravel / ThinkPHP / Symfony / Hyperf / Slim use `:`, and **Yii uses `/`** (`php yii aetherupload/groups`). Every example below can be copied and run as it stands.

## Per-framework setup

**Laravel**

```php
// bootstrap/providers.php (Laravel 11+) or the providers array in config/app.php
AetherUpload\Adapter\Laravel\AetherUploadServiceProvider::class,
```
```bash
php artisan vendor:publish --tag=aetherupload-config         # publish config/aetherupload.php
php artisan vendor:publish --tag=aetherupload-translations   # publish language files
php artisan vendor:publish --tag=aetherupload-assets         # publish frontend js
php artisan aetherupload:groups
```
> This package's composer.json does **not** contain `extra.laravel.providers` — the six frameworks have mutually exclusive dependencies, so auto-discovery cannot be hard-coded. The provider has to be registered by hand.
> Config merging is **shallow**: once the application has published `config/aetherupload.php`, its `groups` **replaces** the plugin defaults as a whole. When you add a group, copy the default groups in at the same time.

**ThinkPHP**

```php
// app/service.php
return [ \AetherUpload\Adapter\ThinkPhp\AetherUploadService::class ];
```
```bash
php think aetherupload:groups
php think aetherupload:publish
```
> Again, note that `groups` replaces the defaults as a whole. Also, `config/route.php` and `config/lang.php` are **required files**: when they are missing, the framework hands null to `array_merge` / `array_change_key_case` and throws a TypeError.

**Symfony**

```php
// config/bundles.php
AetherUpload\Adapter\Symfony\AetherUploadBundle::class => ['all' => true],
```
```yaml
# config/routes.yaml (Symfony bundle routes must be imported by the application; there is no auto-loading mechanism)
aetherupload:
    resource: '@AetherUploadBundle/Resources/config/routes.php'
    type: php
```
> The `Configuration` tree declares every config key. An undeclared key makes the container fail to boot; it is not silently ignored.

**Slim**

```php
// public/index.php
$app = \AetherUpload\Adapter\Slim\Bootstrap::create([
    'config'    => require __DIR__ . '/../config/aetherupload.php',   // omit to use the package defaults
    'base_path' => dirname(__DIR__),
    'redis'     => static fn () => new Predis\Client(['database' => 0]),   // needed for instant upload
]);
$app->run();
```
> Slim's PSR-7 responses are immutable. The core's output is turned into a real PSR-7 response by the `Bootstrap::handler()` layer — **the only conversion point**. Putting it in middleware will not get through.
> Slim has no console convention, so this package ships ready-made entry classes for its four commands. You place the entry script yourself (four lines):
> ```php
> // bin/aetherupload
> require __DIR__ . '/../vendor/autoload.php';
> \AetherUpload\Adapter\Slim\Bootstrap::bind(require __DIR__ . '/../config/aetherupload.php', dirname(__DIR__));
> exit((new \AetherUpload\Adapter\Slim\Console\Application())->run());
> ```
> ```bash
> php bin/aetherupload aetherupload:groups     # plus publish / build / clean
> ```
> Requires `symfony/console` (require-dev / suggest in this package).

**Hyperf**

```php
// config/config.php — this package does not write extra.hyperf.config (it would interfere with
// other frameworks' auto-discovery; to be added once verified separately), so expand the
// ConfigProvider explicitly here (it supplies the routes, the commands, and the publish key)
return [
    // …
] + (new \AetherUpload\Adapter\Hyperf\ConfigProvider())();
```
```bash
php bin/hyperf.php aetherupload:groups
php bin/hyperf.php aetherupload:publish
```
> Requires `ext-swoole`. Two more development-time gotchas: `xdebug.mode=profile` makes Hyperf **exit silently** on cold start (`exit 255` inside `ClassLoader::init()`, with no PHP error at all), so set `XDEBUG_MODE=off` in CI and locally. And swoole's `Coroutine\run()` cannot share a process with PHPUnit, so coroutine tests have to run their probe in a separate process.

**Yii2**

```php
// application config
return [
    'bootstrap' => ['aetherupload' => \AetherUpload\Adapter\Yii\Bootstrap::class],
    'params'    => ['aetherupload' => require __DIR__ . '/aetherupload.php'],   // can start from the sample produced by publish
    'components' => [
        // Prerequisite: this must live under components. Yii ignores a same-named top-level key,
        // which stops enableStrictParsing from taking effect and lets the route verb constraint be
        // bypassed (a GET reaching an action declared POST only)
        'urlManager' => ['enableStrictParsing' => true],
        // Needed for instant upload; the console application needs it too, or aetherupload/build fails with Unknown component ID: redis
        'redis' => [ /* … */ ],
    ],
];
```
```bash
php yii aetherupload/groups     # note: Yii's console separator is "/"; writing aetherupload:groups reports Unknown command
php yii aetherupload/publish
```
> Assets are published to `vendor/aetherupload/js` under the **document root** (webman's document root is `public/`, Yii's is `web/`). The example page references the absolute path `/vendor/aetherupload/js/…`, whose meaning is "document root", so `YiiPaths::assetPath()` prefers the host's `@webroot` alias and falls back to `<app>/web` when that alias is unavailable.

**webman**

```bash
# run from the webman project root
composer require erikwang2013/aetherupload-webman
```
> webman is the only **zero-config** host: on `composer require`, `Install.php` distributes the config, routes, commands, language files, and frontend scripts, and creates the storage directories. Once installed, visit `http://your-domain/aetherupload` for the example page.
>
> Tip: to change config options, edit `config/plugin/erikwang2013/aetherupload-webman/app.php`.

> The other six frameworks must register their adapter first, then run the two common steps. **webman offers the same commands** (`php webman aetherupload:groups` / `aetherupload:publish`); its install script has just already run them once.

# Usage  
**Uploading a file**  

See the example file and its comments, then include the relevant file and code on any page that needs large-file upload.

**Group configuration**  

Add a group under `groups` in this plugin's config file, then run `php webman aetherupload:groups` to create the matching directory.  
```php
'video' => [
    'group_dir'                    => 'video',
    'resource_maxsize'             => 0,
    'resource_extensions'          => [],
    'event_before_upload_complete' => false, 
    'event_upload_complete'        => false,
],
```
On the frontend, select the upload group by calling `setGroup('group-name')`. The group must already exist, and the name **must not contain an underscore** — the name takes part in the storage path encoding, and an underscore makes resources in that group impossible to address, which fails already at config and upload time.

**Enabling instant upload (requires Redis and browser support)**  

See the Redis section of the webman documentation and install the required dependencies.  
Install Redis and start the service.  
Install the predis package: `composer require predis/predis`.  
Set `client` to `predis` in `config/redis.php`.  
Set `instant_completion` to `true` in this plugin's config file.

*Tip: Redis holds an instant-upload index that mirrors the real resource files. Any change to the real files must be synced into that index, otherwise it goes stale.  
The package already covers additions; when a resource file has to be deleted, you must call the matching methods yourself to remove both the file and its record in the index.* 
```php
\AetherUpload\Util::deleteResource($savedPath); //delete the matching resource file
\AetherUpload\Util::deleteRedisSavedPath($savedPath); //delete the matching Redis instant-upload record
``` 
*Both are idempotent: they return true even when the file or the record is already gone.* 

**Custom middleware**  

See the route middleware section of the webman documentation. Write your middleware and put its class name in the matching part of the config file.  
```php
'middleware_download' => [app\middleware\MiddlewareA::class,app\middleware\MiddlewareB::class],
```  
Use this to control permissions for uploading, accessing, and downloading files.

**Custom routes**  

Edit options such as `'route_uploading' => '/aetherupload/uploading'` in this plugin's config file, and call the matching frontend methods such as `setUploadingRoute('/aetherupload/uploading')`.  
The file access and download routes need no frontend method: once edited, they can be used directly.
 
**Upload-complete events**  

There are two events, one before upload completion and one after. See the Event section under common components in the webman documentation.  
Configure the handler classes for `aetherupload.before_upload_complete` and `aetherupload.upload_complete` in `config/event.php`.  
Set the matching options under `groups` to `true` in this plugin's config file. 
```php
'event_before_upload_complete' => true, 
'event_upload_complete'        => true,
```
Use this for extra processing before and after upload completion.

**Lax mode**  

Set `'lax_mode' => true,` in this plugin's config file, and call `setLaxMode(true)` on the frontend.  
Skipping the hash computation before upload shortens the total time. With this option on, instant upload and integrity checking are unavailable.

**Multi-language**  

The frontend detects the browser language and sets it automatically. Chinese and English are supported today.
  
**Handy console commands**  

`php webman aetherupload:groups` lists every group and creates the matching directories  
`php webman aetherupload:build` rebuilds the instant-upload index for resource files in Redis  
`php webman aetherupload:clean 2` removes orphaned temporary files older than 2 days  

# Tuning suggestions
* **(Recommended) Clear orphaned temporary files automatically every day**  
An upload can end unexpectedly — the page or the browser is force-closed mid-transfer, for instance. The partial files already written then become orphans that take up a lot of storage space. A crontab job can clear them on a schedule.  
Run `crontab -e` on Linux and make sure the file contains this line:  
```php
0 0 * * * php /absolute/path/to/project-root/webman aetherupload:clean 1> /dev/null 2>&1  
```  

* **Rebuild the Redis instant-upload index automatically every day**  
Bad handling or an extreme case can leave stale entries in the instant-upload index, which makes instant upload inaccurate. Rebuilding the index removes them and restores agreement with the real resource files.  
Run `crontab -e` on Linux and make sure the file contains this line:  
```php
0 0 * * * php /absolute/path/to/project-root/webman aetherupload:build 1> /dev/null 2>&1  
```  

* **(Optional) Enable the nginx internal redirect for resumable large-file downloads and video seeking**  
webman's file response does not implement HTTP Range. A large download goes out in one piece, video seeking does not work, and a worker process is tied up for the whole transfer. Behind nginx, you can turn on this plugin's `x_accel_redirect` option: nginx sends the file instead, the worker is released immediately, and Range works (resumable downloads, video seeking).  
Set `'x_accel_redirect' => true,` in this plugin's config file, and add a location for the internal prefix in your nginx config, with `alias` pointing at the absolute path of the project's upload root (note the trailing slash):  
```nginx
location /internal-aetherupload/ {
    internal;
    alias /absolute/path/to/project-root/storage/app/aetherupload/;
}
```
The location must keep `internal`, otherwise the outside can reach the resource files directly and bypass the application. If `root_dir` changes, `alias` has to change with it.  

* **Speed up chunk temp-file I/O (PHP only)**  
Use Linux's tmpfs to keep uploaded chunk temp files in memory, so they can be read and written quickly. This trades space for time and will **cost extra memory** (roughly one chunk's worth).  
Set the php.ini `upload_tmp_dir` value to `"/dev/shm"` and restart the service.  

* **Speed up chunk temp-file I/O (system temp directory)**  
Use Linux's tmpfs to keep uploaded chunk temp files in memory, so they can be read and written quickly. This trades space for time and will **cost extra memory** (roughly one chunk's worth).  
Run these commands:    
`mkdir /dev/shm/tmp`  
`chmod 1777 /dev/shm/tmp`  
`mount --bind /dev/shm/tmp /tmp`  

# Compatibility
<table>
  <th></th>
  <th>IE</th>
  <th>Edge</th>
  <th>Firefox</th>
  <th>Chrome</th>
  <th>Safari</th>
  <tr>
  <td>Upload</td>
  <td>10+</td>
  <td>12+</td>
  <td>3.6+</td>
  <td>6+</td>
  <td>5.1+</td>
  </tr>
  <tr>
  <td>Instant upload</td>
  <td>10+</td>
  <td>12+</td>
  <td>3.6+</td>
  <td>6+</td>
  <td>6+</td>
  </tr>
</table>

# Security
Before upload, AetherUpload filters file extensions with a whitelist plus a blacklist; after upload, it checks the Mime-Type. The whitelist directly constrains the extension the file is saved with, and the blacklist blocks common executable extensions by default to stop malicious uploads. For safety, never leave the whitelist empty.  

All these safeguards help, but malicious uploads can never be fully prevented. Set the upload directory permissions correctly, and make sure the relevant programs have no execute permission on the resource files.
