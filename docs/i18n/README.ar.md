# AetherUpload-Webman  

<img src="../js/aetherupload-pet.svg" width="120" alt="وحش الأثير — الحيوان الأليف لمشروع AetherUpload">

[中文](../../README.md) · [English](README.en.md) · [한국어](README.ko.md) · [Русский](README.ru.md) · [Deutsch](README.de.md) · [Français](README.fr.md) · [Español](README.es.md) · [Português](README.pt.md) · [हिन्दी](README.hi.md) · [العربية](README.ar.md) · [বাংলা](README.bn.md) · [Bahasa Indonesia](README.id.md) · [日本語](README.ja.md)

هذا المشروع منقول من حزمة رفع الملفات الكبيرة [AetherUpload-Laravel](https://github.com/peinhu/AetherUpload-Laravel) واسعة الانتشار، وقد أُعيدت فيه كتابة قراءة الإعدادات ومعالجة التزامن وطبقة التخزين بما يناسب نموذج العملية المقيمة في [webman](https://www.workerman.net/webman).

**ما الذي يحلّه**

عند رفع الملفات الكبيرة مباشرةً من المتصفح تبرز ثلاث مشكلات لا مفرّ منها: تجاوز `post_max_size` يمنع الرفع؛ وانقطاع الشبكة يفرض البدء من الصفر؛ وتكرار نقل الملف نفسه مرارًا. أما طريقة AetherUpload فهي: تقطيع الملف داخل المتصفح، ثم إلحاق كل chunk على التوالي بملف مؤقت على الخادم، وأخيرًا تسميته عند الكتابة إلى القرص اعتمادًا على md5 لمحتوى الملف. وهكذا تتشارك **عملية الرفع، والاستئناف بعد انقطاع الاتصال، والرفع الفوري، وإزالة التكرار، والتحقق من السلامة** الآلية نفسها، دون قراءة الملف كاملًا في الذاكرة، ودون الحاجة إلى جدول قاعدة بيانات يتتبّع «أين الملف».

**شكله**

حزمة composer واحدة، **بنواة منفصلة عن إطار المضيف**: الكود نفسه يعمل على webman و PHP الأصلي (بلا إطار) و Laravel و ThinkPHP و Symfony و Slim و Hyperf و Yii2 (انظر [الأطر المدعومة](#الأطر-المدعومة)). تتولّى أوامر التثبيت / النشر توزيع إعدادات المضيف وتوجيهه وأوامر طرفيته وملفات لغته وسكربتات واجهته الأمامية؛ ولا اعتماد على قاعدة بيانات؛ ولا يُطلب Redis إلا عند تفعيل الرفع الفوري، فهو اعتماد اختياري.

![مثال لصفحة](http://wx2.sinaimg.cn/mw690/69e23056gy1fho6ymepjlg20go0aknar.gif) 

# بنية المشروع

```text
aetherupload-webman/
├── src/                          شيفرة الإضافة
│   ├── Runtime.php                 نقطة ربط المضيف (واجهة ثابتة): على مستوى العملية لا يحمل إلا ارتباطات غير قابلة للتغيير، ويصرّح بخطأ عند غياب الربط
│   ├── RequestContext.php          حالة قابلة للتغيير لكل طلب / لكل coroutine (لقطة إعدادات المجموعة، اللغات المحمَّلة)، فلا تختلط المجموعات بين الطلبات المتزامنة في العملية المقيمة
│   ├── Contract/                   11 واجهة (الإعدادات / الترجمة / الطلب / ملف الرفع / الاستجابة / Redis / الأحداث / المسارات / نظام الملفات / السياق / المهايئ)
│   ├── Kernel/                     التنفيذات الافتراضية في جانب النواة: AbstractAdapter, PrefixedConfig, Filesystem, NullRedis, PhpFileTranslator, ClientRedis…
│   ├── Console/                    منطق الأعمال لأوامر الطرفية الثلاثة (Runner) + مدخل جاهز للمضيفات بلا اصطلاح طرفية، تتقاسمه قشور الأوامر السبع نفسها
│   ├── Adapter/                    المهايئات الثمانية: Webman / Native / Laravel / ThinkPhp / Symfony / Slim / Hyperf / Yii
│   ├── UploadController.php        مدخل الرفع: preprocess (التهيئة / تحديد الرفع الفوري) و saveChunk (كتابة الـ chunks)
│   ├── ResourceController.php      مدخل العرض والتنزيل: display / download، مع إمكانية تسليم الإرسال إلى nginx مباشرةً
│   ├── PartialResource.php         جسم ملف الـ chunks: تركيب المسار، والإلحاق chunk تلو الآخر، وإعادة التسمية، والتحقق من الحجم والنوع
│   ├── Header.php                  ملف حالة نقطة التوقف: لا يخزّن إلا chunkIndex واحدًا
│   ├── Resource.php                كائن الملف النهائي، يُمرَّر وسيطًا إلى حدث «اكتمال الرفع»
│   ├── RedisSavedPath.php          فهرس الرفع الفوري: سجل لكل key، مع TTL مستقل
│   ├── SavedPathResolver.php       ترميز مسار التخزين وفك ترميزه (عنونة بلا حالة ثلاثية المقاطع)
│   ├── ConfigMapper.php            مفرد الإعدادات (singleton) + لقطة إعدادات المجموعة لكل طلب
│   ├── MimeType.php                ربط mime ↔ الامتداد، مع إعادة تحقق وفق القائمة البيضاء قبل الكتابة إلى القرص
│   ├── Util.php                    توليد الأسماء المؤقتة، والتحقق الأمني من المسارات، وحذف الموارد
│   ├── Install.php                 التثبيت / إلغاء التثبيت: توزيع الإعدادات والموارد على تطبيق المضيف
│   ├── Responser.php               تغليف استجابة JSON
│   ├── SimpleValidateTrait.php     تحقق مبسّط من الحقول المطلوبة
│   ├── ExamplePageTrait.php        صفحة المثال
│   └── helpers.php                 مساعدات القوالب: aetherupload_display_link / aetherupload_download_link
├── commands/                     قشور أوامر الطرفية في webman (تُنسخ إلى app/command عند التثبيت، والمنطق في src/Console)
│   ├── AetherUploadListGroups.php        php webman aetherupload:groups    يسرد المجموعات وينشئ أدلتها
│   ├── AetherUploadBuildRedisHashes.php  php webman aetherupload:build    يعيد بناء فهرس الرفع الفوري وفق الحالة الفعلية على القرص
│   └── AetherUploadCleanUpDirectory.php  php webman aetherupload:clean N  ينظّف الملفات المؤقتة المنتهية وفق mtime
├── config/
│   ├── app.php                   إعدادات إضافة webman: المجموعات والتوجيه والـ middleware ومختلف المفاتيح
│   ├── aetherupload.php          الإعدادات نفسها بمعزل عن أي إطار، وتُستخدم خط أساس للمهايئات السبعة الأخرى (المساران مثبّتان بالتوافق عبر ConfigParityTest)
│   └── route.php                 أربعة مسارات + نقطة تركيب middleware لكل منها
├── docs/                         التوثيق والصور وسكربتات الواجهة الأمامية
│   ├── aetherupload-architecture.svg  مخطط البنية
│   ├── aetherupload-design.svg        مخطط منهج التصميم
│   ├── aetherupload-lifecycle.svg     مخطط دورة حياة الرفع
│   ├── HARNESS.md                     عقد اختبارات الوحدة (قراءة إلزامية قبل كتابة أي اختبار)
│   ├── REPORT.md                      تقرير موجز لجولة اختبار شاملة وقياس تغطية
│   ├── i18n/                          ترجمات README ومخططات التصميم الثلاثة (12 لغة، ودليل التنقّل بينها في TRANSLATING.md)
│   └── js/                            موارد الواجهة الأمامية (تُنشر إلى <جذر المستند>/vendor/aetherupload/js)
│       ├── aetherupload-all.js        النسخة المجمّعة: النواة + zepto + spark-md5
│       ├── aetherupload-core.js       المنطق الأساسي: حساب hash، والتقطيع، والرفع، والتقدّم، وإعادة المحاولة عند الانقطاع
│       └── aetherupload-pet.svg       حيوان المشروع الأليف «وحش الأثير»، يُستخدم أيضًا أيقونةً لصفحة المثال وللموقع
├── translations/{zh,en}/         تعدد اللغات (يُنشر إلى مسار translations في المضيف ضمن aetherupload/)
├── views/example.blade.php       مصدر صفحة المثال، يمكن الرجوع إليه مباشرةً عند الدمج
├── tests/
│   ├── *.php                     حالات اختبار الوحدة بـ PHPUnit (المضيف ببدائل، PHP 8.0–8.4، بإصداري PHPUnit 9.6 و 10.5)
│   └── Integration/<fw>/         أطقم end-to-end، واحدة لكل إطار: تثبيت حقيقي للإطار وتشغيل حقيقي لمسار الرفع (webman يشغّل خدمة حقيقية عبر phpunit.xml، والسبعة الأخرى عبر ci.sh الخاص بكل منها)
├── uploads/                      دليل قديم لا تستخدمه الإضافة وقت التشغيل
└── composer.json
```

> الملفات الخمسة عشر في جذر `src/` (المتحكمات، والـ chunks، ونقطة التوقف، وفهرس الرفع الفوري، وترميز المسارات، واستجابات الخطأ…) هي نواة الرفع المستقلة عن أي إطار: لا تشير إلى أي مساحة أسماء للمضيف، بل تأخذ الإعدادات / الترجمة / الطلب / الاستجابة / Redis / الأحداث كلها عبر منافذ `Runtime`.
> والاستثناء الوحيد هو `Install.php` —— فسكربت التثبيت الذي يُطلقه `composer require` يعمل قبل قيام تطبيق المضيف، حين لا يكون `Runtime` قد رُبط بعد؛ وفي هذه الحالة يربط نفسه احتياطيًا بمهايئ webman ويرجع إلى `config/app.php` داخل الحزمة.

> قبل التثبيت تكون هذه كلها ملفات داخل الحزمة؛ وبعد `composer require` يوزّع `Install.php` الإعدادات والأوامر وسكربتات الواجهة الأمامية وملفات اللغة إلى المواضع المقابلة في تطبيق المضيف، وفق الربط المبيّن في الجدول أعلاه.

# بنية التصميم

<img src="img/aetherupload-architecture.ar.svg" alt="مخطط بنية AetherUpload-Webman">

- **العميل**: يكفي تضمين `aetherupload-all.js` واحد (يحتوي zepto و spark-md5)، وهو يتولّى حساب md5 والتقطيع وشريط التقدّم وإعادة المحاولة التلقائية عند انقطاع الاتصال.
- **الخادم**: لا يملك `UploadController` سوى مدخلين (التهيئة، وكتابة الـ chunks)؛ ويتولّى `PartialResource` المسار والإلحاق وإعادة التسمية، بينما لا يسجّل `Header` سوى chunkIndex؛ وتُلتقط الإعدادات عبر `ConfigMapper` كلقطة لكل طلب، تفاديًا لاختلاط المجموعات بين الطلبات المتزامنة في العملية المقيمة.
- **التخزين والتشغيل**: لا توجد على القرص إلا ثلاثة أنواع من الملفات —— `*.part` للـ chunks، و `_header/` لنقاط التوقف، و `<md5>.<ext>` للملفات النهائية؛ ولا يُستخدم Redis إلا عند تفعيل الرفع الفوري؛ وعند تفعيل `x_accel_redirect` يرسل nginx الملف مباشرةً، فيوفّر بذلك دعم Range والتنزيل المُستأنَف.

# منهج التصميم

<img src="img/aetherupload-design.ar.svg" alt="منهج التصميم في AetherUpload-Webman">

إلى جانب القرارات الثلاثة في المخطط، ثمة خيار مستقل: **جعل فهرس الرفع الفوري اعتمادًا اختياريًا + TTL مستقلًا لكل سجل**. فالموقع الذي لا يثبّت Redis يظل قادرًا على الرفع (مع تعطّل الرفع الفوري وحده)؛ أما الموقع الذي يثبّته فينتهي كل سجل فيه على حدة عبر `SETEX` —— فلا تتشارك السجلات وقت انتهاء واحدًا، ولا تتضخّم بلا حدّ بسبب الرفع المتواصل؛ ويمكن بعد الترقية قراءة سجلات hash القديمة كخيار احتياطي. أما الاتساق فيُوكل إلى `aetherupload:build` (إعادة بناء يومية) و `aetherupload:clean` (استرجاع الملفات المؤقتة وفق mtime).

# دورة حياة الرفع

<img src="img/aetherupload-lifecycle.ar.svg" alt="دورة حياة الرفع في AetherUpload-Webman">

يقتصر المسار الرئيسي على أربع خطوات: **التهيئة → الـ chunks (حلقة) → التحقق من آخر chunk → الكتابة إلى القرص**. تنشئ التهيئة ملف `.part` فارغًا وتكتب `chunkIndex=0` في الـ header؛ وترتيب كل chunk ثابت: «التحقق → الإلحاق → إعادة كتابة chunkIndex»؛ ولا يجري التحقق من الحجم و MIME وإعادة حساب md5 للملف كاملًا وإعادة التسمية إلى `<md5>.<ext>` وكتابة فهرس الرفع الفوري إلا مع آخر chunk.

ويستحق مساران جانبيان توضيحًا منفصلًا:

- **إصابة الرفع الفوري**: إذا وجدت التهيئة ملفًا نهائيًا يحمل md5 نفسه، أعادت مساره مباشرةً دون رفع أي chunk.
- **الانقطاع والاستعادة**: إعادة إرسال الرقم نفسه تُتخطّى بلا أثر؛ أما القفز في الأرقام أو اقتطاع chunk فلا يعيدان سوى خطأ، **ودون تنظيف** `.part` ولا الـ header، ولذلك يستطيع العميل على شبكة ضعيفة أن يواصل إعادة المحاولة حتى يكمل الـ chunk الناقص. ولا تنظيف فعليًا إلا في حالتين —— رسوب التحقق من آخر chunk (فيتخلّى عن الملف كاملًا)، و `aetherupload:clean` الذي يشغّله cron بعد إغلاق الصفحة لاسترجاع الملفات وفق mtime.

# المزايا والخصائص
- [x] شريط تقدّم بالنسبة المئوية  
- [x] تقييد أنواع الملفات  
- [x] تقييد أحجام الملفات  
- [x] دعم تعدد اللغات  
- [x] إعداد مجموعات الموارد  
- [x] حدث اكتمال الرفع   
- [x] الرفع المتزامن *①*  
- [x] الاستئناف بعد انقطاع الاتصال *②*  
- [x] الرفع الفوري للملفات *③*  
- [x] middleware مخصّص *④*  
- [x] توجيه مخصّص   
- [x] الوضع المتساهل

*①: الرفع المتزامن أبطأ قليلًا من الرفع غير المتزامن عندما تكون سعة نطاق الرفع كبيرة، إلا أنه يدمج أجزاء الملف أثناء الرفع نفسه؛ أما الرفع غير المتزامن، ولأن ترتيب اكتمال رفع الـ chunks غير مضمون، فلا يستطيع الدمج إلا بعد اكتمالها جميعًا، ما يجعله ينتظر طويلًا قرب النهاية. ولا يرفع الرفع المتزامن سوى chunk واحد في كل مرة، فيستهلك من ذاكرة الخادم في وحدة الزمن أقلّ، ومن ثمّ يتحمّل عددًا أكبر من الرافعين في آنٍ واحد مقارنةً بالطريقة غير المتزامنة.*  

*②: الاستئناف بعد انقطاع الاتصال يختلف عن الاستئناف من نقطة التوقف؛ فالأول يعني أن مكوّن الرفع، عند انقطاع الشبكة أو اضطراب الشبكة اللاسلكية ودون إغلاق الصفحة، يعيد المحاولة تلقائيًا على فترات منتظمة، وبمجرد عودة الشبكة يستأنف الملف من الـ chunk الذي لم يكتمل رفعه. ولا سبيل إلى الاستئناف بعد تحديث الصفحة أو إغلاقها وإعادة فتحها، إذ يصبح الجزء المرفوع سابقًا ملفًا غير صالح.*  

*③: يحتاج الرفع الفوري للملفات إلى دعم Redis على الخادم ودعم المتصفح على العميل (FileReader و File.slice())، ويبطل عمله بغياب أيٍّ منهما. وهو معطّل افتراضيًا، ويجب تفعيله في ملف الإعدادات.*  

*④: بالاقتران مع middleware مخصّص، يمكن التحكم في صلاحيات الوصول إلى الموارد المرفوعة وتنزيلها.*


# الأطر المدعومة

النواة (الـ chunks، والاستئناف، والرفع الفوري، والتحقق، والعنونة) منفصلة عن إطار المضيف، والحزمة نفسها متاحة على المضيفات التالية، وكل مضيف منها مسنود باختبار end-to-end **يثبّت الإطار فعليًا ويشغّل مسار الرفع كاملًا فعليًا**:

| المضيف | طريقة الدمج | اختبار end-to-end |
|---|---|---|
| webman | دعم أصلي (`composer require` يوزّع الإعدادات/التوجيه/الأوامر/سكربتات الواجهة الأمامية تلقائيًا) | `tests/Integration/webman/` |
| **PHP الأصلي (بلا إطار)** | سطر واحد `Bootstrap::handle()`، والتوجيه توزّعه هذه الحزمة وفق الإعدادات | `tests/Integration/native/` |
| Laravel | ServiceProvider + `vendor:publish` | `tests/Integration/laravel/` |
| ThinkPHP | تسجيل Service في `app/service.php` | `tests/Integration/thinkphp/` |
| Symfony | Bundle + استيراد موارد التوجيه | `tests/Integration/symfony/` |
| Slim | سطر واحد `Bootstrap::create()` | `tests/Integration/slim/` |
| Hyperf | `ConfigProvider` | `tests/Integration/hyperf/` |
| Yii2 | تركيب Bootstrap في `bootstrap` الخاص بالتطبيق | `tests/Integration/yii/` |

> وجود `webman` في اسم الحزمة يعود إلى أسباب تاريخية (فالإضافة كانت تدعم webman وحده في البداية)، ولا يمنع ذلك استخدامها مع بقية المضيفات.

## خطوتان عامّتان

أيًّا كان المضيف، بعد تثبيت الحزمة هناك إجراءان **إلزاميان** (يكملهما سكربت التثبيت تلقائيًا في webman، أما بقية المضيفات فتحتاج إلى تشغيل الأمر المقابل يدويًا):

1. **إنشاء دليل التخزين**: `aetherupload:groups` —— ينشئ `root_dir` و `_header` وأدلة المجموعات.
   **وإغفاله يؤدّي إلى الفشل حتمًا**: فالتابع `createGroupSubDir()` في النواة هو `mkdir` غير تعاودي، ويعيد false مباشرةً عند غياب الدليل الأب، ثم تُترجَم الأخطاء كلها إلى `upload_error` العام، فيصعب عند التشخيص أن تتبيّن أن المشكلة في الدليل.
2. **نشر الملفات**: `aetherupload:publish` (وفي Laravel `vendor:publish --tag=aetherupload-*`) —— يضع ملفات اللغة و `js` الواجهة الأمامية في موضع يمكن للمضيف الوصول إليه.

> يتبع الفاصل في أسماء الأوامر اصطلاح الطرفية لدى كل إطار: webman / Laravel / ThinkPHP / Symfony / Hyperf / Slim / PHP الأصلي تستخدم `:`، و**Yii يستخدم `/`** (`php yii aetherupload/groups`). والأمثلة لكل إطار أدناه جاهزة للنسخ والتشغيل مباشرةً.

## دمج كل إطار

**PHP الأصلي (بلا إطار)**

يمكن لأي تطبيق يعمل على PHP استخدامها مباشرةً —— دون تثبيت إطار، ودون middleware، ودون تسجيل خدمة، ويكفي في سكربت الدخول سطر واحد:

```php
// public/index.php (سكربت الدخول في FPM / Apache / nginx+php-fpm)
require __DIR__ . '/../vendor/autoload.php';

exit(\AetherUpload\Adapter\Native\Bootstrap::handle([
    'base_path' => dirname(__DIR__),
    'config'    => require __DIR__ . '/../config/aetherupload.php',   // إن حُذف فتُستخدم القيم الافتراضية داخل الحزمة
    'redis'     => static fn () => new \Redis(),                       // يلزم للرفع الفوري، وهو اختياري
]));
```

```bash
php bin/aetherupload aetherupload:groups     # إنشاء الأدلة
php bin/aetherupload aetherupload:publish    # نشر ملفات اللغة و js الواجهة الأمامية
```

و `bin/aetherupload` أيضًا ثلاثة أسطر، ضع نسخة منه بنفسك:

```php
#!/usr/bin/env php
<?php
require __DIR__ . '/../vendor/autoload.php';
\AetherUpload\Adapter\Native\Bootstrap::bind(require __DIR__ . '/../config/aetherupload.php', dirname(__DIR__));
exit((new \AetherUpload\Console\Application())->run());
```

> **لا حاجة إلى كتابة التوجيه بنفسك**: يوزّع `Bootstrap::handle()` الطلب الحالي وفق `route_preprocess` / `route_uploading` / `route_display` / `route_download` في الإعدادات؛ فإن لم يُطابِق الطلب أيًّا منها فالاستجابة 404، وإن كان أسلوبه غير مناسب فالاستجابة 405 مع ترويسة `Allow`.
> **middleware**: المفاتيح `middleware_*` في الإعدادات هي في هذا المضيف **كائنات قابلة للاستدعاء دون وسائط**، وإرجاع كائن استجابة منها يُنهي المعالجة فورًا —— فإن لم تكفِ الصلاحيات فاكتب مباشرةً `return Runtime::response()->text('forbidden', 403);`، وأي قيمة أخرى تُتجاهل ويستمر التنفيذ (وهكذا يُوصَل التحكم في الصلاحيات المذكور في الحاشية الرابعة).
> **الخادم المدمج**: يكفي `php -S 127.0.0.1:8080 -t public public/index.php`. ولكي يرسل الخادم المدمج نفسه الملفات الثابتة في `public/` (ومن هذا المسار يُقدَّم js الواجهة الأمامية المنشور)، أضف قبل `handle()` السطر `if (is_file(__DIR__ . parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH))) { return false; }`.
> **بلا اعتماد على الدوال المدمجة كطريق احتياطي**: يقرأ الطلب من `$_GET`/`$_POST`/`$_FILES` (فإن PHP لا يفلتر شيئًا إضافيًا، وتبقى ضوابط الأنواع في النواة فعّالة كما هي)، وتُرسَل الاستجابة عبر `NativeResponse::send()` دفعة واحدة بـ `header()` + `echo`، دون أي طبقة وسيطة من إطار.

**Laravel**

```php
// bootstrap/providers.php（Laravel 11+）أو مصفوفة providers في config/app.php
AetherUpload\Adapter\Laravel\AetherUploadServiceProvider::class,
```
```bash
php artisan vendor:publish --tag=aetherupload-config         # لنشر config/aetherupload.php
php artisan vendor:publish --tag=aetherupload-translations   # لنشر ملفات اللغة
php artisan vendor:publish --tag=aetherupload-assets         # لنشر js الواجهة الأمامية
php artisan aetherupload:groups
```
> لا يحتوي composer.json في هذه الحزمة على `extra.laravel.providers` (اعتمادات الأطر متعارضة، فلا يمكن تثبيت الاكتشاف التلقائي فيها)، ولذلك يجب تسجيل الـ provider يدويًا.
> ودمج الإعدادات **سطحي**: بمجرد أن ينشر التطبيق `config/aetherupload.php` تحلّ `groups` فيه **محل** القيم الافتراضية للإضافة بالكامل —— فعند إضافة مجموعة جديدة انسخ المجموعات الافتراضية معها.

**ThinkPHP**

```php
// app/service.php
return [ \AetherUpload\Adapter\ThinkPhp\AetherUploadService::class ];
```
```bash
php think aetherupload:groups
php think aetherupload:publish
```
> انتبه كذلك إلى أن `groups` تُستبدَل بالكامل؛ كما أن `config/route.php` و `config/lang.php` **ملفان إلزاميان**، ويؤدي غيابهما إلى تلقّي الإطار null في `array_merge`/`array_change_key_case` ورمي TypeError.

**Symfony**

```php
// config/bundles.php
AetherUpload\Adapter\Symfony\AetherUploadBundle::class => ['all' => true],
```
```yaml
# config/routes.yaml（يجب أن يستورد التطبيق مسارات الـ bundle في Symfony، فلا توجد آلية تحميل تلقائي）
aetherupload:
    resource: '@AetherUploadBundle/Resources/config/routes.php'
    type: php
```
> تُعلن شجرة `Configuration` كل مفاتيح الإعدادات، وأي مفتاح غير معلن يُفشل إقلاع الحاوية (وليس تجاهلًا صامتًا).

**Slim**

```php
// public/index.php
$app = \AetherUpload\Adapter\Slim\Bootstrap::create([
    'config'    => require __DIR__ . '/../config/aetherupload.php',   // إن حُذف فتُستخدم القيم الافتراضية داخل الحزمة
    'base_path' => dirname(__DIR__),
    'redis'     => static fn () => new Predis\Client(['database' => 0]),   // يلزم للرفع الفوري
]);
$app->run();
```
> استجابة PSR-7 في Slim غير قابلة للتغيير، ويُحوَّل ناتج النواة إلى PSR-7 حقيقي في طبقة `Bootstrap::handler()` (**نقطة التحويل الوحيدة**، فوضعها داخل middleware لا يجدي).
> ولا يفرض Slim اصطلاحًا للطرفية، لذا توفّر هذه الحزمة أصناف مدخل جاهزة للأوامر الأربعة، ويجب أن تضع سكربت المدخل بنفسك (أربعة أسطر):
> ```php
> // bin/aetherupload
> require __DIR__ . '/../vendor/autoload.php';
> \AetherUpload\Adapter\Slim\Bootstrap::bind(require __DIR__ . '/../config/aetherupload.php', dirname(__DIR__));
> exit((new \AetherUpload\Adapter\Slim\Console\Application())->run());
> ```
> ```bash
> php bin/aetherupload aetherupload:groups     # وهناك أيضًا publish / build / clean
> ```
> يلزم `symfony/console` (وهو في require-dev / suggest لهذه الحزمة).

**Hyperf**

```php
// config/config.php —— لم تُكتب extra.hyperf.config في هذه الحزمة (لأنها تتعارض مع آليات الاكتشاف التلقائي
// في الأطر الأخرى، وستُضاف بعد التحقق منها على حدة)، ولذلك يُوسَّع ConfigProvider صراحةً هنا (فهو يوفّر التوجيه والأوامر ومفاتيح publish)
return [
    // …
] + (new \AetherUpload\Adapter\Hyperf\ConfigProvider())();
```
```bash
php bin/hyperf.php aetherupload:groups
php bin/hyperf.php aetherupload:publish
```
> يلزم `ext-swoole`. وهناك ملاحظتان أخريان في طور التطوير: الإعداد `xdebug.mode=profile` يجعل الإقلاع البارد في Hyperf **يخرج صامتًا** (exit 255 داخل `ClassLoader::init()`، دون أي خطأ PHP)، فاضبط `XDEBUG_MODE=off` في CI ومحليًا؛ كما أن `Coroutine\run()` في swoole لا يمكن أن يتشارك العملية مع PHPUnit، لذا يجب تشغيل أي اختبار يخص الـ coroutine في عملية مستقلة.

**Yii2**

```php
// إعدادات التطبيق
return [
    'bootstrap' => ['aetherupload' => \AetherUpload\Adapter\Yii\Bootstrap::class],
    'params'    => ['aetherupload' => require __DIR__ . '/aetherupload.php'],   // يمكن أن يستند إلى نموذج يولّده publish
    'components' => [
        // شرط مسبق: يجب كتابته تحت components. فالمفتاح نفسه في المستوى الأعلى يتجاهله Yii،
        // وبذلك لا يصبح enableStrictParsing فعّالًا، وتُتجاوَز قيود verb في التوجيه (فتصل طلبات GET إلى إجراءات لا تعلن سوى POST)
        'urlManager' => ['enableStrictParsing' => true],
        // يلزم للرفع الفوري؛ ويجب إعداده في تطبيق الطرفية أيضًا، وإلا أبلغ aetherupload/build عن Unknown component ID: redis
        'redis' => [ /* … */ ],
    ],
];
```
```bash
php yii aetherupload/groups     # تنبيه: فاصل الطرفية في Yii هو "/"، وكتابة aetherupload:groups تُنتج خطأ Unknown command
php yii aetherupload/publish
```
> موضع النشر هو `vendor/aetherupload/js` تحت **جذر المستند** (جذر المستند في webman هو `public/`، وفي Yii هو `web/`): فصفحة المثال تشير إلى المسار المطلق `/vendor/aetherupload/js/…`، ودلالته «جذر المستند»، ولذلك يأخذ `YiiPaths::assetPath()` الاسم المستعار `@webroot` الخاص بالمضيف أولًا، ويرجع إلى `<app>/web` عند تعذّره.

**webman**

```bash
# ينفَّذ في جذر مشروع webman
composer require erikwang2013/aetherupload-webman
```
> webman هو المضيف الوحيد **الخالي من الإعداد**: فعند `composer require` يوزّع `Install.php` الإعدادات والتوجيه والأوامر وملفات اللغة وسكربتات الواجهة الأمامية تلقائيًا، وينشئ دليل التخزين. وبعد التثبيت تكفي زيارة `http://النطاق/aetherupload` لرؤية صفحة المثال.
>
> ملاحظة: لتغيير خيارات الإعدادات ذات الصلة حرّر `config/plugin/erikwang2013/aetherupload-webman/app.php`.

> أما المضيفات السبعة الأخرى فيجب فيها تسجيل المهايئ أولًا ثم تنفيذ «الخطوتين العامّتين». و**webman يوفّر الأوامر نفسها أيضًا** (`php webman aetherupload:groups` / `aetherupload:publish`)، غير أنها نُفّذت تلقائيًا مرة واحدة عند التثبيت.

# الاستخدام  
**رفع الملفات**  

راجع ملف المثال وتعليقاته، وأدرج الملفات والشيفرة المقابلة في الصفحة التي تحتاج إلى رفع ملفات كبيرة.

**إعداد المجموعات**  

أضف مجموعة جديدة تحت `groups` في ملف إعدادات هذه الإضافة، ثم شغّل `php webman aetherupload:groups` لإنشاء الدليل المقابل تلقائيًا.  
```php
'video' => [
    'group_dir'                    => 'video',
    'resource_maxsize'             => 0,
    'resource_extensions'          => [],
    'event_before_upload_complete' => false, 
    'event_upload_complete'        => false,
],
```
حدّد مجموعة الرفع في الواجهة الأمامية باستدعاء التابع `setGroup('اسم المجموعة')`، مع الانتباه إلى أن اسم المجموعة يجب أن يكون موجودًا سابقًا، و**ألا يحتوي على شرطة سفلية** (فهو يشارك في ترميز مسار التخزين، ووجود شرطة سفلية فيه يجعل موارد تلك المجموعة غير قابلة للتعيين، فتفشل مرحلة الإعداد والرفع معًا).

**إضافة خاصية الرفع الفوري (تحتاج إلى دعم Redis والمتصفح)**  

راجع قسم Redis في توثيق Webman لتثبيت الاعتمادات اللازمة.  
ثبّت Redis وشغّل الخدمة.  
ثبّت حزمة predis: `composer require predis/predis`.  
اضبط `client` على `predis` في `config/redis.php`.  
اضبط `instant_completion` على `true` في ملف إعدادات هذه الإضافة.

*ملاحظة: تُدار في Redis قائمة للرفع الفوري تقابل ملفات الموارد الفعلية، ويجب مزامنة كل تغيير ينتج عن إضافة ملفات الموارد الفعلية أو حذفها مع قائمة الرفع الفوري، وإلا نشأت بيانات غير صالحة.  
وتتضمّن الإضافة جزء الإضافة بالفعل؛ أما عند الحاجة إلى حذف ملف مورد فيجب على المستخدم أن يستدعي التابع المقابل يدويًا لحذف الملف والسجل في قائمة الرفع الفوري.* 
```php
\AetherUpload\Util::deleteResource($savedPath); //حذف ملف المورد المقابل
\AetherUpload\Util::deleteRedisSavedPath($savedPath); //حذف سجل الرفع الفوري المقابل في Redis
``` 
*وكلاهما عملية لا أثر لها عند التكرار (idempotent): تعيد true حتى إذا كان الملف أو سجل الرفع الفوري غير موجود أصلًا.* 

**middleware مخصّص**  

راجع قسم middleware التوجيه في توثيق Webman، وأنشئ الـ middleware الخاص بك وضع اسمه في القسم المقابل من ملف الإعدادات.  
```php
'middleware_download' => [app\middleware\MiddlewareA::class,app\middleware\MiddlewareB::class],
```  
ويمكن بهذه الخاصية التحكم في صلاحيات رفع الملفات والوصول إليها وتنزيلها.

**توجيه مخصّص**  

حرّر خيارات مثل `'route_uploading' => '/aetherupload/uploading'` في ملف إعدادات هذه الإضافة، واستدعِ في الواجهة الأمامية توابع مثل `setUploadingRoute('/aetherupload/uploading')`.  
وبعد تعديل مساري الوصول إلى الملفات وتنزيلها يمكن الوصول إليهما مباشرةً دون استدعاء أي تابع في الواجهة الأمامية.
 
**حدث اكتمال الرفع**  

ينقسم إلى حدث قبل اكتمال الرفع وحدث بعده، راجع قسم Event ضمن المكوّنات الشائعة في توثيق Webman.  
اضبط صنف معالجة الحدث المقابل لـ `aetherupload.before_upload_complete` و `aetherupload.upload_complete` في `config/event.php`.  
واضبط الخيار المقابل تحت `groups` على `true` في ملف إعدادات هذه الإضافة. 
```php
'event_before_upload_complete' => true, 
'event_upload_complete'        => true,
```
ويمكن بهذه الخاصية إجراء معالجة إضافية قبل اكتمال الرفع وبعده.

**الوضع المتساهل**  

حرّر `'lax_mode' => true,` في ملف إعدادات هذه الإضافة، واستدعِ التابع `setLaxMode(true)` في الواجهة الأمامية.  
وبتخطّي حساب hash قبل الرفع يمكن تقصير الزمن الإجمالي. وبعد تفعيل هذا الخيار يتعذّر الرفع الفوري والتحقق من السلامة.

**تعدد اللغات**  

تضبطه الواجهة الأمامية تلقائيًا بعد كشف لغة المتصفح، وهو يدعم حاليًا الصينية والإنجليزية.
  
**أوامر طرفية سهلة الاستخدام**  

`php webman aetherupload:groups` يسرد كل المجموعات وينشئ أدلتها تلقائيًا  
`php webman aetherupload:build` يعيد بناء قائمة الرفع الفوري لملفات الموارد في Redis  
`php webman aetherupload:clean 2` يحذف الملفات المؤقتة غير الصالحة الأقدم من يومين  

# اقتراحات التحسين
* **(مُوصى به) إعداد حذف تلقائي يومي للملفات المؤقتة غير الصالحة**  
قد ينتهي مسار الرفع على غير المتوقع، كأن يُغلق المستخدم الصفحة أو المتصفح قسرًا أثناء النقل، فيصبح الجزء الذي تكوّن من الملف ملفًا غير صالح يشغل مساحة تخزين كبيرة؛ ويمكننا استخدام مهام crontab المجدولة لحذفها دوريًا.  
شغّل الأمر `crontab -e` في Linux وتأكد من احتواء الملف على هذا السطر:  
```php
0 0 * * * php /المسار-المطلق-لجذر-المشروع/webman aetherupload:clean 1> /dev/null 2>&1  
```  

* **إعداد إعادة بناء يومية تلقائية لقائمة الرفع الفوري في Redis**  
قد تؤدي المعالجة غير السليمة وبعض الحالات القصوى إلى ظهور بيانات غير صالحة في قائمة الرفع الفوري، فتنال من دقة خاصية الرفع الفوري؛ وإعادة بناء القائمة تزيل تلك البيانات وتعيد مزامنتها مع ملفات الموارد الفعلية.  
شغّل الأمر `crontab -e` في Linux وتأكد من احتواء الملف على هذا السطر:  
```php
0 0 * * * php /المسار-المطلق-لجذر-المشروع/webman aetherupload:build 1> /dev/null 2>&1  
```  

* **(اختياري) تفعيل إعادة التوجيه الداخلي في nginx لدعم الاستئناف من نقطة التوقف للملفات الكبيرة وتحريك الفيديو**  
لا تنفّذ استجابة الملفات في webman بروتوكول HTTP Range، فيُرسَل الملف الكبير كاملًا، ولا يمكن تحريك شريط تقدّم الفيديو، كما تُشغل عملية worker واحدة طوال مدة النقل. وإذا كان النشر على nginx، فيمكن تفعيل خيار `x_accel_redirect` في هذه الإضافة، فيتولّى nginx إرسال الملف مباشرةً وتُحرَّر عملية worker فورًا، مع دعم Range (الاستئناف من نقطة التوقف وتحريك الفيديو).  
حرّر `'x_accel_redirect' => true,` في ملف إعدادات هذه الإضافة، وأضف location للبادئة الداخلية في إعدادات nginx، مع توجيه `alias` إلى المسار المطلق لجذر الرفع في المشروع (مع الانتباه إلى الشرطة المائلة في النهاية):  
```nginx
location /internal-aetherupload/ {
    internal;
    alias /المسار-المطلق-لجذر-المشروع/storage/app/aetherupload/;
}
```
ويجب الإبقاء على `internal` في هذا location لمنع الوصول الخارجي إلى ملفات الموارد مباشرةً بتجاوز البرنامج؛ وعند تعديل `root_dir` يجب تعديل `alias` بما يوافقه.  

* **تحسين سرعة قراءة وكتابة ملفات الـ chunks المؤقتة (يسري على PHP وحده)**  
يُستفاد من نظام الملفات tmpfs في Linux لوضع ملفات الـ chunks المؤقتة المرفوعة في الذاكرة وقراءتها وكتابتها بسرعة، وذلك بمقايضة المساحة بالزمن لرفع كفاءة القراءة والكتابة، وسيُستهلك بذلك **جزء إضافي** من الذاكرة (بنحو حجم chunk واحد).  
اضبط قيمة `upload_tmp_dir` الخاص بدليل الرفع المؤقت في php.ini على `"/dev/shm"`، ثم أعد تشغيل الخدمة.  

* **تحسين سرعة قراءة وكتابة ملفات الـ chunks المؤقتة (يسري على دليل النظام المؤقت)**  
يُستفاد من نظام الملفات tmpfs في Linux لوضع ملفات الـ chunks المؤقتة المرفوعة في الذاكرة وقراءتها وكتابتها بسرعة، وذلك بمقايضة المساحة بالزمن لرفع كفاءة القراءة والكتابة، وسيُستهلك بذلك **جزء إضافي** من الذاكرة (بنحو حجم chunk واحد).  
نفّذ الأوامر التالية:    
`mkdir /dev/shm/tmp`  
`chmod 1777 /dev/shm/tmp`  
`mount --bind /dev/shm/tmp /tmp`  

# التوافق
<table>
  <th></th>
  <th>IE</th>
  <th>Edge</th>
  <th>Firefox</th>
  <th>Chrome</th>
  <th>Safari</th>
  <tr>
  <td>الرفع</td>
  <td>10+</td>
  <td>12+</td>
  <td>3.6+</td>
  <td>6+</td>
  <td>5.1+</td>
  </tr>
  <tr>
  <td>الرفع الفوري</td>
  <td>10+</td>
  <td>12+</td>
  <td>3.6+</td>
  <td>6+</td>
  <td>6+</td>
  </tr>
</table>

# الأمان
يستخدم AetherUpload قبل الرفع تصفيةً لامتدادات الملفات بصيغة قائمة بيضاء + قائمة سوداء، ثم يتحقق بعد الرفع من نوع Mime-Type للملف. فالقائمة البيضاء تحدّد مباشرةً امتداد الملف المحفوظ، والقائمة السوداء تحجب افتراضيًا امتدادات الملفات التنفيذية الشائعة، وذلك لمنع رفع الملفات الخبيثة؛ ولأغراض الأمان لا ينبغي ترك خانة القائمة البيضاء فارغة.  

ومع كل هذه الإجراءات الأمنية، لا يمكن سدّ كل الطرق أمام رفع الملفات الخبيثة، ويُنصح بضبط صلاحيات دليل الرفع ضبطًا سليمًا وبالتأكد من أن البرامج المعنية لا تملك صلاحية التنفيذ على ملفات الموارد.
