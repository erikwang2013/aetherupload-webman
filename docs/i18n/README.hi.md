# AetherUpload-Webman  

<img src="../js/aetherupload-pet.svg" width="120" alt="एथर बीस्ट — AetherUpload प्रोजेक्ट का मैस्कॉट">

[中文](../../README.md) · [English](README.en.md) · [한국어](README.ko.md) · [Русский](README.ru.md) · [Deutsch](README.de.md) · [Français](README.fr.md) · [Español](README.es.md) · [Português](README.pt.md) · [हिन्दी](README.hi.md) · [العربية](README.ar.md) · [বাংলা](README.bn.md) · [Bahasa Indonesia](README.id.md) · [日本語](README.ja.md)

यह प्रोजेक्ट लोकप्रिय Laravel package [AetherUpload-Laravel](https://github.com/peinhu/AetherUpload-Laravel) से port किया गया है, जो बड़ी files का upload संभालता है; इसमें [webman](https://www.workerman.net/webman) के long-running process मॉडल के लिए config पढ़ना, concurrency handling और storage layer दोबारा लिखा गया है।

**यह क्या हल करता है**

browser से सीधे बड़ी file upload करते समय तीन समस्याएँ टाली नहीं जा सकतीं: `post_max_size` से बड़ी file upload ही नहीं होती; network टूटते ही शुरू से दोबारा करना पड़ता है; और एक ही file बार-बार transfer होती रहती है। AetherUpload का तरीका यह है — browser में file को slice किया जाता है, हर chunk को server की एक temp file में append किया जाता है, और disk पर लिखते समय file के अंदरूनी md5 से नाम रखा जाता है। इससे **upload, resume, instant upload, dedup और integrity verification** एक ही mechanism पर चलते हैं; पूरी प्रक्रिया में file memory में नहीं पढ़ी जाती, और “file कहाँ है” के लिए कोई database table बनाए रखने की ज़रूरत नहीं पड़ती।

**इसका स्वरूप**

एक composer package, जिसमें **core host framework से decoupled है**: यही code webman, Laravel, ThinkPHP, Symfony, Slim, Hyperf और Yii2 पर चलता है (देखें [समर्थित frameworks](#समर्थित-frameworks))। host का config, route, console command, language file और frontend script install / publish command से distribute होते हैं; database पर कोई निर्भरता नहीं; Redis की ज़रूरत सिर्फ़ instant upload चालू होने पर पड़ती है, यानी यह optional dependency है।

![उदाहरण पेज](http://wx2.sinaimg.cn/mw690/69e23056gy1fho6ymepjlg20go0aknar.gif) 

# प्रोजेक्ट संरचना

```text
aetherupload-webman/
├── src/                          plugin source code
│   ├── Runtime.php                 host binding point (static facade): process-level पर केवल immutable binding रखता है, binding न होने पर साफ़ error देता है
│   ├── RequestContext.php          प्रति request / प्रति coroutine mutable state (group config snapshot, लोड की गई भाषाएँ), long-running process में concurrent requests आपस में group नहीं मिलातीं
│   ├── Contract/                   11 interfaces (config / translation / request / uploaded file / response / Redis / event / path / filesystem / context / adapter)
│   ├── Kernel/                     core-side default implementations: AbstractAdapter, PrefixedConfig, Filesystem, NullRedis, NullEventDispatcher…
│   ├── Console/                    तीन console commands का business logic (Runner), जिसे सातों frameworks के command shells साझा करते हैं
│   ├── Adapter/                    सात adapters: Webman / Laravel / ThinkPhp / Symfony / Slim / Hyperf / Yii
│   ├── UploadController.php        upload entry point: preprocess (preprocessing / instant upload decision) और saveChunk (chunk writing)
│   ├── ResourceController.php      display और download entry point: display / download, nginx से सीधे भेजने की सुविधा
│   ├── PartialResource.php         chunk file का मूल object: path assembly, chunk-by-chunk append, rename, size और type verification
│   ├── Header.php                  resume state file: इसमें सिर्फ़ एक chunkIndex रहता है
│   ├── Resource.php                final file का object, जो “upload पूर्ण” event में parameter के रूप में inject होता है
│   ├── RedisSavedPath.php          instant upload index: हर record का अपना key और अलग TTL
│   ├── SavedPathResolver.php       storage path का encoding/decoding (three-part stateless addressing)
│   ├── ConfigMapper.php            config singleton + प्रति request group config snapshot
│   ├── MimeType.php                mime ↔ extension mapping, disk पर लिखने से पहले whitelist से दोबारा जाँच
│   ├── Util.php                    temp name generation, path security verification, resource deletion
│   ├── Install.php                 install / uninstall: host application को config और resources distribute करना
│   ├── Responser.php               JSON response wrapper
│   ├── SimpleValidateTrait.php     न्यूनतम required-field validation
│   ├── ExamplePageTrait.php        example page
│   └── helpers.php                 template helpers: aetherupload_display_link / aetherupload_download_link
├── commands/                     webman के console shells (install के समय app/command में copy होते हैं, logic src/Console में है)
│   ├── AetherUploadListGroups.php        php webman aetherupload:groups   groups की list बनाता है और उनके directories बनाता है
│   ├── AetherUploadBuildRedisHashes.php  php webman aetherupload:build    disk की मौजूदा स्थिति के अनुसार instant upload index दोबारा बनाता है
│   └── AetherUploadCleanUpDirectory.php  php webman aetherupload:clean N  mtime के अनुसार पुरानी temp files साफ़ करता है
├── config/
│   ├── app.php                   webman plugin config: group, route, middleware और तरह-तरह के switches
│   ├── aetherupload.php          framework-agnostic वही config, जो बाक़ी छह adapters के लिए baseline है (दोनों paths को ConfigParityTest एक-समान लॉक करता है)
│   └── route.php                 चार routes + हर एक के middleware mount points
├── docs/                         documents, images और frontend scripts
│   ├── aetherupload-architecture.svg  design architecture diagram
│   ├── aetherupload-design.svg        design approach diagram
│   ├── aetherupload-lifecycle.svg     upload lifecycle diagram
│   ├── HARNESS.md                     unit testing contract (test लिखने से पहले अवश्य पढ़ें)
│   ├── REPORT.md                      एक बार की पूरी testing और coverage का summary report
│   ├── i18n/                          बहुभाषी README और तीनों design diagrams के अनुवाद (12 भाषाएँ, अनुवाद की navigation के लिए TRANSLATING.md देखें)
│   └── js/                            frontend assets (यहाँ publish होते हैं: <document root>/vendor/aetherupload/js)
│       ├── aetherupload-all.js        bundled version: core + zepto + spark-md5
│       ├── aetherupload-core.js       core logic: hash निकालना, slicing, upload, progress, disconnect पर retry
│       └── aetherupload-pet.svg       प्रोजेक्ट मैस्कॉट “एथर बीस्ट”, जो example page icon और favicon दोनों के रूप में काम करता है
├── translations/{zh,en}/         बहुभाषी support (host के translations path में aetherupload/ के नीचे publish होता है)
├── views/example.blade.php       example page का source code, integration के समय सीधे देखा जा सकता है
├── tests/
│   ├── *.php                     PHPUnit unit test cases (host के stand-in उपयोग होते हैं, PHP 8.0–8.4, PHPUnit 9.6 और 10.5 दोनों versions)
│   └── Integration/<fw>/         end-to-end suite, हर framework के लिए एक: असली framework install होता है और असली upload pipeline चलती है (webman, phpunit.xml से असली service उठाता है; बाक़ी छह अपने-अपने ci.sh से चलते हैं)
├── uploads/                      पुराना directory, plugin runtime में इसका उपयोग नहीं होता
└── composer.json
```

> `src/` की जड़ में मौजूद वे 15 files (controllers, chunk, resume state, instant upload index, path encoding/decoding, error response…) framework-agnostic upload core हैं: ये किसी भी host namespace का reference नहीं देतीं, और config / translation / request / response / Redis / event सब `Runtime` से ports लेते हैं।
> इसका एकमात्र अपवाद `Install.php` है —— `composer require` से चलने वाला install script host application शुरू होने से पहले चलता है, जब `Runtime` अभी bind नहीं हुआ होता; ऐसी स्थिति में यह fallback के तौर पर ख़ुद को webman adapter से bind कर लेता है और package के अंदरूनी `config/app.php` पर लौट आता है।

> install से पहले ये सब package के अंदरूनी files हैं; `composer require` के बाद `Install.php` ऊपर की table में दिए mapping के अनुसार config, commands, frontend scripts और language files को host application में उनके तय स्थानों पर distribute करता है।

# डिज़ाइन आर्किटेक्चर

<img src="img/aetherupload-architecture.hi.svg" alt="AetherUpload-Webman आर्किटेक्चर">

- **client**: सिर्फ़ एक `aetherupload-all.js` include करना है (इसमें zepto और spark-md5 शामिल हैं); यही md5 निकालता है, chunk बनाता है, progress bar दिखाता है और disconnect होने पर अपने आप retry करता है।
- **server**: `UploadController` में सिर्फ़ दो entry points हैं (preprocessing और chunk writing); `PartialResource` path, append और rename संभालता है, जबकि `Header` सिर्फ़ chunkIndex रखता है; config `ConfigMapper` से प्रति request snapshot होता है, जिससे long-running process में concurrent requests के group आपस में नहीं मिलते।
- **storage और operations**: disk पर हमेशा सिर्फ़ तीन तरह की files रहती हैं —— `*.part` chunks, `_header/` resume state और `<md5>.<ext>` final file; Redis का उपयोग सिर्फ़ instant upload चालू होने पर होता है; `x_accel_redirect` चालू करने पर file nginx सीधे भेजता है, और साथ ही Range तथा resume download भी मिल जाता है।

# डिज़ाइन दृष्टिकोण

<img src="img/aetherupload-design.hi.svg" alt="AetherUpload-Webman डिज़ाइन दृष्टिकोण">

चित्र में दिए तीन निर्णयों के अलावा एक अलग चुनाव भी है: **instant upload index को optional dependency बनाया गया है + हर record का अपना अलग TTL**। जिन sites पर Redis नहीं है वहाँ भी upload होता रहता है (बस instant upload काम नहीं करता); जिन पर Redis है, वहाँ हर record `SETEX` से अपने-अपने समय पर expire होता है —— न expiry time साझा होता है, न लगातार upload से index बेवजह बढ़ता जाता है; upgrade के बाद पुराने hash records अब भी पढ़े जा सकते हैं। consistency की ज़िम्मेदारी `aetherupload:build` (रोज़ दोबारा बनाना) और `aetherupload:clean` (mtime के अनुसार temp files हटाना) पर है।

# upload जीवनचक्र

<img src="img/aetherupload-lifecycle.hi.svg" alt="AetherUpload-Webman upload जीवनचक्र">

मुख्य path में सिर्फ़ चार चरण हैं: **preprocess → chunk (loop) → अंतिम chunk का verification → disk पर लेखन**। preprocess ख़ाली `.part` बनाता है और `chunkIndex=0` header में लिखता है; हर chunk का क्रम तय है —— “verify → append → chunkIndex दोबारा लिखना”; size और MIME की जाँच, पूरी file की md5 दोबारा निकालना, `<md5>.<ext>` में rename करना और instant upload index लिखना —— ये सब सिर्फ़ अंतिम chunk पर होता है।

दो bypass paths अलग से समझाने योग्य हैं:

- **instant upload hit**: preprocessing चरण में पता चलता है कि वही md5 पहले से मौजूद है, तो सीधे उसका path लौटा दिया जाता है और एक भी chunk upload नहीं करना पड़ता।
- **रुकावट और recovery**: वही sequence number दोबारा भेजने पर वह idempotent तरीके से छोड़ दिया जाता है; sequence में उछाल आने या chunk के कटे होने पर सिर्फ़ error लौटता है, `.part` और header **साफ़ नहीं** किए जाते —— इसलिए कमज़ोर network पर client तब तक retry करता रह सकता है जब तक छूटा हुआ chunk न भर जाए। सफ़ाई सिर्फ़ दो हालातों में होती है —— अंतिम chunk का verification fail होने पर (पूरी file छोड़ दी जाती है), और page बंद होने के बाद cron से `aetherupload:clean` चलाकर mtime के अनुसार files हटाने पर।

# विशेषताएँ
- [x] प्रतिशत progress bar  
- [x] file type की सीमा  
- [x] file size की सीमा  
- [x] बहुभाषी support  
- [x] resource group config  
- [x] upload complete event   
- [x] synchronous upload *①*  
- [x] disconnect के बाद resume *②*  
- [x] instant upload *③*  
- [x] custom middleware *④*  
- [x] custom route   
- [x] lax mode

*①: asynchronous upload की तुलना में synchronous upload तब थोड़ा धीमा पड़ता है जब upload bandwidth काफ़ी हो, लेकिन synchronous तरीके में upload के साथ-साथ file जोड़ी जा सकती है; asynchronous में chunks के पूरा होने का क्रम तय नहीं होता, इसलिए सारे chunks पूरे होने का इंतज़ार करना पड़ता है और अंत के पास लंबा इंतज़ार होता है। synchronous upload में एक बार में सिर्फ़ एक ही chunk upload होता है, इससे प्रति इकाई समय server पर कम memory लगती है और asynchronous की तुलना में ज़्यादा लोग एक साथ upload कर सकते हैं।*  

*②: disconnect-resume और breakpoint-resume एक चीज़ नहीं हैं। यहाँ disconnect-resume का मतलब है कि network बंद होने या wireless network अस्थिर होने पर, page बंद किए बिना, upload component तय समय पर अपने आप retry करता है; network लौटते ही file उसी chunk से आगे upload होती है जो पूरा नहीं हुआ था। page refresh करने या बंद करके दोबारा खोलने पर यह resume काम नहीं करता —— तब तक upload हुआ हिस्सा बेकार file बन चुका होता है।*  

*③: instant upload के लिए server पर Redis और client browser का support (FileReader, File.slice()) दोनों ज़रूरी हैं; एक भी न हो तो instant upload काम नहीं करता। यह डिफ़ॉल्ट रूप से बंद है और config file से चालू करना पड़ता है।*  

*④: custom middleware के साथ मिलाकर upload हो चुके resources के access और download पर permission control किया जा सकता है।*


# समर्थित frameworks

core (chunk, resume, instant upload, verification, addressing) host framework से decoupled है; यही package नीचे दिए frameworks पर चलता है, और हर एक के लिए **असली framework install करके पूरी upload pipeline चलाने वाला** end-to-end test मौजूद है:

| framework | integration का तरीका | end-to-end test |
|---|---|---|
| webman | native support (`composer require` से config/route/command/frontend scripts अपने आप distribute हो जाते हैं) | `tests/Integration/webman/` |
| Laravel | ServiceProvider + `vendor:publish` | `tests/Integration/laravel/` |
| ThinkPHP | `app/service.php` में Service register करें | `tests/Integration/thinkphp/` |
| Symfony | Bundle + route resource import | `tests/Integration/symfony/` |
| Slim | एक लाइन `Bootstrap::create()` | `tests/Integration/slim/` |
| Hyperf | `ConfigProvider` | `tests/Integration/hyperf/` |
| Yii2 | application के `bootstrap` में Bootstrap लगाएँ | `tests/Integration/yii/` |

> package के नाम में `webman` ऐतिहासिक कारण से है (यह plugin शुरू में सिर्फ़ webman को support करता था); इससे बाक़ी frameworks पर इसके उपयोग पर कोई असर नहीं पड़ता।

## सामान्य दो चरण

किसी भी framework में package install करने के बाद दो काम **अनिवार्य** हैं (webman में install script इन्हें अपने आप कर देता है, बाक़ी frameworks में संबंधित command ख़ुद चलानी पड़ती है):

1. **storage directories बनाएँ**: `aetherupload:groups` —— यह `root_dir`, `_header` और हर group का directory बनाता है।
   **इन्हें बनाए बिना काम नहीं चलेगा**: core का `createGroupSubDir()` non-recursive `mkdir` है; parent directory न होने पर यह सीधे false लौटाता है, और error आगे जाकर एक सामान्य `upload_error` में बदल जाता है —— debug करते समय यह पहचानना मुश्किल होता है कि दिक़्क़त directory की है।
2. **files publish करें**: `aetherupload:publish` (Laravel में `vendor:publish --tag=aetherupload-*`) —— language files और frontend `js` को host से access होने वाली जगह पर रखता है।

> command नाम का separator हर framework के console convention के अनुसार है: webman / Laravel / ThinkPHP / Symfony / Hyperf / Slim में `:`, और **Yii में `/`** (`php yii aetherupload/groups`)। नीचे हर framework का उदाहरण सीधे copy करके चलाया जा सकता है।

## हर framework का integration

**Laravel**

```php
// bootstrap/providers.php (Laravel 11+) या config/app.php की providers array
AetherUpload\Adapter\Laravel\AetherUploadServiceProvider::class,
```
```bash
php artisan vendor:publish --tag=aetherupload-config         # config/aetherupload.php publish करता है
php artisan vendor:publish --tag=aetherupload-translations   # language files publish करता है
php artisan vendor:publish --tag=aetherupload-assets         # frontend js publish करता है
php artisan aetherupload:groups
```
> इस package के composer.json में `extra.laravel.providers` **नहीं** है (छह frameworks की dependencies आपस में टकराती हैं, इसलिए auto-discovery तय नहीं किया जा सकता); इसीलिए provider ख़ुद register करना ज़रूरी है।
> config merge **shallow merge** है: application में `config/aetherupload.php` publish होते ही उसमें दिया `groups` plugin के default values को **पूरी तरह बदल** देता है —— नया group जोड़ते समय default groups भी उसमें उतार लें।

**ThinkPHP**

```php
// app/service.php
return [ \AetherUpload\Adapter\ThinkPhp\AetherUploadService::class ];
```
```bash
php think aetherupload:groups
php think aetherupload:publish
```
> यहाँ भी ध्यान रहे कि `groups` पूरी तरह बदल जाता है; साथ ही `config/route.php` और `config/lang.php` **अनिवार्य files** हैं —— इनके न होने पर framework `array_merge`/`array_change_key_case` पर null पाता है और TypeError फेंकता है।

**Symfony**

```php
// config/bundles.php
AetherUpload\Adapter\Symfony\AetherUploadBundle::class => ['all' => true],
```
```yaml
# config/routes.yaml (Symfony में bundle के routes application की तरफ़ से import करने पड़ते हैं, auto-loading mechanism नहीं है)
aetherupload:
    resource: '@AetherUploadBundle/Resources/config/routes.php'
    type: php
```
> `Configuration` tree सभी config keys declare करता है; बिना declare की गई key से container शुरू ही नहीं होता (चुपचाप नज़रअंदाज़ नहीं किया जाता)।

**Slim**

```php
// public/index.php
$app = \AetherUpload\Adapter\Slim\Bootstrap::create([
    'config'    => require __DIR__ . '/../config/aetherupload.php',   // छोड़ दें तो package के default values उपयोग होंगे
    'base_path' => dirname(__DIR__),
    'redis'     => static fn () => new Predis\Client(['database' => 0]),   // instant upload के लिए ज़रूरी
]);
$app->run();
```
> Slim का PSR-7 response immutable होता है; core का output `Bootstrap::handler()` की परत से असली PSR-7 बनता है (**यही एकमात्र conversion point है**, middleware में रखने पर यह काम नहीं करेगा)।
> Slim में console का कोई convention नहीं है; यह package चारों commands के लिए तैयार entry classes देता है, पर entry script ख़ुद रखनी पड़ती है (चार लाइनें):
> ```php
> // bin/aetherupload
> require __DIR__ . '/../vendor/autoload.php';
> \AetherUpload\Adapter\Slim\Bootstrap::bind(require __DIR__ . '/../config/aetherupload.php', dirname(__DIR__));
> exit((new \AetherUpload\Adapter\Slim\Console\Application())->run());
> ```
> ```bash
> php bin/aetherupload aetherupload:groups     # इसके अलावा publish / build / clean भी हैं
> ```
> `symfony/console` चाहिए (यह package इसे require-dev / suggest में रखता है)।

**Hyperf**

```php
// config/config.php —— इस package ने extra.hyperf.config नहीं लिखा (यह बाक़ी frameworks के auto-discovery
// से टकराता है, अलग से verify होने के बाद जोड़ा जाएगा), इसलिए यहाँ ConfigProvider साफ़-साफ़ expand किया गया है (route, command और publish keys यही देता है)
return [
    // …
] + (new \AetherUpload\Adapter\Hyperf\ConfigProvider())();
```
```bash
php bin/hyperf.php aetherupload:groups
php bin/hyperf.php aetherupload:publish
```
> `ext-swoole` चाहिए। development के दौरान दो और बातें ध्यान रखें: `xdebug.mode=profile` होने पर Hyperf का cold start **चुपचाप बंद हो जाता** है (`ClassLoader::init()` में exit 255, कोई PHP error नहीं) —— CI और local पर `XDEBUG_MODE=off` रखें; swoole का `Coroutine\run()` PHPUnit के साथ एक ही process में नहीं चल सकता, इसलिए coroutine से जुड़े tests की probe अलग process में चलानी पड़ती है।

**Yii2**

```php
// application config
return [
    'bootstrap' => ['aetherupload' => \AetherUpload\Adapter\Yii\Bootstrap::class],
    'params'    => ['aetherupload' => require __DIR__ . '/aetherupload.php'],   // publish से बने sample पर आधारित हो सकता है
    'components' => [
        // शर्त: इसे components के अंदर ही लिखना है। top level पर रखी उसी नाम की key Yii नज़रअंदाज़ कर देता है,
        // तब enableStrictParsing लागू नहीं होता और route के verb constraints bypass हो जाते हैं (GET उस action तक पहुँच जाता है जो सिर्फ़ POST घोषित करता है)
        'urlManager' => ['enableStrictParsing' => true],
        // instant upload के लिए ज़रूरी; console application में भी configure करें, वरना aetherupload/build "Unknown component ID: redis" error देगा
        'redis' => [ /* … */ ],
    ],
];
```
```bash
php yii aetherupload/groups     # ध्यान दें: Yii के console का separator "/" है; aetherupload:groups लिखने पर Unknown command error आता है
php yii aetherupload/publish
```
> publish की जगह **document root** के नीचे `vendor/aetherupload/js` है (webman का document root `public/` है, Yii का `web/`): example page absolute path `/vendor/aetherupload/js/…` का reference देता है, जिसका अर्थ “document root” है, इसलिए `YiiPaths::assetPath()` पहले host का `@webroot` alias लेता है, और न मिलने पर `<app>/web` पर लौट आता है।

**webman**

```bash
# webman प्रोजेक्ट की जड़ में चलाएँ
composer require erikwang2013/aetherupload-webman
```
> webman ही एकमात्र **zero-config** host है: `composer require` के समय `Install.php` config, route, commands, language files और frontend scripts अपने आप distribute कर देता है और storage directories भी बना देता है। install के बाद सीधे `http://<domain>/aetherupload` खोलने पर example page दिखता है।
>
> सुझाव: config के options बदलने के लिए `config/plugin/erikwang2013/aetherupload-webman/app.php` edit करें।

> बाक़ी छह frameworks में पहले adapter register करना होता है, फिर “सामान्य दो चरण” चलाने होते हैं। **webman में भी वही commands मौजूद हैं** (`php webman aetherupload:groups` / `aetherupload:publish`), बस install के समय ये अपने आप चल चुकी होती हैं।

# उपयोग  
**file upload**  

बड़ी files upload करने वाले pages में संबंधित files और code include करें —— उदाहरण के लिए example file और उसकी comments देखें।

**group config**  

इस plugin की config file में `groups` के नीचे नया group जोड़ें, और `php webman aetherupload:groups` चलाकर उसका directory अपने आप बनवाएँ।  
```php
'video' => [
    'group_dir'                    => 'video',
    'resource_maxsize'             => 0,
    'resource_extensions'          => [],
    'event_before_upload_complete' => false, 
    'event_upload_complete'        => false,
],
```
frontend में `setGroup('group name')` method call करके upload group तय करें। ध्यान रहे, group का नाम पहले से मौजूद होना चाहिए और उसमें **underscore नहीं हो सकता** (यह storage path encoding में शामिल होता है; underscore होने पर उस group के resources locate नहीं हो पाते और config तथा upload दोनों चरणों में failure आता है)।

**instant upload जोड़ना (Redis और browser का support ज़रूरी)**  

webman के documents में Redis वाला हिस्सा देखें और ज़रूरी dependencies install करें।  
Redis install करें और service शुरू करें।  
predis package install करें: `composer require predis/predis`।  
`config/redis.php` में `client` को `predis` पर set करें।  
इस plugin की config file में `instant_completion` को `true` करें।

*सुझाव: Redis में असली resource files के अनुरूप instant upload list रखी जाती है; resource files जोड़ने या हटाने का असर इस list में भी पहुँचाना ज़रूरी है, वरना dirty data बन जाता है।  
नया record जोड़ने वाला हिस्सा package में पहले से मौजूद है; पर जब resource file हटानी हो, तो उपयोगकर्ता को ख़ुद संबंधित method call करके file और instant upload list का record हटाना पड़ता है।* 
```php
\AetherUpload\Util::deleteResource($savedPath); //संबंधित resource file हटाता है
\AetherUpload\Util::deleteRedisSavedPath($savedPath); //संबंधित Redis instant upload record हटाता है
``` 
*दोनों idempotent हैं: file या instant upload record पहले से न हो तो भी true लौटाते हैं।* 

**custom middleware**  

webman के documents में route middleware वाला हिस्सा देखकर अपना middleware बनाएँ और उसका नाम config file के संबंधित हिस्से में भरें।  
```php
'middleware_download' => [app\middleware\MiddlewareA::class,app\middleware\MiddlewareB::class],
```  
इस सुविधा से file के upload, access और download पर permission control किया जा सकता है।

**custom route**  

इस plugin की config file में `'route_uploading' => '/aetherupload/uploading'` जैसे options edit करें, और frontend में `setUploadingRoute('/aetherupload/uploading')` जैसे methods call करें।  
file access और download के routes edit करने के बाद उन्हें सीधे खोला जा सकता है; frontend method call करने की ज़रूरत नहीं।
 
**upload complete event**  

ये events दो हैं —— upload पूरा होने से पहले और upload पूरा होने के बाद; webman के documents में आम components वाला Event हिस्सा देखें।  
`config/event.php` में `aetherupload.before_upload_complete` और `aetherupload.upload_complete` के लिए संबंधित event handler classes configure करें।  
इस plugin की config file में `groups` के नीचे संबंधित options को `true` करें। 
```php
'event_before_upload_complete' => true, 
'event_upload_complete'        => true,
```
इस सुविधा से upload पूरा होने से पहले और बाद में अतिरिक्त processing की जा सकती है।

**lax mode**  

इस plugin की config file में `'lax_mode' => true,` edit करें, और frontend में `setLaxMode(true)` method call करें।  
upload से पहले hash निकालना छोड़ देने से कुल समय घट जाता है। यह option चालू करने पर instant upload और integrity verification काम नहीं करते।

**बहुभाषी support**  

frontend browser की भाषा detect करके अपने आप set कर देता है; फ़िलहाल चीनी और अंग्रेज़ी समर्थित हैं।
  
**आसान console commands**  

`php webman aetherupload:groups` सभी groups की list बनाता है और उनके directories अपने आप बनाता है  
`php webman aetherupload:build` Redis में resource files की instant upload list दोबारा बनाता है  
`php webman aetherupload:clean 2` 2 दिन से पुरानी बेकार temp files हटाता है  

# अनुकूलन सुझाव
* **(अनुशंसित)रोज़ अपने आप बेकार temp files साफ़ करने की व्यवस्था करें**  
upload प्रक्रिया बीच में रुक भी सकती है —— जैसे transfer के दौरान page या browser ज़बरदस्ती बंद कर देना; तब बना हुआ हिस्सा बेकार file बन जाता है और काफ़ी storage घेर लेता है। इन्हें समय-समय पर हटाने के लिए crontab के scheduled task उपयोग किए जा सकते हैं।  
Linux में `crontab -e` command चलाएँ और सुनिश्चित करें कि file में यह लाइन मौजूद है:  
```php
0 0 * * * php /प्रोजेक्ट-रूट-का-पूर्ण-पथ/webman aetherupload:clean 1> /dev/null 2>&1  
```  

* **रोज़ अपने आप Redis की instant upload list दोबारा बनाने की व्यवस्था करें**  
ग़लत handling और कुछ अत्यधिक हालातों में instant upload list में dirty data आ सकता है, जिससे instant upload की सटीकता पर असर पड़ता है; list दोबारा बनाने से dirty data हट जाता है और असली resource files के साथ तालमेल बहाल हो जाता है।  
Linux में `crontab -e` command चलाएँ और सुनिश्चित करें कि file में यह लाइन मौजूद है:  
```php
0 0 * * * php /प्रोजेक्ट-रूट-का-पूर्ण-पथ/webman aetherupload:build 1> /dev/null 2>&1  
```  

* **(वैकल्पिक)nginx internal redirect चालू करें, जिससे बड़ी files का breakpoint-resume और video dragging support मिले**  
webman का file response HTTP Range लागू नहीं करता; बड़ी file पूरी की पूरी भेजी जाती है, video में progress bar नहीं खींचा जा सकता, और पूरे transfer के दौरान एक worker process घिरा रहता है। अगर deployment nginx के नीचे है, तो इस plugin का `x_accel_redirect` option चालू किया जा सकता है —— file nginx सीधे भेजता है, worker तुरंत मुक्त हो जाता है, और Range (breakpoint-resume, video dragging) support मिल जाता है।  
इस plugin की config file में `'x_accel_redirect' => true,` edit करें, और nginx config में internal prefix के लिए location जोड़ें, जिसका `alias` प्रोजेक्ट के upload root directory के पूरे path की ओर इंगित हो (अंत की slash पर ध्यान दें):  
```nginx
location /internal-aetherupload/ {
    internal;
    alias /प्रोजेक्ट-रूट-का-पूर्ण-पथ/storage/app/aetherupload/;
}
```
इस location में `internal` रखना ज़रूरी है, ताकि बाहर से program को bypass करके सीधे resource files तक पहुँचा न जा सके; `root_dir` बदलने पर `alias` भी उसी के अनुसार बदलना पड़ता है।  

* **chunk temp files की read/write गति बढ़ाएँ (सिर्फ़ PHP पर लागू)**  
Linux के tmpfs file system की मदद से upload होने वाली chunk temp files को memory में रखकर तेज़ी से पढ़ा-लिखा जा सकता है; जगह के बदले समय पाकर read/write efficiency बढ़ती है, पर इससे कुछ memory **अतिरिक्त घिरती** है (लगभग एक chunk के आकार के बराबर)।  
php.ini में upload temp directory `upload_tmp_dir` की value `"/dev/shm"` set करें और service restart करें।  

* **chunk temp files की read/write गति बढ़ाएँ (system temp directory पर लागू)**  
Linux के tmpfs file system की मदद से upload होने वाली chunk temp files को memory में रखकर तेज़ी से पढ़ा-लिखा जा सकता है; जगह के बदले समय पाकर read/write efficiency बढ़ती है, पर इससे कुछ memory **अतिरिक्त घिरती** है (लगभग एक chunk के आकार के बराबर)।  
ये commands चलाएँ:    
`mkdir /dev/shm/tmp`  
`chmod 1777 /dev/shm/tmp`  
`mount --bind /dev/shm/tmp /tmp`  

# compatibility
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
  <td>instant upload</td>
  <td>10+</td>
  <td>12+</td>
  <td>3.6+</td>
  <td>6+</td>
  <td>6+</td>
  </tr>
</table>

# security
AetherUpload upload से पहले whitelist + blacklist के रूप में file extension filter करता है, और upload के बाद file का Mime-Type जाँचता है। whitelist सीधे सहेजी जाने वाली file extensions को सीमित करता है, और blacklist डिफ़ॉल्ट रूप से आम executable file extensions को रोक देता है, ताकि दुर्भावनापूर्ण files upload न हों; सुरक्षा के लिहाज़ से whitelist की जगह ख़ाली नहीं छोड़नी चाहिए।  

इतनी सुरक्षा के बाद भी दुर्भावनापूर्ण file upload पूरी तरह रोकना संभव नहीं है; सलाह है कि upload directory की permissions ठीक से set करें और सुनिश्चित करें कि संबंधित programs को resource files पर execute करने की permission न हो।
