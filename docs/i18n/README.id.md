# AetherUpload-Webman  

<img src="../js/aetherupload-pet.svg" width="120" alt="Aether Beast — maskot proyek AetherUpload">

[中文](../../README.md) · [English](README.en.md) · [한국어](README.ko.md) · [Русский](README.ru.md) · [Deutsch](README.de.md) · [Français](README.fr.md) · [Español](README.es.md) · [Português](README.pt.md) · [हिन्दी](README.hi.md) · [العربية](README.ar.md) · [বাংলা](README.bn.md) · [Bahasa Indonesia](README.id.md) · [日本語](README.ja.md)

Proyek ini adalah port dari paket ekstensi unggah berkas besar Laravel yang banyak mendapat pujian, [AetherUpload-Laravel](https://github.com/peinhu/AetherUpload-Laravel), sekaligus menulis ulang pembacaan konfigurasi, penanganan konkurensi, dan lapisan penyimpanan untuk model proses persisten [webman](https://www.workerman.net/webman).

**Apa yang diselesaikannya**

Mengunggah berkas besar langsung dari browser tidak bisa lepas dari tiga masalah: berkas yang melebihi `post_max_size` tidak dapat terkirim; koneksi yang terputus memaksa memulai dari awal lagi; dan berkas yang sama dikirim berulang kali. Pendekatan AetherUpload adalah — memotong berkas menjadi chunk di browser, menambahkan setiap potongan ke sebuah berkas sementara di server, lalu menamainya dengan md5 dari isi berkas saat ditulis ke disk. Dengan begitu **unggah, lanjut-unggah saat koneksi terputus, unggah instan, deduplikasi, dan verifikasi integritas** memakai satu mekanisme yang sama; seluruh berkas tidak pernah dibaca ke memori, dan tidak diperlukan tabel basis data untuk melacak "di mana berkas berada".

**Bentuknya**

Sebuah paket composer, dengan **inti yang terpisah dari framework host**: kode yang sama dapat dipakai di webman, PHP native (tanpa framework), Laravel, ThinkPHP, Symfony, Slim, Hyperf, dan Yii2 (lihat [Framework yang Didukung](#framework-yang-didukung)). Konfigurasi, rute, perintah konsol, berkas bahasa, dan skrip frontend milik host didistribusikan oleh perintah instalasi / publikasi; tidak bergantung pada basis data; Redis hanya diperlukan saat unggah instan dinyalakan, jadi termasuk dependensi opsional.

![Halaman contoh](http://wx2.sinaimg.cn/mw690/69e23056gy1fho6ymepjlg20go0aknar.gif) 

# Struktur Proyek

```text
aetherupload-webman/
├── src/                          kode sumber plugin
│   ├── Runtime.php                 titik pengikatan host (facade statis): di tingkat proses hanya menyimpan binding yang tidak berubah; error eksplisit bila belum terikat
│   ├── RequestContext.php          status yang dapat berubah per request / per coroutine (snapshot konfigurasi grup, bahasa yang sudah dimuat); pada proses persisten request bersamaan tidak saling tertukar grupnya
│   ├── Contract/                   11 antarmuka (konfigurasi / terjemahan / request / berkas unggahan / respons / Redis / event / jalur / filesystem / konteks / adapter)
│   ├── Kernel/                     implementasi bawaan di sisi inti: AbstractAdapter, PrefixedConfig, Filesystem, NullRedis, PhpFileTranslator, ClientRedis…
│   ├── Console/                    logika bisnis tiga perintah (Runner) + pintu masuk siap pakai untuk host tanpa konvensi konsol, tujuh kerangka perintah memakai salinan yang sama
│   ├── Adapter/                    delapan adapter: Webman / Native / Laravel / ThinkPhp / Symfony / Slim / Hyperf / Yii
│   ├── UploadController.php        pintu masuk unggah: preprocess (prapemrosesan / penentuan unggah instan) dan saveChunk (penulisan chunk)
│   ├── ResourceController.php      pintu masuk tampilan dan unduhan: display / download, dapat diserahkan ke nginx untuk pengiriman langsung
│   ├── PartialResource.php         inti berkas chunk: penyusunan jalur, penambahan per chunk, penggantian nama, verifikasi ukuran dan tipe
│   ├── Header.php                  berkas status resume: hanya menyimpan satu chunkIndex
│   ├── Resource.php                objek berkas hasil, disuntikkan sebagai parameter ke event "unggah selesai"
│   ├── RedisSavedPath.php          indeks unggah instan: satu key per catatan, TTL independen
│   ├── SavedPathResolver.php       pengodean dan penafsiran jalur penyimpanan (pengalamatan tanpa status tiga segmen)
│   ├── ConfigMapper.php            singleton konfigurasi + snapshot konfigurasi grup per request
│   ├── MimeType.php                pemetaan mime ↔ ekstensi, diperiksa ulang menurut daftar putih sebelum ditulis ke disk
│   ├── Util.php                    pembuatan nama sementara, verifikasi keamanan jalur, penghapusan sumber daya
│   ├── Install.php                 instalasi / uninstalasi: mendistribusikan konfigurasi dan sumber daya ke aplikasi host
│   ├── Responser.php               pembungkus respons JSON
│   ├── SimpleValidateTrait.php     verifikasi wajib-isi yang sangat sederhana
│   ├── ExamplePageTrait.php        halaman contoh
│   └── helpers.php                 helper templat: aetherupload_display_link / aetherupload_download_link
├── commands/                     kerangka konsol webman (disalin ke app/command saat instalasi, logikanya ada di src/Console)
│   ├── AetherUploadListGroups.php        php webman aetherupload:groups   daftar semua grup sekaligus buat direktorinya
│   ├── AetherUploadBuildRedisHashes.php  php webman aetherupload:build    bangun ulang indeks unggah instan sesuai kondisi disk
│   └── AetherUploadCleanUpDirectory.php  php webman aetherupload:clean N  bersihkan berkas sementara kedaluwarsa berdasarkan mtime
├── config/
│   ├── app.php                   konfigurasi plugin webman: grup, rute, middleware, dan berbagai sakelar
│   ├── aetherupload.php          konfigurasi yang sama tanpa ketergantungan framework, menjadi basis bagi tujuh adapter lainnya (kedua jalur dikunci agar konsisten oleh ConfigParityTest)
│   └── route.php                 empat rute + titik pasang middleware masing-masing
├── docs/                         dokumentasi, gambar, dan skrip frontend
│   ├── aetherupload-architecture.svg  diagram arsitektur desain
│   ├── aetherupload-design.svg        diagram filosofi desain
│   ├── aetherupload-lifecycle.svg     diagram siklus hidup unggahan
│   ├── HARNESS.md                     kontrak pengujian unit (wajib dibaca sebelum menulis pengujian)
│   ├── REPORT.md                      laporan ringkas dari satu kali pengujian penuh beserta cakupannya
│   ├── i18n/                          terjemahan README dan ketiga diagram desain dalam berbagai bahasa (12 bahasa, navigasi terjemahan lihat TRANSLATING.md)
│   └── js/                            aset frontend (dipublikasikan ke <akar-dokumen>/vendor/aetherupload/js)
│       ├── aetherupload-all.js        versi bundel: inti + zepto + spark-md5
│       ├── aetherupload-core.js       logika inti: hitung hash, potong chunk, unggah, progres, coba ulang saat terputus
│       └── aetherupload-pet.svg       maskot proyek "Aether Beast", sekaligus dipakai sebagai ikon halaman contoh dan ikon situs
├── translations/{zh,en}/         multibahasa (dipublikasikan ke path translations host di bawah aetherupload/)
├── views/example.blade.php       kode sumber halaman contoh, dapat langsung dijadikan acuan saat integrasi
├── tests/
│   ├── *.php                     kasus uji unit PHPUnit (host memakai test double, PHP 8.0–8.4, dua versi PHPUnit: 9.6 dan 10.5)
│   └── Integration/<fw>/         rangkaian end-to-end, satu set per framework: benar-benar memasang framework dan menjalankan jalur unggah sungguhan (webman menjalankan layanan sungguhan lewat phpunit.xml, tujuh lainnya lewat ci.sh masing-masing)
├── uploads/                      direktori warisan, tidak dipakai saat plugin berjalan
└── composer.json
```

> 15 berkas di akar `src/` (controller, chunk, resume, indeks unggah instan, pengodean jalur, respons galat…) adalah inti unggah yang tidak bergantung pada framework: tidak satu pun merujuk namespace host, dan konfigurasi / terjemahan / request / respons / Redis / event semuanya mengambil port melalui `Runtime`.
> Satu-satunya pengecualian adalah `Install.php` — skrip instalasi yang dipicu oleh `composer require` berjalan sebelum aplikasi host hidup, saat `Runtime` belum terikat; dalam kondisi itu ia mengikat adapter webman sendiri sebagai cadangan dan jatuh ke `config/app.php` di dalam paket.

> Sebelum instalasi, semuanya adalah berkas di dalam paket; setelah `composer require`, `Install.php` mendistribusikan konfigurasi, perintah, skrip frontend, dan berkas bahasa ke lokasi yang sesuai di aplikasi host menurut pemetaan yang ditandai pada bagan di atas.

# Arsitektur Desain

<img src="img/aetherupload-architecture.id.svg" alt="Diagram arsitektur AetherUpload-Webman">

- **Klien**: cukup sertakan satu `aetherupload-all.js` (berisi zepto dan spark-md5); ia menangani perhitungan md5, pemotongan chunk, bilah progres, dan percobaan ulang otomatis saat koneksi terputus.
- **Sisi server**: `UploadController` hanya punya dua pintu masuk (prapemrosesan, penulisan chunk); `PartialResource` mengurus jalur, penambahan, dan penggantian nama, sedangkan `Header` hanya mencatat chunkIndex; konfigurasi di-snapshot per request oleh `ConfigMapper` agar request bersamaan pada proses persisten tidak saling tertukar grupnya.
- **Penyimpanan dan operasional**: di disk selalu hanya ada tiga jenis berkas — chunk `*.part`, resume `_header/`, dan berkas hasil `<md5>.<ext>`; Redis hanya dipakai saat unggah instan dinyalakan; setelah `x_accel_redirect` dinyalakan, berkas dikirim langsung oleh nginx, sekaligus melengkapi Range dan unduhan yang dapat dilanjutkan.

# Filosofi Desain

<img src="img/aetherupload-design.id.svg" alt="Filosofi desain AetherUpload-Webman">

Selain tiga keputusan pada diagram, ada satu pilihan yang berdiri sendiri: **indeks unggah instan dijadikan dependensi opsional + TTL independen untuk setiap catatan**. Situs yang tidak memasang Redis tetap bisa mengunggah (hanya unggah instannya yang tidak berlaku); pada situs yang memasang Redis, setiap catatan kedaluwarsa sendiri-sendiri lewat `SETEX` — waktu kedaluwarsanya tidak dipakai bersama dan tidak membengkak tanpa batas karena unggahan yang terus mengalir; setelah pemutakhiran, catatan hash lama masih dapat dibaca sebagai cadangan. Konsistensi diserahkan kepada `aetherupload:build` (dibangun ulang setiap hari) dan `aetherupload:clean` (mengumpulkan berkas sementara berdasarkan mtime).

# Siklus Hidup Unggahan

<img src="img/aetherupload-lifecycle.id.svg" alt="Siklus hidup unggahan AetherUpload-Webman">

Jalur utama hanya punya empat langkah: **preprocess → chunk (berulang) → verifikasi chunk terakhir → tulis ke disk**. Preprocess membuat `.part` kosong dan menuliskan `chunkIndex=0` ke header; urutan setiap chunk tetap "verifikasi → tambah → tulis balik chunkIndex"; hanya chunk terakhir yang diverifikasi ukuran dan MIME-nya, dihitung ulang md5 seluruh berkas, diganti namanya menjadi `<md5>.<ext>`, dan dituliskan ke indeks unggah instan.

Dua jalur samping perlu dijelaskan tersendiri:

- **Unggah instan terpenuhi**: pada tahap prapemrosesan ditemukan berkas hasil dengan md5 yang sama, jalurnya langsung dikembalikan, dan tidak ada satu chunk pun yang perlu dikirim.
- **Terputus dan pemulihan**: pengiriman ulang nomor yang sama dilewati secara idempoten; nomor yang melompat atau chunk yang terpotong hanya mengembalikan galat, **tanpa membersihkan** `.part` dan header, sehingga klien di jaringan lemah dapat terus mencoba ulang sampai chunk yang hilang terpenuhi. Yang benar-benar membersihkan hanya dua situasi — verifikasi chunk terakhir tidak lolos (seluruhnya dibuang), dan pembersihan oleh `aetherupload:clean` yang dijalankan cron berdasarkan mtime setelah halaman ditutup.

# Fitur
- [x] Bilah progres persentase  
- [x] Pembatasan jenis berkas  
- [x] Pembatasan ukuran berkas  
- [x] Dukungan multibahasa  
- [x] Konfigurasi grup sumber daya  
- [x] Event unggah selesai   
- [x] Unggah sinkron *①*  
- [x] Lanjut-unggah saat koneksi terputus *②*  
- [x] Unggah instan berkas *③*  
- [x] Middleware khusus *④*  
- [x] Rute khusus   
- [x] Mode longgar

*①: Dibandingkan unggah asinkron, unggah sinkron sedikit lebih lambat bila bandwidth unggah cukup besar, tetapi unggah sinkron dapat menggabungkan berkas selagi mengunggah, sedangkan pada unggah asinkron urutan selesainya setiap chunk tidak pasti sehingga penggabungan baru bisa dilakukan setelah semua chunk selesai — akibatnya unggah asinkron harus menunggu cukup lama saat hampir rampung. Pada unggah sinkron hanya ada satu chunk yang diunggah pada satu waktu, sehingga memori server yang terpakai per satuan waktu lebih sedikit dan jumlah orang yang dapat mengunggah bersamaan lebih banyak dibandingkan cara asinkron.*  

*②: Lanjut-unggah saat koneksi terputus berbeda dengan lanjut-unggah dari titik putus. Lanjut-unggah saat koneksi terputus berarti: ketika jaringan terputus atau jaringan nirkabel tidak stabil, selama halaman tidak ditutup, komponen unggah akan mencoba ulang secara berkala, dan begitu jaringan pulih, berkas dilanjutkan dari chunk yang belum berhasil terkirim. Jenis lanjut-unggah ini tidak dapat dilakukan setelah halaman disegarkan atau ditutup lalu dibuka kembali — bagian yang sudah terunggah sebelumnya sudah menjadi berkas tidak sah.*  

*③: Unggah instan berkas memerlukan dukungan Redis di sisi server dan dukungan browser di sisi klien (FileReader, File.slice()); bila salah satunya tidak ada, fitur unggah instan tidak akan berfungsi. Secara bawaan fitur ini mati dan harus dinyalakan di berkas konfigurasi.*  

*④: Dipadukan dengan middleware khusus, perilaku akses dan unduhan atas sumber daya yang sudah terunggah dapat dikendalikan hak aksesnya.*


# Framework yang Didukung

Inti (chunk, lanjut-unggah, unggah instan, verifikasi, pengalamatan) terpisah dari framework host; paket yang sama dapat dipakai pada host berikut, dan masing-masing dijaga oleh pengujian end-to-end yang **benar-benar memasang framework dan menjalankan seluruh jalur unggah yang sesungguhnya**:

| Host | Cara integrasi | Pengujian end-to-end |
|---|---|---|
| webman | Dukungan bawaan (`composer require` langsung mendistribusikan konfigurasi / rute / perintah / skrip frontend secara otomatis) | `tests/Integration/webman/` |
| **PHP native (tanpa framework)** | Satu baris `Bootstrap::handle()`, rute didistribusikan oleh paket ini menurut konfigurasi | `tests/Integration/native/` |
| Laravel | ServiceProvider + `vendor:publish` | `tests/Integration/laravel/` |
| ThinkPHP | Daftarkan Service di `app/service.php` | `tests/Integration/thinkphp/` |
| Symfony | Bundle + impor sumber daya rute | `tests/Integration/symfony/` |
| Slim | Satu baris `Bootstrap::create()` | `tests/Integration/slim/` |
| Hyperf | `ConfigProvider` | `tests/Integration/hyperf/` |
| Yii2 | Pasang Bootstrap di `bootstrap` aplikasi | `tests/Integration/yii/` |

> Kata `webman` pada nama paket adalah warisan sejarah (plugin ini awalnya hanya mendukung webman) dan tidak memengaruhi pemakaiannya di host lain.

## Dua Langkah Umum

Apa pun host-nya, setelah paket terpasang ada dua tindakan yang **wajib** dilakukan (pada webman keduanya dikerjakan otomatis oleh skrip instalasi; host lain harus menjalankan perintahnya sendiri secara manual):

1. **Buat direktori penyimpanan**: `aetherupload:groups` — membuat `root_dir`, `_header`, dan direktori setiap grup.
   **Tanpa ini pasti gagal**: `createGroupSubDir()` di inti adalah `mkdir` non-rekursif, sehingga bila direktori induknya tidak ada ia langsung mengembalikan false, dan galatnya diterjemahkan secara seragam menjadi `upload_error` yang umum sehingga sulit terlihat bahwa masalahnya ada di direktori.
2. **Publikasikan berkas**: `aetherupload:publish` (untuk Laravel gunakan `vendor:publish --tag=aetherupload-*`) — menaruh berkas bahasa dan `js` frontend ke lokasi yang dapat diakses host.

> Pemisah pada nama perintah mengikuti konvensi konsol masing-masing framework: webman / Laravel / ThinkPHP / Symfony / Hyperf / Slim / PHP native memakai `:`, sedangkan **Yii memakai `/`** (`php yii aetherupload/groups`). Contoh tiap framework di bawah ini dapat disalin dan dijalankan langsung.

## Integrasi per Framework

**PHP native (tanpa framework)**

Aplikasi apa pun yang berjalan di atas PHP dapat langsung memakainya — tanpa memasang framework, tanpa memasang middleware, dan tanpa mendaftarkan service; skrip pintu masuknya cukup satu baris:

```php
// public/index.php (skrip pintu masuk FPM / Apache / nginx+php-fpm)
require __DIR__ . '/../vendor/autoload.php';

exit(\AetherUpload\Adapter\Native\Bootstrap::handle([
    'base_path' => dirname(__DIR__),
    'config'    => require __DIR__ . '/../config/aetherupload.php',   // bila dihilangkan, dipakai nilai bawaan di dalam paket
    'redis'     => static fn () => new \Redis(),                       // diperlukan untuk unggah instan, opsional
]));
```

```bash
php bin/aetherupload aetherupload:groups     # buat direktori
php bin/aetherupload aetherupload:publish    # publikasikan berkas bahasa dan js frontend
```

`bin/aetherupload` juga tiga baris, cukup sediakan satu salinannya sendiri:

```php
#!/usr/bin/env php
<?php
require __DIR__ . '/../vendor/autoload.php';
\AetherUpload\Adapter\Native\Bootstrap::bind(require __DIR__ . '/../config/aetherupload.php', dirname(__DIR__));
exit((new \AetherUpload\Console\Application())->run());
```

> **Rute tidak perlu ditulis sendiri**: `Bootstrap::handle()` mendistribusikan request yang sedang berjalan menurut `route_preprocess` / `route_uploading` / `route_display` / `route_download` di berkas konfigurasi; bila tidak ada yang cocok dikembalikan 404, bila metodenya salah dikembalikan 405 beserta header `Allow`.
> **Middleware**: `middleware_*` di berkas konfigurasi pada host ini berupa **objek yang dapat dipanggil tanpa argumen**; mengembalikan objek respons berarti memutus rantai — bila hak aksesnya kurang, langsung `return Runtime::response()->text('forbidden', 403);`, sedangkan nilai kembalian lain diabaikan begitu saja dan pemrosesan dilanjutkan (begitulah kontrol hak akses yang dimaksud pada catatan kaki ④ disambungkan).
> **Server bawaan**: cukup `php -S 127.0.0.1:8080 -t public public/index.php`. Agar server bawaan mengirim sendiri berkas statis di dalam `public/` (js frontend hasil publikasi lewat jalur ini), tambahkan satu baris `if (is_file(__DIR__ . parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH))) { return false; }` sebelum `handle()`.
> **Tidak memakai fungsi bawaan sebagai cadangan**: request dibaca dari `$_GET`/`$_POST`/`$_FILES` (PHP tidak menyaringnya secara tambahan, penjaga tipe di inti tetap berlaku seperti biasa), sedangkan respons dikirim oleh `NativeResponse::send()` sekali jalan lewat `header()` + `echo`, tanpa lapisan perantara framework.

**Laravel**

```php
// bootstrap/providers.php (Laravel 11+) atau array providers di config/app.php
AetherUpload\Adapter\Laravel\AetherUploadServiceProvider::class,
```
```bash
php artisan vendor:publish --tag=aetherupload-config         # publikasikan config/aetherupload.php
php artisan vendor:publish --tag=aetherupload-translations   # publikasikan berkas bahasa
php artisan vendor:publish --tag=aetherupload-assets         # publikasikan js frontend
php artisan aetherupload:groups
```
> composer.json paket ini **tidak memuat** `extra.laravel.providers` (dependensi antarframework saling bertentangan sehingga penemuan otomatis tidak bisa dipatok), karena itu provider harus didaftarkan secara manual.
> Penggabungan konfigurasi bersifat **shallow merge**: begitu aplikasi memublikasikan `config/aetherupload.php`, `groups` di dalamnya akan **menggantikan seluruhnya** nilai bawaan plugin — saat menambah grup, salin juga grup bawaannya.

**ThinkPHP**

```php
// app/service.php
return [ \AetherUpload\Adapter\ThinkPhp\AetherUploadService::class ];
```
```bash
php think aetherupload:groups
php think aetherupload:publish
```
> Perhatikan juga bahwa `groups` digantikan seluruhnya; selain itu `config/route.php` dan `config/lang.php` adalah **berkas wajib** — bila hilang, framework akan menerima null di `array_merge` / `array_change_key_case` dan melempar TypeError.

**Symfony**

```php
// config/bundles.php
AetherUpload\Adapter\Symfony\AetherUploadBundle::class => ['all' => true],
```
```yaml
# config/routes.yaml (rute bundle Symfony wajib di-import dari sisi aplikasi, tidak ada mekanisme pemuatan otomatis)
aetherupload:
    resource: '@AetherUploadBundle/Resources/config/routes.php'
    type: php
```
> Pohon `Configuration` mendeklarasikan seluruh kunci konfigurasi; kunci yang tidak dideklarasikan akan membuat container gagal dijalankan (bukan diabaikan diam-diam).

**Slim**

```php
// public/index.php
$app = \AetherUpload\Adapter\Slim\Bootstrap::create([
    'config'    => require __DIR__ . '/../config/aetherupload.php',   // bila dihilangkan, dipakai nilai bawaan di dalam paket
    'base_path' => dirname(__DIR__),
    'redis'     => static fn () => new Predis\Client(['database' => 0]),   // diperlukan untuk unggah instan
]);
$app->run();
```
> Respons PSR-7 pada Slim bersifat immutable; hasil inti diwujudkan menjadi PSR-7 sungguhan oleh lapisan `Bootstrap::handler()` (**satu-satunya titik konversi** — bila ditaruh di middleware, ia tidak akan menembus).
> Slim tidak punya konvensi konsol; paket ini menyediakan kelas pintu masuk siap pakai untuk keempat perintah, dan skrip pintu masuknya perlu Anda tempatkan sendiri (empat baris):
> ```php
> // bin/aetherupload
> require __DIR__ . '/../vendor/autoload.php';
> \AetherUpload\Adapter\Slim\Bootstrap::bind(require __DIR__ . '/../config/aetherupload.php', dirname(__DIR__));
> exit((new \AetherUpload\Adapter\Slim\Console\Application())->run());
> ```
> ```bash
> php bin/aetherupload aetherupload:groups     # tersedia juga publish / build / clean
> ```
> Memerlukan `symfony/console` (require-dev / suggest paket ini).

**Hyperf**

```php
// config/config.php —— paket ini tidak menuliskan extra.hyperf.config (akan saling mengganggu
// dengan mekanisme penemuan otomatis framework lain, dan akan ditambahkan setelah diverifikasi
// secara terpisah), jadi ConfigProvider dibentangkan secara eksplisit di sini (rute, perintah,
// dan kunci publish semuanya berasal darinya)
return [
    // …
] + (new \AetherUpload\Adapter\Hyperf\ConfigProvider())();
```
```bash
php bin/hyperf.php aetherupload:groups
php bin/hyperf.php aetherupload:publish
```
> Memerlukan `ext-swoole`. Dua catatan lain selama pengembangan: `xdebug.mode=profile` membuat cold start Hyperf **keluar diam-diam** (exit 255 di dalam `ClassLoader::init()`, tanpa galat PHP apa pun), jadi setel `XDEBUG_MODE=off` di CI maupun di lokal; `Coroutine\run()` milik swoole tidak boleh seproses dengan PHPUnit, sehingga pengujian terkait coroutine harus menaruh probenya di proses terpisah.

**Yii2**

```php
// konfigurasi aplikasi
return [
    'bootstrap' => ['aetherupload' => \AetherUpload\Adapter\Yii\Bootstrap::class],
    'params'    => ['aetherupload' => require __DIR__ . '/aetherupload.php'],   // dapat didasarkan pada contoh yang dihasilkan publish
    'components' => [
        // prasyarat: harus ditulis di bawah components. Kunci bernama sama di tingkat atas
        // akan diabaikan Yii, sehingga enableStrictParsing tidak berlaku dan batasan verb
        // pada rute dapat dilewati (GET mengenai aksi yang hanya mendeklarasikan POST)
        'urlManager' => ['enableStrictParsing' => true],
        // diperlukan untuk unggah instan; aplikasi konsol juga harus dikonfigurasi,
        // kalau tidak aetherupload/build akan melaporkan Unknown component ID: redis
        'redis' => [ /* … */ ],
    ],
];
```
```bash
php yii aetherupload/groups     # perhatikan: pemisah konsol Yii adalah "/", menulis aetherupload:groups akan melaporkan Unknown command
php yii aetherupload/publish
```
> Lokasi publikasinya adalah `vendor/aetherupload/js` di bawah **akar dokumen** (akar dokumen webman adalah `public/`, sedangkan Yii `web/`): halaman contoh merujuk jalur absolut `/vendor/aetherupload/js/…` yang bermakna "akar dokumen", karena itu `YiiPaths::assetPath()` mengutamakan alias `@webroot` milik host dan jatuh ke `<app>/web` bila tidak didapatkan.

**webman**

```bash
# jalankan di akar proyek webman
composer require erikwang2013/aetherupload-webman
```
> webman adalah satu-satunya host yang **tanpa konfigurasi**: saat `composer require`, `Install.php` otomatis mendistribusikan konfigurasi, rute, perintah, berkas bahasa, dan skrip frontend, sekaligus menyiapkan direktori penyimpanannya. Setelah terpasang, langsung kunjungi `http://domain/aetherupload` untuk halaman contohnya.
>
> Catatan: untuk mengubah opsi konfigurasi terkait, edit `config/plugin/erikwang2013/aetherupload-webman/app.php`.

> Tujuh host lainnya harus mendaftarkan adapter terlebih dahulu, lalu menjalankan "dua langkah umum". **webman juga menyediakan perintah yang sama** (`php webman aetherupload:groups` / `aetherupload:publish`), hanya saja keduanya sudah dijalankan otomatis saat instalasi.

# Penggunaan  
**Unggah berkas**  

Rujuk berkas contoh beserta komentarnya, lalu sertakan berkas dan kode yang sesuai pada halaman yang memerlukan unggah berkas besar.

**Konfigurasi grup**  

Tambahkan grup baru di bawah `groups` pada berkas konfigurasi plugin ini, lalu jalankan `php webman aetherupload:groups` untuk membuat direktorinya secara otomatis.  
```php
'video' => [
    'group_dir'                    => 'video',
    'resource_maxsize'             => 0,
    'resource_extensions'          => [],
    'event_before_upload_complete' => false, 
    'event_upload_complete'        => false,
],
```
Di frontend, tentukan grup unggahan dengan memanggil metode `setGroup('nama grup')`. Perhatikan bahwa nama grup harus sudah ada, dan **tidak boleh mengandung garis bawah** (nama grup ikut menyusun pengodean jalur penyimpanan; adanya garis bawah membuat sumber daya pada grup tersebut tidak dapat dilacak, sehingga kegagalan sudah terjadi pada tahap konfigurasi dan unggah).

**Menambahkan fitur unggah instan (memerlukan dukungan Redis dan browser)**  

Rujuk bagian Redis pada dokumentasi webman, lalu pasang dependensi yang diperlukan.  
Pasang Redis dan jalankan layanannya.  
Pasang paket predis `composer require predis/predis`.  
Setel `client` menjadi `predis` di `config/redis.php`.  
Setel `instant_completion` menjadi `true` pada berkas konfigurasi plugin ini.

*Catatan: di Redis dipelihara sebuah daftar unggah instan yang berkorespondensi dengan berkas sumber daya yang sesungguhnya; setiap perubahan akibat penambahan atau penghapusan berkas sumber daya harus disinkronkan ke daftar unggah instan, jika tidak akan muncul data kotor.  
Paket ini sudah menangani bagian penambahannya; ketika berkas sumber daya perlu dihapus, pengguna harus memanggil sendiri metode yang sesuai untuk menghapus berkas beserta catatannya di daftar unggah instan.* 
```php
\AetherUpload\Util::deleteResource($savedPath); // menghapus berkas sumber daya terkait
\AetherUpload\Util::deleteRedisSavedPath($savedPath); // menghapus catatan unggah instan Redis terkait
``` 
*Keduanya bersifat idempoten: bila berkas atau catatan unggah instannya sudah tidak ada, hasilnya tetap true.* 

**Middleware khusus**  

Rujuk bagian middleware rute pada dokumentasi webman, buat middleware Anda, lalu isikan nama middleware tersebut pada bagian yang sesuai di berkas konfigurasi.  
```php
'middleware_download' => [app\middleware\MiddlewareA::class,app\middleware\MiddlewareB::class],
```  
Fitur ini dapat dipakai untuk mengendalikan hak akses atas unggah, akses, dan unduhan berkas.

**Rute khusus**  

Edit opsi seperti `'route_uploading' => '/aetherupload/uploading'` pada berkas konfigurasi plugin ini, lalu panggil metode seperti `setUploadingRoute('/aetherupload/uploading')` di frontend.  
Setelah rute akses dan unduhan berkas diubah, keduanya dapat langsung diakses tanpa memanggil metode frontend.
 
**Event unggah selesai**  

Terdiri atas event sebelum unggah selesai dan sesudah unggah selesai; rujuk bagian komponen umum Event pada dokumentasi webman.  
Konfigurasikan kelas penanganan event yang sesuai untuk `aetherupload.before_upload_complete` dan `aetherupload.upload_complete` di `config/event.php`.  
Setel opsi yang bersangkutan di bawah `groups` pada berkas konfigurasi plugin ini menjadi `true`. 
```php
'event_before_upload_complete' => true, 
'event_upload_complete'        => true,
```
Fitur ini dapat dipakai untuk melakukan pemrosesan tambahan sebelum atau sesudah unggah selesai.

**Mode longgar**  

Edit `'lax_mode' => true,` pada berkas konfigurasi plugin ini, lalu panggil metode `setLaxMode(true)` di frontend.  
Dengan melewati perhitungan hash sebelum unggah, total waktu tempuh dapat dipersingkat. Setelah opsi ini dinyalakan, unggah instan dan verifikasi integritas tidak dapat dilakukan.

**Multibahasa**  

Frontend mendeteksi bahasa browser lalu menyetelnya secara otomatis; saat ini mendukung bahasa Tionghoa dan Inggris.
  
**Perintah konsol yang praktis**  

`php webman aetherupload:groups` mendaftar semua grup sekaligus membuat direktorinya secara otomatis  
`php webman aetherupload:build` membangun ulang daftar unggah instan berkas sumber daya di Redis  
`php webman aetherupload:clean 2` membersihkan berkas sementara tidak sah yang berumur lebih dari 2 hari  

# Saran Optimasi
* **(Direkomendasikan) Setel pembersihan otomatis berkas sementara tidak sah setiap hari**  
Karena alur unggah bisa berhenti secara tak terduga — misalnya halaman atau browser ditutup paksa saat transfer berlangsung — bagian berkas yang sudah terbentuk menjadi berkas tidak sah yang memakan banyak ruang penyimpanan; kita dapat memakai fitur tugas terjadwal crontab untuk membersihkannya secara berkala.  
Jalankan perintah `crontab -e` di Linux, dan pastikan berkasnya memuat baris berikut:  
```php
0 0 * * * php /jalur-absolut-akar-proyek/webman aetherupload:clean 1> /dev/null 2>&1  
```  

* **Setel pembangunan ulang daftar unggah instan di Redis setiap hari**  
Penanganan yang tidak tepat dan beberapa situasi ekstrem dapat memunculkan data kotor pada daftar unggah instan sehingga ketepatan fitur unggah instan terganggu; membangun ulang daftar unggah instan menghapus data kotor tersebut dan memulihkan sinkronisasinya dengan berkas sumber daya yang sesungguhnya.  
Jalankan perintah `crontab -e` di Linux, dan pastikan berkasnya memuat baris berikut:  
```php
0 0 * * * php /jalur-absolut-akar-proyek/webman aetherupload:build 1> /dev/null 2>&1  
```  

* **(Opsional) Nyalakan pengalihan internal nginx untuk mendukung lanjut-unggah berkas besar dan geser video**  
Respons berkas webman tidak mengimplementasikan HTTP Range, sehingga unduhan berkas besar dikirim utuh, progres video tidak dapat digeser, dan satu proses worker terpakai selama seluruh transfer. Bila di-deploy di bawah nginx, opsi `x_accel_redirect` pada plugin ini dapat dinyalakan: berkas dikirim langsung oleh nginx, worker segera dilepas, dan Range didukung (lanjut-unggah, geser video).  
Edit `'x_accel_redirect' => true,` pada berkas konfigurasi plugin ini, lalu tambahkan location untuk prefiks internal di konfigurasi nginx dengan `alias` mengarah ke jalur absolut direktori akar unggahan proyek (perhatikan garis miring di akhir):  
```nginx
location /internal-aetherupload/ {
    internal;
    alias /jalur-absolut-akar-proyek/storage/app/aetherupload/;
}
```
Location tersebut wajib mempertahankan `internal` agar pihak luar tidak melewati program dan mengakses berkas sumber daya secara langsung; setelah `root_dir` diubah, `alias` perlu disesuaikan pula.  

* **Mempercepat baca-tulis berkas sementara chunk (hanya berlaku untuk PHP)**  
Manfaatkan filesystem tmpfs di Linux untuk menaruh berkas sementara chunk hasil unggahan di memori agar baca-tulisnya cepat; dengan menukar ruang dengan waktu, efisiensi baca-tulis meningkat, tetapi akan **memakai tambahan** sebagian memori (sekitar ukuran satu chunk).  
Setel nilai `upload_tmp_dir` (direktori sementara unggahan) di php.ini menjadi `"/dev/shm"`, lalu jalankan ulang layanannya.  

* **Mempercepat baca-tulis berkas sementara chunk (berlaku untuk direktori sementara sistem)**  
Manfaatkan filesystem tmpfs di Linux untuk menaruh berkas sementara chunk hasil unggahan di memori agar baca-tulisnya cepat; dengan menukar ruang dengan waktu, efisiensi baca-tulis meningkat, tetapi akan **memakai tambahan** sebagian memori (sekitar ukuran satu chunk).  
Jalankan perintah berikut:    
`mkdir /dev/shm/tmp`  
`chmod 1777 /dev/shm/tmp`  
`mount --bind /dev/shm/tmp /tmp`  

# Kompatibilitas
<table>
  <th></th>
  <th>IE</th>
  <th>Edge</th>
  <th>Firefox</th>
  <th>Chrome</th>
  <th>Safari</th>
  <tr>
  <td>Unggah</td>
  <td>10+</td>
  <td>12+</td>
  <td>3.6+</td>
  <td>6+</td>
  <td>5.1+</td>
  </tr>
  <tr>
  <td>Unggah instan</td>
  <td>10+</td>
  <td>12+</td>
  <td>3.6+</td>
  <td>6+</td>
  <td>6+</td>
  </tr>
</table>

# Keamanan
AetherUpload menyaring ekstensi berkas sebelum unggah dengan bentuk daftar putih + daftar hitam, lalu memeriksa Mime-Type berkas setelah unggah. Daftar putih secara langsung membatasi ekstensi berkas yang disimpan, sedangkan daftar hitam secara bawaan memblokir ekstensi berkas eksekutabel yang umum, guna mencegah unggahan berkas berbahaya; demi keamanan, kolom daftar putih sebaiknya tidak dibiarkan kosong.  

Meskipun banyak langkah keamanan telah dilakukan, unggahan berkas berbahaya tetap sulit dicegah sepenuhnya; disarankan menyetel izin direktori unggahan dengan benar dan memastikan program terkait tidak memiliki izin eksekusi atas berkas sumber daya.
