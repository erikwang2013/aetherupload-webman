# AetherUpload-Webman  

<img src="../js/aetherupload-pet.svg" width="120" alt="ইথারবিস্ট — AetherUpload প্রকল্পের পোষ্য">

[中文](../../README.md) · [English](README.en.md) · [한국어](README.ko.md) · [Русский](README.ru.md) · [Deutsch](README.de.md) · [Français](README.fr.md) · [Español](README.es.md) · [Português](README.pt.md) · [हिन्दी](README.hi.md) · [العربية](README.ar.md) · [বাংলা](README.bn.md) · [Bahasa Indonesia](README.id.md) · [日本語](README.ja.md)

এই প্রকল্পটি বহুল প্রশংসিত Laravel বড়-file upload extension package [AetherUpload-Laravel](https://github.com/peinhu/AetherUpload-Laravel) থেকে port করা হয়েছে, এবং [webman](https://www.workerman.net/webman)-এর স্থায়ী process মডেলের জন্য configuration পড়া, concurrency পরিচালনা ও storage স্তর নতুন করে লেখা হয়েছে।

**এটি কী সমাধান করে**

ব্রাউজার থেকে সরাসরি বড় file upload করলে তিনটি সমস্যা এড়ানো যায় না: `post_max_size` ছাড়িয়ে গেলে upload হয় না; network একবার কেটে গেলে শুরু থেকে আবার করতে হয়; আর একই file বারবার পাঠাতে হয়। AetherUpload-এর পদ্ধতি হলো — ব্রাউজারে file-টিকে slice করে এক এক chunk করে সার্ভারের একটি temporary file-এ যুক্ত করা, আর ডিস্কে লেখার সময় file-এর content-এর md5 দিয়ে তার নাম দেওয়া। ফলে **upload, auto-resume, Instant Upload, ডুপ্লিকেট অপসারণ ও integrity যাচাই** একই কৌশল ব্যবহার করে; পুরো file কখনও memory-তে পড়তে হয় না, আর "file কোথায় আছে" তা জানতে একটি database table-ও রাখতে হয় না।

**এর গঠন**

একটি composer package, যেখানে **kernel host framework থেকে আলাদা**: একই কোড webman, Laravel, ThinkPHP, Symfony, Slim, Hyperf ও Yii2-এ ব্যবহার করা যায় (দেখুন [সমর্থিত ফ্রেমওয়ার্ক](#সমর্থিত-ফ্রেমওয়ার্ক))। host-এর configuration, route, console command, language file ও frontend script install / publish command দিয়ে বিতরণ করা হয়; database-এর উপর কোনো নির্ভরতা নেই; Redis কেবল Instant Upload চালু থাকলে দরকার, অর্থাৎ এটি ঐচ্ছিক নির্ভরতা।

![উদাহরণ পৃষ্ঠা](http://wx2.sinaimg.cn/mw690/69e23056gy1fho6ymepjlg20go0aknar.gif) 

# প্রকল্পের কাঠামো

```text
aetherupload-webman/
├── src/                          plugin-এর source
│   ├── Runtime.php                 host binding point (static facade): process স্তরে কেবল immutable binding ধরে রাখে, binding না থাকলে স্পষ্ট error দেয়
│   ├── RequestContext.php          প্রতি request / প্রতি coroutine-এর mutable state (group configuration snapshot, লোড করা ভাষা); স্থায়ী process-এ সমান্তরাল request-গুলো একে অন্যের group মেশে না
│   ├── Contract/                   ১১টি interface (configuration / translation / request / uploaded file / response / Redis / event / path / filesystem / context / adapter)
│   ├── Kernel/                     kernel পক্ষের default implementation: AbstractAdapter, PrefixedConfig, Filesystem, NullRedis, NullEventDispatcher…
│   ├── Console/                    তিনটি console command-এর business logic (Runner); সাতটি framework-এর command shell একই কোড ভাগ করে নেয়
│   ├── Adapter/                    সাতটি adapter: Webman / Laravel / ThinkPhp / Symfony / Slim / Hyperf / Yii
│   ├── UploadController.php        upload-এর entry point: preprocess (পূর্বপ্রক্রিয়া / Instant Upload যাচাই) ও saveChunk (chunk লেখা)
│   ├── ResourceController.php      প্রদর্শন ও download-এর entry point: display / download; nginx-কে সরাসরি পাঠানোর সুবিধাও আছে
│   ├── PartialResource.php         chunk file নিজেই: path তৈরি, এক chunk ধরে ধরে যুক্ত করা, rename, size ও type যাচাই
│   ├── Header.php                  Resume state file: কেবল একটি chunkIndex রাখে
│   ├── Resource.php                তৈরি হওয়া file-এর object, "upload সম্পন্ন" event-এ parameter হিসেবে inject হয়
│   ├── RedisSavedPath.php          Instant Upload index: প্রতিটি রেকর্ডের আলাদা key, আলাদা TTL
│   ├── SavedPathResolver.php       storage path-এর encode/decode (তিন স্তরের stateless addressing)
│   ├── ConfigMapper.php            configuration singleton + প্রতি request-এ group configuration snapshot
│   ├── MimeType.php                mime ↔ extension ম্যাপিং; ডিস্কে লেখার আগে whitelist দিয়ে পুনরায় যাচাই
│   ├── Util.php                    temporary নাম তৈরি, path-এর security যাচাই, resource মুছে ফেলা
│   ├── Install.php                 install / uninstall: host application-এ configuration ও resource বিতরণ
│   ├── Responser.php               JSON response wrapper
│   ├── SimpleValidateTrait.php     সর্বনিম্ন required-field যাচাই
│   ├── ExamplePageTrait.php        example page
│   └── helpers.php                 template helper: aetherupload_display_link / aetherupload_download_link
├── commands/                     webman-এর console shell (install-এর সময় app/command-এ কপি হয়, logic src/Console-এ)
│   ├── AetherUploadListGroups.php        php webman aetherupload:groups   সব group তালিকা করে ও সংশ্লিষ্ট directory তৈরি করে
│   ├── AetherUploadBuildRedisHashes.php  php webman aetherupload:build    ডিস্কের বর্তমান অবস্থা অনুযায়ী Instant Upload index পুনর্নির্মাণ করে
│   └── AetherUploadCleanUpDirectory.php  php webman aetherupload:clean N  mtime অনুযায়ী মেয়াদোত্তীর্ণ temporary file পরিষ্কার করে
├── config/
│   ├── app.php                   webman plugin configuration: group, route, middleware ও নানা switch
│   ├── aetherupload.php          framework-নিরপেক্ষ একই configuration, বাকি ছয়টি adapter-এর baseline (দুই path-এর মিল ConfigParityTest দিয়ে লক করা)
│   └── route.php                 চারটি route + প্রতিটির middleware সংযুক্তির জায়গা
├── docs/                         documentation, ছবি ও frontend script
│   ├── aetherupload-architecture.svg  ডিজাইন আর্কিটেকচার চিত্র
│   ├── aetherupload-design.svg        ডিজাইন ভাবনার চিত্র
│   ├── aetherupload-lifecycle.svg     upload lifecycle চিত্র
│   ├── HARNESS.md                     unit test-এর চুক্তি (test লেখার আগে অবশ্যই পড়ুন)
│   ├── REPORT.md                      একটি সম্পূর্ণ test ও coverage-এর সারসংক্ষেপ প্রতিবেদন
│   ├── i18n/                          বহুভাষিক README ও তিনটি ডিজাইন চিত্রের অনুবাদ (১২টি ভাষা; নেভিগেশন দেখুন TRANSLATING.md-এ)
│   └── js/                            frontend resource (প্রকাশিত হয় <document root>/vendor/aetherupload/js-এ)
│       ├── aetherupload-all.js        bundled সংস্করণ: core + zepto + spark-md5
│       ├── aetherupload-core.js       core logic: hash গণনা, slice, upload, progress, সংযোগ কাটলে পুনঃচেষ্টা
│       └── aetherupload-pet.svg       প্রকল্পের পোষ্য "ইথারবিস্ট", একইসঙ্গে example page-এর icon ও site icon
├── translations/{zh,en}/         বহুভাষা (host-এর translations path-এ aetherupload/-এর নিচে প্রকাশিত হয়)
├── views/example.blade.php       example page-এর source; সংযুক্ত করার সময় সরাসরি দেখে নেওয়া যায়
├── tests/
│   ├── *.php                     PHPUnit unit test (host-এর জন্য stand-in, PHP 8.0–8.4, PHPUnit 9.6 ও 10.5 উভয় সংস্করণ)
│   └── Integration/<fw>/         end-to-end suite, প্রতিটি framework-এর জন্য আলাদা: সত্যিকারের framework install করে সত্যিকারের upload path চালানো হয় (webman phpunit.xml দিয়ে আসল service তোলে, বাকি ছয়টি নিজের ci.sh চালায়)
├── uploads/                      পুরনো directory, plugin চলার সময় এটি ব্যবহৃত হয় না
└── composer.json
```

> `src/` root directory-র সেই ১৫টি file (controller, chunk, resume, Instant Upload index, path encode/decode, error response…) framework-নিরপেক্ষ upload kernel: এরা কোনো host namespace ব্যবহার করে না — configuration / translation / request / response / Redis / event সবই `Runtime` হয়ে port নেয়।
> একমাত্র ব্যতিক্রম `Install.php` —— `composer require`-এর triggered install script host application চালু হওয়ার আগেই চলে, তখন `Runtime` binding করা থাকে না; সেই অবস্থায় এটি fallback হিসেবে webman adapter নিজে bind করে এবং package-এর ভিতরের `config/app.php`-এ ফিরে যায়।

> install করার আগে এগুলো সবই package-এর ভিতরের file; `composer require`-এর পর `Install.php` উপরিউক্ত ম্যাপ অনুযায়ী configuration, command, frontend script ও language file host application-এর নির্দিষ্ট জায়গায় বিতরণ করে।

# ডিজাইন আর্কিটেকচার

<img src="img/aetherupload-architecture.bn.svg" alt="AetherUpload-Webman আর্কিটেকচার">

- **client**: একটি `aetherupload-all.js` যুক্ত করলেই হয় (এর ভিতরে zepto ও spark-md5 আছে); এটি md5 গণনা, slice, progress bar ও সংযোগ কেটে গেলে স্বয়ংক্রিয় পুনঃচেষ্টার দায়িত্ব নেয়।
- **server**: `UploadController`-এর মাত্র দুটি entry point (preprocess, chunk লেখা); `PartialResource` path, যুক্ত করা ও rename সামলায়, `Header` কেবল chunkIndex রাখে; configuration `ConfigMapper` দিয়ে প্রতি request-এ snapshot নেওয়া হয়, যাতে স্থায়ী process-এ সমান্তরাল request-গুলো একে অন্যের group মিশিয়ে না ফেলে।
- **storage ও পরিচালনা**: ডিস্কে সর্বদা মাত্র তিন ধরনের file থাকে — `*.part` chunk, `_header/` resume state, `<md5>.<ext>` চূড়ান্ত file; Redis কেবল Instant Upload চালু থাকলে ব্যবহৃত হয়; `x_accel_redirect` চালু করলে nginx নিজেই file পাঠায় এবং সঙ্গে Range ও resume download-ও যোগ হয়।

# ডিজাইনের ভাবনা

<img src="img/aetherupload-design.bn.svg" alt="AetherUpload-Webman ডিজাইনের ভাবনা">

চিত্রে দেখানো তিনটি সিদ্ধান্তের বাইরে আরও একটি স্বতন্ত্র পছন্দ আছে: **Instant Upload index-কে ঐচ্ছিক নির্ভরতা করা + প্রতিটি রেকর্ডের আলাদা TTL**। Redis না থাকা সাইটেও upload চলে (কেবল Instant Upload কাজ করে না); Redis থাকলে প্রতিটি রেকর্ড `SETEX` দিয়ে আলাদাভাবে মেয়াদোত্তীর্ণ হয় — ফলে মেয়াদ শেষ হওয়ার সময় ভাগাভাগি হয় না, আর অবিরাম upload-এর কারণে index অসীমভাবে বাড়েও না; upgrade-এর পরেও পুরনো hash রেকর্ড পড়া যায়। সামঞ্জস্য রক্ষার দায়িত্ব `aetherupload:build` (প্রতিদিন পুনর্নির্মাণ) ও `aetherupload:clean` (mtime অনুযায়ী temporary file পুনরুদ্ধার)-এর উপর।

# upload lifecycle

<img src="img/aetherupload-lifecycle.bn.svg" alt="AetherUpload-Webman upload lifecycle">

মূল path-এ মাত্র চারটি ধাপ: **preprocess → chunk (লুপ) → শেষ chunk-এর যাচাই → ডিস্কে লেখা**। preprocess খালি `.part` তৈরি করে এবং header-এ `chunkIndex=0` লেখে; প্রতিটি chunk-এর ক্রম নির্দিষ্ট — "যাচাই → যুক্ত → chunkIndex ফিরিয়ে লেখা"; কেবল শেষ chunk-এ size ও MIME যাচাই, পুরো file-এর md5 পুনরায় গণনা, `<md5>.<ext>`-এ rename এবং Instant Upload index-এ লেখা হয়।

দুটি বাইপাস path আলাদা করে বলার মতো:

- **Instant Upload মিলে যাওয়া**: preprocess পর্যায়ে দেখা যায় একই md5-এর চূড়ান্ত file আগেই আছে, তাই সরাসরি তার path ফেরত দেওয়া হয় — একটি chunk-ও পাঠাতে হয় না।
- **বিঘ্ন ও পুনরুদ্ধার**: একই নম্বর আবার পাঠালে idempotent-ভাবে বাদ পড়ে; নম্বর লাফিয়ে গেলে বা chunk কাটা পড়লে কেবল error ফেরত আসে, `.part` ও header **মুছে ফেলা হয় না** — তাই দুর্বল network-এ client অনবরত চেষ্টা করে অনুপস্থিত chunk পূরণ করতে পারে। সত্যিকার অর্থে মোছা হয় কেবল দুটি ক্ষেত্রে — শেষ chunk-এর যাচাই ব্যর্থ হলে (পুরোটাই বাতিল হয়), আর page বন্ধ করার পর cron `aetherupload:clean` চালিয়ে mtime অনুযায়ী পুনরুদ্ধার করলে।

# বৈশিষ্ট্য
- [x] শতকরা progress bar  
- [x] file type সীমাবদ্ধতা  
- [x] file size সীমাবদ্ধতা  
- [x] বহুভাষা সমর্থন  
- [x] resource group configuration  
- [x] upload সম্পন্ন হওয়ার event   
- [x] synchronous upload *①*  
- [x] auto-resume *②*  
- [x] file Instant Upload *③*  
- [x] নিজস্ব middleware *④*  
- [x] নিজস্ব route   
- [x] lax mode

*①: asynchronous upload-এর তুলনায় synchronous upload, upload bandwidth যথেষ্ট বেশি থাকলে কিছুটা ধীর, কিন্তু synchronous-এ upload-এর সঙ্গে সঙ্গেই file জোড়া লাগানো যায়; অন্যদিকে asynchronous-এ file chunk-গুলো কোনটি আগে শেষ হবে তা নিশ্চিত নয়, তাই সব chunk শেষ হওয়ার পরেই জোড়া লাগাতে হয় — ফলে asynchronous upload শেষ হওয়ার কাছাকাছি সময়ে অনেকক্ষণ অপেক্ষা করতে হয়। synchronous upload-এ একসঙ্গে কেবল একটি file chunk upload হয়, তাই নির্দিষ্ট সময়ে সার্ভারের memory কম দখল হয় এবং asynchronous-এর তুলনায় বেশি মানুষ একসঙ্গে upload করতে পারে।*  

*②: auto-resume আর resume-from-breakpoint এক জিনিস নয়; auto-resume মানে হলো — network কেটে গেলে বা wireless network অস্থির হলে, page না বন্ধ করে থাকলে upload component নির্দিষ্ট সময় পরপর স্বয়ংক্রিয়ভাবে আবার চেষ্টা করে, আর network ফিরে এলে যে file chunk-টি upload হয়নি সেখান থেকেই upload চলতে থাকে। page refresh করলে বা বন্ধ করে আবার খুললে auto-resume সম্ভব নয় — আগে upload হওয়া অংশ তখন অবৈধ file হয়ে যায়।*  

*③: file Instant Upload-এর জন্য সার্ভারে Redis এবং client ব্রাউজারের সমর্থন (FileReader, File.slice()) — দুটোই দরকার; একটিও না থাকলে Instant Upload কাজ করে না। ডিফল্টভাবে বন্ধ, configuration file-এ চালু করতে হয়।*  

*④: নিজস্ব middleware-এর সঙ্গে মিলিয়ে upload হওয়া resource-এর access ও download-এর উপর permission নিয়ন্ত্রণ করা যায়।*


# সমর্থিত ফ্রেমওয়ার্ক

kernel (chunk, resume, Instant Upload, যাচাই, addressing) host framework থেকে আলাদা; একই package নিচের framework-গুলোতে ব্যবহার করা যায়, আর প্রতিটির জন্যই **সত্যিকারের framework install করে সম্পূর্ণ upload path চালানো** end-to-end test রয়েছে:

| Framework | সংযুক্ত করার উপায় | end-to-end test |
|---|---|---|
| webman | native support (`composer require` করলেই configuration/route/command/frontend script স্বয়ংক্রিয়ভাবে বিতরণ হয়) | `tests/Integration/webman/` |
| Laravel | ServiceProvider + `vendor:publish` | `tests/Integration/laravel/` |
| ThinkPHP | `app/service.php`-এ Service রেজিস্টার করা | `tests/Integration/thinkphp/` |
| Symfony | Bundle + route resource import | `tests/Integration/symfony/` |
| Slim | এক লাইন `Bootstrap::create()` | `tests/Integration/slim/` |
| Hyperf | `ConfigProvider` | `tests/Integration/hyperf/` |
| Yii2 | application-এর `bootstrap`-এ Bootstrap যুক্ত করা | `tests/Integration/yii/` |

> package-এর নামে `webman` থাকার কারণ ঐতিহাসিক (এই plugin প্রথমে কেবল webman সমর্থন করত); বাকি framework-গুলোতে ব্যবহারে এর কোনো প্রভাব নেই।

## সাধারণ দুটি ধাপ

যে framework-ই হোক, package install করার পর দুটি **অবশ্যই করার** কাজ আছে (webman-এ install script নিজেই করে দেয়, বাকি framework-গুলোতে সংশ্লিষ্ট command নিজে চালাতে হয়):

1. **storage directory তৈরি করা**: `aetherupload:groups` —— `root_dir`, `_header` ও প্রতিটি group-এর directory তৈরি করে।
   **না বানালে অবশ্যই ব্যর্থ হবে**: kernel-এর `createGroupSubDir()` recursive নয় এমন `mkdir` ব্যবহার করে; parent directory না থাকলে সরাসরি false ফেরত দেয়, আর error-টি সব ক্ষেত্রেই সাধারণ `upload_error`-এ অনূদিত হয় — তাই সমস্যা খুঁজতে গিয়ে directory-র বিষয়টি বোঝা যায় না।
2. **file publish করা**: `aetherupload:publish` (Laravel-এ `vendor:publish --tag=aetherupload-*`) —— language file ও frontend `js` host যেখানে পৌঁছাতে পারে এমন জায়গায় রাখে।

> command নামের separator প্রতিটি framework-এর console রীতিনীতি অনুসরণ করে: webman / Laravel / ThinkPHP / Symfony / Hyperf / Slim-এ `:`, আর **Yii-তে `/`** (`php yii aetherupload/groups`)। নিচে প্রতিটির উদাহরণ সরাসরি copy করে চালানো যায়।

## প্রতিটি framework-এ সংযুক্ত করা

**Laravel**

```php
// bootstrap/providers.php (Laravel 11+) অথবা config/app.php-এর providers array
AetherUpload\Adapter\Laravel\AetherUploadServiceProvider::class,
```
```bash
php artisan vendor:publish --tag=aetherupload-config         # config/aetherupload.php publish করা
php artisan vendor:publish --tag=aetherupload-translations   # language file publish করা
php artisan vendor:publish --tag=aetherupload-assets         # frontend js publish করা
php artisan aetherupload:groups
```
> এই package-এর composer.json-এ `extra.laravel.providers` **নেই** (ছয়টি framework-এর নির্ভরতা পরস্পরবিরোধী, তাই auto-discovery কোডে লিখে দেওয়া যায় না); তাই provider নিজে হাতে রেজিস্টার করতেই হবে।
> configuration merge হলো **shallow merge**: application একবার `config/aetherupload.php` publish করলে তার ভিতরের `groups` plugin-এর default মান **সম্পূর্ণভাবে প্রতিস্থাপন** করে — নতুন group যোগ করার সময় default group-গুলোও সঙ্গে লিখে দিন।

**ThinkPHP**

```php
// app/service.php
return [ \AetherUpload\Adapter\ThinkPhp\AetherUploadService::class ];
```
```bash
php think aetherupload:groups
php think aetherupload:publish
```
> এখানেও খেয়াল রাখুন `groups` সম্পূর্ণভাবে প্রতিস্থাপিত হয়; এছাড়া `config/route.php` ও `config/lang.php` **অবশ্যই থাকতে হবে**, না থাকলে framework `array_merge`/`array_change_key_case`-এ null পেয়ে TypeError ছোড়ে।

**Symfony**

```php
// config/bundles.php
AetherUpload\Adapter\Symfony\AetherUploadBundle::class => ['all' => true],
```
```yaml
# config/routes.yaml (Symfony-র bundle route অবশ্যই application থেকে import করতে হয়, কোনো auto-load ব্যবস্থা নেই)
aetherupload:
    resource: '@AetherUploadBundle/Resources/config/routes.php'
    type: php
```
> `Configuration` tree-তে সব configuration key ঘোষণা করা আছে; ঘোষণা না করা key থাকলে container চালুই হয় না (চুপচাপ উপেক্ষা করে না)।

**Slim**

```php
// public/index.php
$app = \AetherUpload\Adapter\Slim\Bootstrap::create([
    'config'    => require __DIR__ . '/../config/aetherupload.php',   // বাদ দিলে package-এর default মান ব্যবহৃত হয়
    'base_path' => dirname(__DIR__),
    'redis'     => static fn () => new Predis\Client(['database' => 0]),   // Instant Upload-এর জন্য দরকার
]);
$app->run();
```
> Slim-এর PSR-7 response immutable; kernel-এর output `Bootstrap::handler()` স্তরে এসে সত্যিকারের PSR-7-এ রূপ নেয় (**একমাত্র রূপান্তরবিন্দু** — middleware-এ রাখলে তা এগিয়ে যেতে পারে না)।
> Slim-এ console-এর কোনো নির্দিষ্ট রীতি নেই; এই package চারটি command-এর তৈরি entry class দেয়, কিন্তু entry script নিজে রাখতে হয় (চার লাইন):
> ```php
> // bin/aetherupload
> require __DIR__ . '/../vendor/autoload.php';
> \AetherUpload\Adapter\Slim\Bootstrap::bind(require __DIR__ . '/../config/aetherupload.php', dirname(__DIR__));
> exit((new \AetherUpload\Adapter\Slim\Console\Application())->run());
> ```
> ```bash
> php bin/aetherupload aetherupload:groups     # এছাড়া publish / build / clean আছে
> ```
> `symfony/console` দরকার (এই package-এ require-dev / suggest)।

**Hyperf**

```php
// config/config.php —— এই package extra.hyperf.config লেখেনি (অন্য framework-এর auto-discovery-র সঙ্গে সংঘর্ষ করে,
// আলাদাভাবে যাচাই করার পর যোগ করা হবে); তাই এখানে স্পষ্টভাবে ConfigProvider খুলে দেওয়া হয় (route, command, publish key সবই এটি দেয়)
return [
    // …
] + (new \AetherUpload\Adapter\Hyperf\ConfigProvider())();
```
```bash
php bin/hyperf.php aetherupload:groups
php bin/hyperf.php aetherupload:publish
```
> `ext-swoole` দরকার। এছাড়া development-এ আরও দুটি বিষয় খেয়াল রাখুন: `xdebug.mode=profile` থাকলে Hyperf-এর cold start **চুপচাপ বন্ধ হয়ে যায়** (`ClassLoader::init()`-এ exit 255, কোনো PHP error ছাড়াই), তাই CI ও লোকালে `XDEBUG_MODE=off` দিন; swoole-এর `Coroutine\run()` PHPUnit-এর সঙ্গে একই process-এ চলতে পারে না, তাই coroutine-সংশ্লিষ্ট test-এর probe আলাদা process-এ চালাতে হবে।

**Yii2**

```php
// application configuration
return [
    'bootstrap' => ['aetherupload' => \AetherUpload\Adapter\Yii\Bootstrap::class],
    'params'    => ['aetherupload' => require __DIR__ . '/aetherupload.php'],   // publish-এ তৈরি sample-এর উপর ভিত্তি করা যায়
    'components' => [
        // পূর্বশর্ত: অবশ্যই components-এর নিচে লিখতে হবে। একই নামের top-level key Yii উপেক্ষা করে,
        // তখন enableStrictParsing কাজ করে না, route-এর verb constraint এড়িয়ে যাওয়া যায় (শুধু POST ঘোষণা করা action-এ GET চলে যায়)
        'urlManager' => ['enableStrictParsing' => true],
        // Instant Upload-এর জন্য দরকার; console application-এও দিতে হবে, নাহলে aetherupload/build "Unknown component ID: redis" error দেবে
        'redis' => [ /* … */ ],
    ],
];
```
```bash
php yii aetherupload/groups     # খেয়াল রাখুন: Yii-র console separator হলো "/", aetherupload:groups লিখলে Unknown command error আসবে
php yii aetherupload/publish
```
> publish-এর গন্তব্য **document root**-এর নিচে `vendor/aetherupload/js` (webman-এর document root হলো `public/`, Yii-র `web/`): example page যে absolute path `/vendor/aetherupload/js/…` ব্যবহার করে তার অর্থ "document root", তাই `YiiPaths::assetPath()` প্রথমে host-এর `@webroot` alias নেয়, না পেলে `<app>/web`-এ ফিরে যায়।

**webman**

```bash
# webman প্রকল্পের root directory-তে চালান
composer require erikwang2013/aetherupload-webman
```
> webman-ই একমাত্র **শূন্য-configuration** host: `composer require` করার সময় `Install.php` নিজেই configuration, route, command, language file ও frontend script বিতরণ করে এবং storage directory বানিয়ে দেয়। install শেষে সরাসরি `http://ডোমেইন/aetherupload` খুললেই example page।
>
> সংকেত: সংশ্লিষ্ট configuration option বদলাতে `config/plugin/erikwang2013/aetherupload-webman/app.php` সম্পাদনা করুন।

> বাকি ছয়টি framework-এ প্রথমে adapter রেজিস্টার করে তারপর "সাধারণ দুটি ধাপ" চালাতে হবে। **webman-ও একই command দেয়** (`php webman aetherupload:groups` / `aetherupload:publish`), কেবল install-এর সময় তা একবার স্বয়ংক্রিয়ভাবে চলে গেছে।

# ব্যবহার  
**File upload**  

example file ও তার comment দেখে, বড় file upload করার প্রয়োজন হলে সেই page-এ সংশ্লিষ্ট file ও কোড যুক্ত করুন।

**Group configuration**  

এই plugin-এর configuration file-এ `groups`-এর নিচে নতুন group যোগ করুন, তারপর `php webman aetherupload:groups` চালালে সংশ্লিষ্ট directory স্বয়ংক্রিয়ভাবে তৈরি হয়।  
```php
'video' => [
    'group_dir'                    => 'video',
    'resource_maxsize'             => 0,
    'resource_extensions'          => [],
    'event_before_upload_complete' => false, 
    'event_upload_complete'        => false,
],
```
frontend-এ `setGroup('group-নাম')` method ডেকে upload group নির্দিষ্ট করুন; খেয়াল রাখুন group-এর নাম আগেই থাকতে হবে এবং তাতে **underscore থাকা চলবে না** (নামটি storage path-এর encode-এ অংশ নেয়; underscore থাকলে সেই group-এর resource আর খুঁজে পাওয়া যায় না, আর configuration ও upload পর্যায়েই তা ব্যর্থ হয়)।

**Instant Upload যোগ করা (Redis ও ব্রাউজার সমর্থন প্রয়োজন)**  

Webman-এর documentation-এর Redis অংশ দেখে প্রয়োজনীয় নির্ভরতা install করুন।  
Redis install করে service চালু করুন।  
predis package install করুন — `composer require predis/predis`।  
`config/redis.php`-এ `client`-এর মান `predis` দিন।  
এই plugin-এর configuration file-এ `instant_completion` কে `true` করুন।

*সংকেত: Redis-এ প্রকৃত resource file-এর সঙ্গে মিলিয়ে একটি Instant Upload তালিকা রাখা হয়; প্রকৃত resource file যোগ বা মুছে ফেললে সেই পরিবর্তন Instant Upload তালিকাতেও মেলাতে হবে, নাহলে নোংরা data তৈরি হবে।  
যোগ করার অংশটি package-এ আগেই আছে; কিন্তু resource file মুছতে হলে ব্যবহারকারীকে নিজে সংশ্লিষ্ট method ডেকে file ও Instant Upload তালিকার রেকর্ড মুছতে হবে।* 
```php
\AetherUpload\Util::deleteResource($savedPath); // সংশ্লিষ্ট resource file মুছে ফেলে
\AetherUpload\Util::deleteRedisSavedPath($savedPath); // সংশ্লিষ্ট Redis Instant Upload রেকর্ড মুছে ফেলে
``` 
*দুটিই idempotent কাজ: file বা Instant Upload রেকর্ড আগেই না থাকলেও true ফেরত দেয়।* 

**নিজস্ব middleware**  

Webman-এর documentation-এর route middleware অংশ দেখে নিজের middleware তৈরি করুন এবং তার নাম configuration file-এর সংশ্লিষ্ট জায়গায় লিখুন।  
```php
'middleware_download' => [app\middleware\MiddlewareA::class,app\middleware\MiddlewareB::class],
```  
এই সুবিধা দিয়ে file-এর upload, access ও download-এর উপর permission নিয়ন্ত্রণ করা যায়।

**নিজস্ব route**  

এই plugin-এর configuration file-এ `'route_uploading' => '/aetherupload/uploading'`-এর মতো option সম্পাদনা করুন, এবং frontend-এ `setUploadingRoute('/aetherupload/uploading')`-এর মতো method ডাকুন।  
file access ও download-এর route সম্পাদনার পর তা সরাসরি ব্যবহার করা যায়, frontend method ডাকার দরকার নেই।
 
**upload সম্পন্ন হওয়ার event**  

এটি upload সম্পন্ন হওয়ার আগে ও পরে — এই দুটি event-এ ভাগ করা; Webman-এর documentation-এর সাধারণ component-এর Event অংশ দেখুন।  
`config/event.php`-এ `aetherupload.before_upload_complete` ও `aetherupload.upload_complete`-এর জন্য সংশ্লিষ্ট event handler class configure করুন।  
এই plugin-এর configuration file-এ `groups`-এর নিচের সংশ্লিষ্ট option `true` করুন। 
```php
'event_before_upload_complete' => true, 
'event_upload_complete'        => true,
```
এই সুবিধা দিয়ে upload সম্পন্ন হওয়ার আগে ও পরে অতিরিক্ত কাজ করা যায়।

**lax mode**  

এই plugin-এর configuration file-এ `'lax_mode' => true,` সম্পাদনা করুন, এবং frontend-এ `setLaxMode(true)` method ডাকুন।  
upload-এর আগে hash গণনা এড়িয়ে গেলে মোট সময় কমে। এই option চালু করলে Instant Upload ও integrity যাচাই আর করা যায় না।

**বহুভাষা**  

frontend ব্রাউজারের ভাষা শনাক্ত করে স্বয়ংক্রিয়ভাবে সেট করে; বর্তমানে চীনা ও ইংরেজি সমর্থিত।
  
**সহজে ব্যবহারযোগ্য console command**  

`php webman aetherupload:groups`  সব group তালিকা করে ও সংশ্লিষ্ট directory স্বয়ংক্রিয়ভাবে তৈরি করে  
`php webman aetherupload:build` Redis-এ resource file-এর Instant Upload তালিকা পুনর্নির্মাণ করে  
`php webman aetherupload:clean 2` ২ দিন আগের অবৈধ temporary file মুছে ফেলে  

# অপ্টিমাইজেশনের পরামর্শ
* **（প্রস্তাবিত）প্রতিদিন স্বয়ংক্রিয়ভাবে অবৈধ temporary file মুছে ফেলার ব্যবস্থা করুন**  
upload প্রক্রিয়া কখনও অপ্রত্যাশিতভাবে থেমে যেতে পারে — যেমন transfer চলার সময় জোর করে page বা browser বন্ধ করে দিলে — তখন তৈরি হওয়া আংশিক file অবৈধ file হয়ে যায় এবং অনেক storage জুড়ে বসে; crontab-এর scheduled task দিয়ে নিয়মিত সেগুলো মুছে ফেলা যায়।  
Linux-এ `crontab -e` command চালিয়ে নিশ্চিত করুন যে file-এ এই লাইনটি আছে:  
```php
0 0 * * * php /প্রকল্পের-root-directory-র-absolute-path/webman aetherupload:clean 1> /dev/null 2>&1  
```  

* **প্রতিদিন Redis-এর Instant Upload তালিকা স্বয়ংক্রিয়ভাবে পুনর্নির্মাণ করুন**  
অনুচিত পরিচালনা বা কিছু চরম পরিস্থিতিতে Instant Upload তালিকায় নোংরা data ঢুকে পড়তে পারে, যা Instant Upload-এর নির্ভুলতাকে প্রভাবিত করে; তালিকা পুনর্নির্মাণ করলে নোংরা data সরে যায় এবং প্রকৃত resource file-এর সঙ্গে সামঞ্জস্য ফিরে আসে।  
Linux-এ `crontab -e` command চালিয়ে নিশ্চিত করুন যে file-এ এই লাইনটি আছে:  
```php
0 0 * * * php /প্রকল্পের-root-directory-র-absolute-path/webman aetherupload:build 1> /dev/null 2>&1  
```  

* **（ঐচ্ছিক）nginx-এর internal redirect চালু করে বড় file-এর resume ও video seek চালু করুন**  
webman-এর file response HTTP Range প্রয়োগ করে না, তাই বড় file download পুরোটাই একবারে পাঠানো হয়, video-র progress bar টানা যায় না, আর পুরো transfer জুড়ে একটি worker process আটকে থাকে। Deployment যদি nginx-এর নিচে হয়, এই plugin-এর `x_accel_redirect` option চালু করা যায় — তখন file সরাসরি nginx পাঠায়, worker সঙ্গে সঙ্গে মুক্ত হয়, এবং Range (resume, video seek) সমর্থিত হয়।  
এই plugin-এর configuration file-এ `'x_accel_redirect' => true,` সম্পাদনা করুন, এবং nginx configuration-এ internal prefix-এর জন্য একটি location যোগ করুন যার `alias` প্রকল্পের upload root directory-র absolute path-এর দিকে দেখাবে (শেষের slash-টি খেয়াল করুন):  
```nginx
location /internal-aetherupload/ {
    internal;
    alias /প্রকল্পের-root-directory-র-absolute-path/storage/app/aetherupload/;
}
```
এই location-এ `internal` অবশ্যই রাখতে হবে, যাতে বাইরে থেকে program-কে এড়িয়ে সরাসরি resource file-এ পৌঁছানো না যায়; `root_dir` বদলালে `alias`-ও সেই অনুযায়ী বদলাতে হবে।  

* **chunk temporary file-এর পড়া/লেখার গতি বাড়ানো (কেবল PHP-তে কার্যকর)**  
Linux-এর tmpfs file system ব্যবহার করে upload-এর chunk temporary file memory-তে রেখে দ্রুত পড়া/লেখা করা যায়; জায়গার বিনিময়ে সময় বাঁচিয়ে read-write দক্ষতা বাড়ে, তবে **অতিরিক্ত** কিছু memory দখল হবে (প্রায় একটি chunk-এর সমান)।  
php.ini-তে upload temporary directory `upload_tmp_dir`-এর মান `"/dev/shm"` দিন, তারপর service restart করুন।  

* **chunk temporary file-এর পড়া/লেখার গতি বাড়ানো (system temporary directory-তে কার্যকর)**  
Linux-এর tmpfs file system ব্যবহার করে upload-এর chunk temporary file memory-তে রেখে দ্রুত পড়া/লেখা করা যায়; জায়গার বিনিময়ে সময় বাঁচিয়ে read-write দক্ষতা বাড়ে, তবে **অতিরিক্ত** কিছু memory দখল হবে (প্রায় একটি chunk-এর সমান)।  
নিচের command-গুলো চালান:    
`mkdir /dev/shm/tmp`  
`chmod 1777 /dev/shm/tmp`  
`mount --bind /dev/shm/tmp /tmp`  

# সামঞ্জস্যতা
<table>
  <th></th>
  <th>IE</th>
  <th>Edge</th>
  <th>Firefox</th>
  <th>Chrome</th>
  <th>Safari</th>
  <tr>
  <td>upload</td>
  <td>10+</td>
  <td>12+</td>
  <td>3.6+</td>
  <td>6+</td>
  <td>5.1+</td>
  </tr>
  <tr>
  <td>Instant Upload</td>
  <td>10+</td>
  <td>12+</td>
  <td>3.6+</td>
  <td>6+</td>
  <td>6+</td>
  </tr>
</table>

# নিরাপত্তা
AetherUpload upload-এর আগে whitelist + blacklist দিয়ে file-এর extension ছেঁকে নেয়, আর upload-এর পর file-এর Mime-Type যাচাই করে। whitelist সরাসরি সংরক্ষিত file-এর extension সীমিত করে, blacklist ডিফল্টভাবে প্রচলিত executable extension আটকে রাখে — এভাবে ক্ষতিকর file upload ঠেকানো হয়; নিরাপত্তার স্বার্থে whitelist ঘরটি খালি রাখা উচিত নয়।  

নানা নিরাপত্তা ব্যবস্থা নেওয়া হলেও ক্ষতিকর file upload সম্পূর্ণ ঠেকানো অসম্ভব; তাই upload directory-র permission সঠিকভাবে সেট করুন এবং নিশ্চিত করুন যে সংশ্লিষ্ট program-গুলোর resource file-এর উপর execute করার অনুমতি নেই।
