# AetherUpload-Webman  

<img src="../js/aetherupload-pet.svg" width="120" alt="AetherBiest — das Maskottchen des Projekts AetherUpload">

[中文](../../README.md) · [English](README.en.md) · [한국어](README.ko.md) · [Русский](README.ru.md) · [Deutsch](README.de.md) · [Français](README.fr.md) · [Español](README.es.md) · [Português](README.pt.md) · [हिन्दी](README.hi.md) · [العربية](README.ar.md) · [বাংলা](README.bn.md) · [Bahasa Indonesia](README.id.md) · [日本語](README.ja.md)

Dieses Projekt ist eine Portierung des vielbeachteten Laravel-Pakets für große Datei-Uploads [AetherUpload-Laravel](https://github.com/peinhu/AetherUpload-Laravel); für das Dauerprozess-Modell von [webman](https://www.workerman.net/webman) wurden das Lesen der Konfiguration, die Nebenläufigkeit und die Speicherschicht neu geschrieben.

**Welches Problem es löst**

Wer große Dateien direkt aus dem Browser hochlädt, kommt an drei Hürden nicht vorbei: oberhalb von `post_max_size` geht nichts mehr durch; bei einem Verbindungsabbruch beginnt alles von vorn; dieselbe Datei wird immer wieder übertragen. AetherUpload geht anders vor – die Datei wird im Browser in Chunks zerlegt, Chunk für Chunk an eine temporäre Datei auf dem Server angehängt und beim Speichern nach dem md5 des Dateiinhalts benannt. So nutzen **Upload, Fortsetzen nach Abbruch, Sofort-Upload, Dedup und Integritätsprüfung** denselben Mechanismus; die Datei wird dabei nie vollständig in den Speicher gelesen, und für die Frage „wo liegt die Datei“ ist keine Datenbanktabelle nötig.

**Wie es aufgebaut ist**

Ein Composer-Paket mit **vom Host-Framework entkoppeltem Kern**: derselbe Code läuft unter webman, nativem PHP (ohne Framework), Laravel, ThinkPHP, Symfony, Slim, Hyperf und Yii2 (siehe [Unterstützte Frameworks](#unterstützte-frameworks)). Konfiguration, Routen, Konsolenbefehle, Sprachdateien und Frontend-Skripte des Hosts werden über Installations-/Publish-Befehle verteilt; eine Datenbank wird nicht benötigt; Redis ist nur für den Sofort-Upload erforderlich und damit eine optionale Abhängigkeit.

![Beispielseite](http://wx2.sinaimg.cn/mw690/69e23056gy1fho6ymepjlg20go0aknar.gif) 

# Projektstruktur

```text
aetherupload-webman/
├── src/                          Plugin-Quellcode
│   ├── Runtime.php                 Host-Anbindungspunkt (statische Fassade): pro Prozess nur unveränderliche Bindungen, ohne Bindung ein klarer Fehler
│   ├── RequestContext.php          Veränderlicher Zustand pro Request / pro Coroutine (Konfigurations-Snapshot je Gruppe, geladene Sprachen); bei Dauerprozessen vermischen sich nebenläufige Requests nicht über Gruppen hinweg
│   ├── Contract/                   11 Interfaces (Konfiguration / Übersetzung / Request / Upload-Datei / Response / Redis / Event / Pfad / Dateisystem / Kontext / Adapter)
│   ├── Kernel/                     Standardimplementierungen der Kernseite: AbstractAdapter, PrefixedConfig, Filesystem, NullRedis, PhpFileTranslator, ClientRedis …
│   ├── Console/                    Geschäftslogik der drei Konsolenbefehle (Runner) + fertige Einstiegspunkte für Hosts ohne Konsolenkonvention; die sieben Befehlshüllen teilen sich dieselbe
│   ├── Adapter/                    acht Adapter: Webman / Native / Laravel / ThinkPhp / Symfony / Slim / Hyperf / Yii
│   ├── UploadController.php        Upload-Einstieg: preprocess (Vorverarbeitung / Sofort-Upload-Prüfung) und saveChunk (Chunk-Schreiben)
│   ├── ResourceController.php      Einstieg für Anzeige und Download: display / download, kann an nginx zur Direktauslieferung übergeben werden
│   ├── PartialResource.php         die Chunk-Datei selbst: Pfadaufbau, Anhängen Chunk für Chunk, Umbenennen, Größen- und Typprüfung
│   ├── Header.php                  Datei mit dem Fortsetzungsstatus: enthält nur einen chunkIndex
│   ├── Resource.php                Objekt der fertigen Datei, wird als Parameter in das Event „Upload abgeschlossen“ injiziert
│   ├── RedisSavedPath.php          Sofort-Upload-Index: ein Key pro Eintrag, eigenes TTL
│   ├── SavedPathResolver.php       Kodierung/Dekodierung der Speicherpfade (dreiteilige, zustandslose Adressierung)
│   ├── ConfigMapper.php            Konfigurations-Singleton + Konfigurations-Snapshot je Gruppe und Request
│   ├── MimeType.php               mime ↔ Dateiendung, vor dem Speichern erneute Prüfung gegen die Whitelist
│   ├── Util.php                    Erzeugen temporärer Namen, Pfadsicherheitsprüfung, Löschen von Ressourcen
│   ├── Install.php                 Installation / Deinstallation: verteilt Konfiguration und Ressourcen an die Host-Anwendung
│   ├── Responser.php               JSON-Response-Kapselung
│   ├── SimpleValidateTrait.php     minimalistische Pflichtfeldprüfung
│   ├── ExamplePageTrait.php        Beispielseite
│   └── helpers.php                 Template-Helfer: aetherupload_display_link / aetherupload_download_link
├── commands/                     Konsolenhüllen für webman (werden bei der Installation nach app/command kopiert, die Logik liegt in src/Console)
│   ├── AetherUploadListGroups.php        php webman aetherupload:groups   Gruppen auflisten und zugehörige Verzeichnisse anlegen
│   ├── AetherUploadBuildRedisHashes.php  php webman aetherupload:build    Sofort-Upload-Index nach dem aktuellen Festplattenstand neu aufbauen
│   └── AetherUploadCleanUpDirectory.php  php webman aetherupload:clean N  abgelaufene temporäre Dateien nach mtime aufräumen
├── config/
│   ├── app.php                   webman-Plugin-Konfiguration: Gruppen, Routen, Middleware und diverse Schalter
│   ├── aetherupload.php          dieselbe Konfiguration framework-unabhängig, als Basis für die übrigen sieben Adapter (beide Pfade sind durch ConfigParityTest fest auf Gleichheit geprüft)
│   └── route.php                 vier Routen + ihre jeweiligen Middleware-Anhängepunkte
├── docs/                         Dokumentation, Bilder und Frontend-Skripte
│   ├── aetherupload-architecture.svg  Architekturdiagramm
│   ├── aetherupload-design.svg        Diagramm der Designidee
│   ├── aetherupload-lifecycle.svg     Diagramm des Upload-Lebenszyklus
│   ├── HARNESS.md                     Vertrag der Unit-Tests (vor dem Schreiben von Tests unverzichtbar)
│   ├── REPORT.md                      Zusammenfassender Bericht über einen vollständigen Testlauf samt Coverage
│   ├── i18n/                          mehrsprachige READMEs und Übersetzungen der drei Diagramme (12 Sprachen, Hinweise zur Navigation in TRANSLATING.md)
│   └── js/                            Frontend-Ressourcen (werden nach <Document Root>/vendor/aetherupload/js veröffentlicht)
│       ├── aetherupload-all.js        gebündelte Fassung: Kern + zepto + spark-md5
│       ├── aetherupload-core.js       Kernlogik: Hash berechnen, Chunking, Upload, Fortschritt, erneuter Versuch nach Abbruch
│       └── aetherupload-pet.svg       das Projektmaskottchen „AetherBiest“, zugleich Symbol der Beispielseite und Favicon
├── translations/{zh,en}/         Mehrsprachigkeit (wird in den translations-Pfad des Hosts unter aetherupload/ veröffentlicht)
├── views/example.blade.php       Quellcode der Beispielseite, kann bei der Integration direkt als Vorlage dienen
├── tests/
│   ├── *.php                     PHPUnit-Unit-Tests (Host durch Attrappen ersetzt, PHP 8.0–8.4, PHPUnit in den Versionen 9.6 und 10.5)
│   └── Integration/<fw>/         End-to-End-Suiten, eine pro Framework: echtes Framework installiert, echte Upload-Kette durchlaufen (webman startet über phpunit.xml einen echten Dienst, die übrigen sieben nutzen ihr jeweiliges ci.sh)
├── uploads/                      Altbestand, wird zur Laufzeit des Plugins nicht verwendet
└── composer.json
```

> Die 15 Dateien direkt unter `src/` (Controller, Chunking, Fortsetzung, Sofort-Upload-Index, Pfad-Kodierung, Fehler-Response …) sind der framework-unabhängige Upload-Kern: Sie referenzieren keinen Host-Namensraum; Konfiguration / Übersetzung / Request / Response / Redis / Events laufen sämtlich über die Ports von `Runtime`.
> Einzige Ausnahme ist `Install.php` – das von `composer require` ausgelöste Installationsskript läuft, bevor die Host-Anwendung steht, und `Runtime` ist dann noch nicht gebunden. In diesem Fall bindet es ersatzweise selbst den webman-Adapter und fällt auf das paketinterne `config/app.php` zurück.

> Vor der Installation sind all dies paketinterne Dateien; nach `composer require` verteilt `Install.php` gemäß der oben angegebenen Zuordnung Konfiguration, Befehle, Frontend-Skripte und Sprachdateien an die entsprechenden Stellen der Host-Anwendung.

# Architektur

<img src="img/aetherupload-architecture.de.svg" alt="AetherUpload-Webman Architektur">

- **Client**: Es genügt, ein `aetherupload-all.js` einzubinden (enthält zepto und spark-md5). Es übernimmt md5-Berechnung, Chunking, Fortschrittsbalken und den automatischen erneuten Versuch nach einem Abbruch.
- **Serverseite**: `UploadController` hat nur zwei Einstiegspunkte (Vorverarbeitung, Chunk-Schreiben); `PartialResource` verwaltet Pfad, Anhängen und Umbenennen, `Header` notiert nur den chunkIndex; die Konfiguration wird von `ConfigMapper` pro Request als Snapshot gehalten, damit nebenläufige Requests in einem Dauerprozess nicht über Gruppen hinweg vermischt werden.
- **Speicherung und Betrieb**: Auf der Festplatte liegen immer nur drei Arten von Dateien – `*.part`-Chunks, der Fortsetzungsstand unter `_header/` und die fertigen `<md5>.<ext>`. Redis wird nur bei aktivem Sofort-Upload genutzt; ist `x_accel_redirect` aktiviert, liefert nginx die Dateien direkt aus und deckt dabei auch Range und fortgesetzten Download ab.

# Designidee

<img src="img/aetherupload-design.de.svg" alt="AetherUpload-Webman Designidee">

Neben den drei Entscheidungen im Diagramm steht eine weitere, eigenständige Wahl: **Der Sofort-Upload-Index als optionale Abhängigkeit, mit eigenem TTL pro Eintrag**. Seiten ohne Redis können weiterhin hochladen (der Sofort-Upload entfällt dann lediglich); mit Redis läuft jeder Eintrag über ein eigenes `SETEX` ab – weder teilen sich Einträge eine Ablaufzeit, noch wächst der Index durch fortlaufende Uploads unbegrenzt; nach einem Upgrade bleiben alte Hash-Einträge lesbar. Die Konsistenz übernehmen `aetherupload:build` (täglicher Neuaufbau) und `aetherupload:clean` (Rückgewinnung temporärer Dateien nach mtime).

# Upload-Lebenszyklus

<img src="img/aetherupload-lifecycle.de.svg" alt="AetherUpload-Webman Upload-Lebenszyklus">

Der Hauptpfad hat nur vier Schritte: **Vorverarbeitung → Chunking (Schleife) → Prüfung des letzten Chunks → Speichern**. Die Vorverarbeitung legt eine leere `.part`-Datei an und schreibt `chunkIndex=0` in den Header; für jeden Chunk gilt feste Reihenfolge „prüfen → anhängen → chunkIndex zurückschreiben“; nur beim letzten Chunk werden Größe und MIME geprüft, der md5 über die gesamte Datei neu berechnet, die Datei in `<md5>.<ext>` umbenannt und der Sofort-Upload-Index geschrieben.

Zwei Nebenpfade verdienen eine eigene Erläuterung:

- **Sofort-Upload-Treffer**: Findet die Vorverarbeitung zu einem md5 bereits eine fertige Datei, gibt sie deren Pfad direkt zurück – kein einziger Chunk muss übertragen werden.
- **Unterbrechung und Wiederaufnahme**: Wird dieselbe Nummer erneut gesendet, wird sie idempotent übersprungen; springt die Nummer oder ist ein Chunk abgeschnitten, kommt nur ein Fehler zurück, und `.part` samt Header werden **nicht aufgeräumt** – bei schwacher Verbindung kann der Client daher so lange erneut versuchen, bis der fehlende Chunk nachgereicht ist. Aufgeräumt wird nur in zwei Fällen: wenn die Prüfung des letzten Chunks fehlschlägt (die ganze Datei wird verworfen) und wenn nach dem Schließen der Seite ein Cron-Job `aetherupload:clean` nach mtime aufräumt.

# Funktionen
- [x] Fortschrittsbalken in Prozent  
- [x] Beschränkung der Dateitypen  
- [x] Beschränkung der Dateigröße  
- [x] Mehrsprachigkeit  
- [x] Konfiguration von Ressourcengruppen  
- [x] Event bei abgeschlossenem Upload   
- [x] Synchroner Upload *①*  
- [x] Fortsetzen nach Verbindungsabbruch *②*  
- [x] Sofort-Upload *③*  
- [x] Eigene Middleware *④*  
- [x] Eigene Routen   
- [x] Lax-Modus

*①: Der synchrone Upload ist bei ausreichender Upload-Bandbreite etwas langsamer als der asynchrone, dafür kann beim synchronen Upload die Datei während des Hochladens zusammengesetzt werden. Beim asynchronen Upload ist die Reihenfolge, in der die Chunks fertig werden, unbestimmt; zusammengesetzt werden kann erst, wenn alle Chunks vorliegen, weshalb kurz vor dem Abschluss längere Wartezeiten entstehen. Beim synchronen Upload ist immer nur ein Chunk gleichzeitig unterwegs, was pro Zeiteinheit weniger Serverspeicher belegt und damit mehr gleichzeitige Uploads erlaubt als der asynchrone Weg.*  

*②: „Fortsetzen nach Verbindungsabbruch“ ist nicht dasselbe wie „Fortsetzen ab einem Breakpoint“: Bei Ersterem versucht die Upload-Komponente bei Netzausfall oder instabilem WLAN automatisch in festen Abständen erneut, ohne dass die Seite geschlossen wird; sobald das Netz zurück ist, läuft der Upload ab dem Chunk weiter, der zuvor nicht erfolgreich übertragen wurde. Nach einem Neuladen oder Schließen der Seite ist dieses Fortsetzen nicht möglich – der bereits übertragene Teil ist dann eine ungültige Datei.*  

*③: Der Sofort-Upload setzt Redis auf dem Server und Browser-Unterstützung (FileReader, File.slice()) voraus; fehlt eines von beidem, greift die Funktion nicht. Standardmäßig ist sie aus und muss in der Konfigurationsdatei aktiviert werden.*  

*④: In Kombination mit eigener Middleware lassen sich Zugriff und Download bereits hochgeladener Ressourcen berechtigungsgesteuert einschränken.*


# Unterstützte Frameworks

Der Kern (Chunking, Fortsetzen, Sofort-Upload, Prüfung, Adressierung) ist vom Host-Framework entkoppelt; dasselbe Paket läuft unter den folgenden **Hosts**, jeder mit End-to-End-Tests abgesichert, die **das Framework wirklich installieren und die vollständige Upload-Kette wirklich durchlaufen**:

| Host | Integration | End-to-End-Test |
|---|---|---|
| webman | native Unterstützung (`composer require` verteilt Konfiguration/Routen/Befehle/Frontend-Skripte automatisch) | `tests/Integration/webman/` |
| **Natives PHP (ohne Framework)** | eine Zeile `Bootstrap::handle()`, die Routen verteilt dieses Paket anhand der Konfiguration | `tests/Integration/native/` |
| Laravel | ServiceProvider + `vendor:publish` | `tests/Integration/laravel/` |
| ThinkPHP | Service in `app/service.php` registrieren | `tests/Integration/thinkphp/` |
| Symfony | Bundle + Import der Routing-Ressource | `tests/Integration/symfony/` |
| Slim | eine Zeile `Bootstrap::create()` | `tests/Integration/slim/` |
| Hyperf | `ConfigProvider` | `tests/Integration/hyperf/` |
| Yii2 | Bootstrap im `bootstrap` der Anwendung einhängen | `tests/Integration/yii/` |

> Das `webman` im Paketnamen ist historisch bedingt (das Plugin unterstützte ursprünglich nur webman) und steht der Nutzung unter den übrigen Hosts nicht entgegen.

## Zwei generelle Schritte

Unabhängig vom Host gibt es nach der Installation des Pakets zwei **Pflichtschritte** (bei webman erledigt sie das Installationsskript automatisch, bei den übrigen Hosts führen Sie den jeweiligen Befehl selbst aus):

1. **Speicherverzeichnisse anlegen**: `aetherupload:groups` – legt `root_dir`, `_header` und die Verzeichnisse der einzelnen Gruppen an.
   **Ohne diesen Schritt schlägt es zwangsläufig fehl**: `createGroupSubDir()` im Kern ist ein nicht-rekursives `mkdir` und gibt bei fehlendem Elternverzeichnis direkt false zurück; der Fehler wird einheitlich zum pauschalen `upload_error` übersetzt, sodass bei der Fehlersuche kaum erkennbar ist, dass es am Verzeichnis liegt.
2. **Dateien veröffentlichen**: `aetherupload:publish` (unter Laravel `vendor:publish --tag=aetherupload-*`) – legt Sprachdateien und das Frontend-`js` an eine für den Host erreichbare Stelle.

> Das Trennzeichen der Befehlsnamen folgt der Konsolenkonvention des jeweiligen Frameworks: webman / Laravel / ThinkPHP / Symfony / Hyperf / Slim / natives PHP verwenden `:`, **Yii verwendet `/`** (`php yii aetherupload/groups`). Die Beispiele unten sind jeweils direkt kopierbar.

## Integration je Framework

**Natives PHP (ohne Framework)**

Jede Anwendung, die auf PHP läuft, kann das Paket direkt verwenden – kein Framework installieren, keine Middleware installieren, keinen Service registrieren; das Einstiegsskript besteht aus einer Zeile:

```php
// public/index.php (Einstiegsskript für FPM / Apache / nginx+php-fpm)
require __DIR__ . '/../vendor/autoload.php';

exit(\AetherUpload\Adapter\Native\Bootstrap::handle([
    'base_path' => dirname(__DIR__),
    'config'    => require __DIR__ . '/../config/aetherupload.php',   // ohne Angabe gelten die paketinternen Standardwerte
    'redis'     => static fn () => new \Redis(),                       // für den Sofort-Upload nötig, optional
]));
```

```bash
php bin/aetherupload aetherupload:groups     # Verzeichnisse anlegen
php bin/aetherupload aetherupload:publish    # Sprachdateien und Frontend-js veröffentlichen
```

`bin/aetherupload` umfasst ebenfalls nur drei Zeilen; legen Sie einfach eine eigene Kopie an:

```php
#!/usr/bin/env php
<?php
require __DIR__ . '/../vendor/autoload.php';
\AetherUpload\Adapter\Native\Bootstrap::bind(require __DIR__ . '/../config/aetherupload.php', dirname(__DIR__));
exit((new \AetherUpload\Console\Application())->run());
```

> **Die Routen schreiben Sie nicht selbst**: `Bootstrap::handle()` verteilt den aktuellen Request anhand von `route_preprocess` / `route_uploading` / `route_display` / `route_download` aus der Konfiguration; kein Treffer ergibt 404, eine falsche Methode 405 samt `Allow`.
> **Middleware**: Die `middleware_*`-Einträge der Konfiguration sind unter diesem Host **aufrufbare Objekte ohne Argumente**; wird ein Response-Objekt zurückgegeben, endet die Verarbeitung sofort – bei fehlender Berechtigung geben Sie direkt `return Runtime::response()->text('forbidden', 403);` zurück, jeder andere Rückgabewert wird ignoriert und die Verarbeitung läuft weiter (genau so wird die Berechtigungssteuerung aus Fußnote ④ angebunden).
> **Eingebauter Server**: `php -S 127.0.0.1:8080 -t public public/index.php` genügt. Damit der eingebaute Server die statischen Dateien aus `public/` selbst ausliefert (diesen Weg nimmt das veröffentlichte Frontend-js), ergänzen Sie vor `handle()` die Zeile `if (is_file(__DIR__ . parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH))) { return false; }`.
> **Kein Notbehelf mit eingebauten Funktionen nötig**: Der Request liest `$_GET`/`$_POST`/`$_FILES` (PHP filtert nicht zusätzlich, die Typwächter des Kerns greifen weiterhin), die Response wird von `NativeResponse::send()` in einem Zug per `header()` + `echo` gesendet – ohne Zwischenschicht eines Frameworks.

**Laravel**

```php
// bootstrap/providers.php (Laravel 11+) oder das providers-Array in config/app.php
AetherUpload\Adapter\Laravel\AetherUploadServiceProvider::class,
```
```bash
php artisan vendor:publish --tag=aetherupload-config         # veröffentlicht config/aetherupload.php
php artisan vendor:publish --tag=aetherupload-translations   # veröffentlicht die Sprachdateien
php artisan vendor:publish --tag=aetherupload-assets         # veröffentlicht das Frontend-js
php artisan aetherupload:groups
```
> Die `composer.json` dieses Pakets enthält **kein** `extra.laravel.providers` (die Abhängigkeiten der einzelnen Frameworks schließen einander aus, daher ist keine feste Auto-Discovery möglich) – der Provider muss deshalb von Hand registriert werden.
> Das Zusammenführen der Konfiguration ist eine **flache Zusammenführung**: Sobald die Anwendung `config/aetherupload.php` veröffentlicht hat, **ersetzt** deren `groups` die Standardwerte des Plugins vollständig – beim Hinzufügen von Gruppen übernehmen Sie die Standardgruppe bitte mit.

**ThinkPHP**

```php
// app/service.php
return [ \AetherUpload\Adapter\ThinkPhp\AetherUploadService::class ];
```
```bash
php think aetherupload:groups
php think aetherupload:publish
```
> Auch hier gilt: `groups` wird vollständig ersetzt; außerdem sind `config/route.php` und `config/lang.php` **erforderliche Dateien** – fehlen sie, erhält das Framework bei `array_merge`/`array_change_key_case` null und wirft einen TypeError.

**Symfony**

```php
// config/bundles.php
AetherUpload\Adapter\Symfony\AetherUploadBundle::class => ['all' => true],
```
```yaml
# config/routes.yaml (Bundle-Routen müssen in Symfony von der Anwendungsseite importiert werden, eine automatische Ladung gibt es nicht)
aetherupload:
    resource: '@AetherUploadBundle/Resources/config/routes.php'
    type: php
```
> Der `Configuration`-Baum deklariert sämtliche Konfigurationsschlüssel; nicht deklarierte Schlüssel lassen den Container beim Start scheitern (sie werden nicht stillschweigend ignoriert).

**Slim**

```php
// public/index.php
$app = \AetherUpload\Adapter\Slim\Bootstrap::create([
    'config'    => require __DIR__ . '/../config/aetherupload.php',   // ohne Angabe gelten die paketinternen Standardwerte
    'base_path' => dirname(__DIR__),
    'redis'     => static fn () => new Predis\Client(['database' => 0]),   // für den Sofort-Upload nötig
]);
$app->run();
```
> Die PSR-7-Response von Slim ist unveränderlich; die Erzeugnisse des Kerns werden erst in der Schicht `Bootstrap::handler()` zu einer echten PSR-7-Response (**dem einzigen Umwandlungspunkt** – in einer Middleware kommt man damit nicht durch).
> Slim kennt keine Konsolenkonvention; dieses Paket liefert fertige Einstiegsklassen für die vier Befehle, das Einstiegsskript legen Sie selbst an (vier Zeilen):
> ```php
> // bin/aetherupload
> require __DIR__ . '/../vendor/autoload.php';
> \AetherUpload\Adapter\Slim\Bootstrap::bind(require __DIR__ . '/../config/aetherupload.php', dirname(__DIR__));
> exit((new \AetherUpload\Adapter\Slim\Console\Application())->run());
> ```
> ```bash
> php bin/aetherupload aetherupload:groups     # außerdem publish / build / clean
> ```
> Erfordert `symfony/console` (in diesem Paket require-dev / suggest).

**Hyperf**

```php
// config/config.php —— dieses Paket schreibt kein extra.hyperf.config (das würde sich mit der
// Auto-Discovery der anderen Frameworks gegenseitig stören und wird nach separater Prüfung ergänzt),
// daher den ConfigProvider hier ausdrücklich entfalten (Routen, Befehle und Publish-Schlüssel kommen von ihm)
return [
    // …
] + (new \AetherUpload\Adapter\Hyperf\ConfigProvider())();
```
```bash
php bin/hyperf.php aetherupload:groups
php bin/hyperf.php aetherupload:publish
```
> Erfordert `ext-swoole`. Zwei weitere Hinweise aus der Entwicklungsarbeit: `xdebug.mode=profile` lässt den Kaltstart von Hyperf **still beenden** (in `ClassLoader::init()` erfolgt exit 255, ohne jede PHP-Fehlermeldung) – setzen Sie in CI und lokal `XDEBUG_MODE=off`; das `Coroutine\run()` von swoole darf nicht im selben Prozess wie PHPUnit laufen, Coroutine-Tests müssen ihre Sonde daher in einem eigenen Prozess ausführen.

**Yii2**

```php
// Anwendungskonfiguration
return [
    'bootstrap' => ['aetherupload' => \AetherUpload\Adapter\Yii\Bootstrap::class],
    'params'    => ['aetherupload' => require __DIR__ . '/aetherupload.php'],   // kann auf dem von publish erzeugten Beispiel aufsetzen
    'components' => [
        // Voraussetzung: muss unter components stehen. Gleichnamige Schlüssel auf oberster Ebene ignoriert Yii,
        // dann greift enableStrictParsing nicht, und die verb-Beschränkungen der Routen werden umgangen (GET trifft Aktionen, die nur POST deklarieren)
        'urlManager' => ['enableStrictParsing' => true],
        // für den Sofort-Upload nötig; auch die Konsolenanwendung braucht dies, sonst meldet aetherupload/build Unknown component ID: redis
        'redis' => [ /* … */ ],
    ],
];
```
```bash
php yii aetherupload/groups     # Achtung: das Konsolentrennzeichen von Yii ist "/"; aetherupload:groups meldet Unknown command
php yii aetherupload/publish
```
> Das Veröffentlichungsziel ist `vendor/aetherupload/js` unter dem **Document Root** (bei webman ist das `public/`, bei Yii `web/`): die Beispielseite referenziert den absoluten Pfad `/vendor/aetherupload/js/…`, gemeint ist damit der „Document Root“, weshalb `YiiPaths::assetPath()` bevorzugt den `@webroot`-Alias des Hosts nimmt und andernfalls auf `<app>/web` zurückfällt.

**webman**

```bash
# im Wurzelverzeichnis des webman-Projekts ausführen
composer require erikwang2013/aetherupload-webman
```
> webman ist der einzige Host, der **ohne jede Konfiguration** auskommt: `composer require` lässt `Install.php` Konfiguration, Routen, Befehle, Sprachdateien und Frontend-Skripte automatisch verteilen und die Speicherverzeichnisse anlegen. Nach der Installation ist `http://Ihre-Domain/aetherupload` direkt die Beispielseite.
>
> Hinweis: Um die zugehörigen Konfigurationsoptionen zu ändern, bearbeiten Sie `config/plugin/erikwang2013/aetherupload-webman/app.php`.

> Die übrigen sieben Hosts erfordern zuerst die Registrierung des Adapters und dann die „zwei generellen Schritte“. **webman bietet dieselben Befehle ebenfalls** (`php webman aetherupload:groups` / `aetherupload:publish`) – dort wurden sie bei der Installation nur bereits automatisch ausgeführt.

# Verwendung  
**Datei-Upload**  

Orientieren Sie sich an der Beispieldatei samt Kommentaren und binden Sie die entsprechenden Dateien und Code in die Seite ein, die große Dateien hochladen soll.

**Gruppenkonfiguration**  

Fügen Sie in der Konfigurationsdatei dieses Plugins unter `groups` eine neue Gruppe hinzu und führen Sie `php webman aetherupload:groups` aus, um das zugehörige Verzeichnis automatisch anzulegen.  
```php
'video' => [
    'group_dir'                    => 'video',
    'resource_maxsize'             => 0,
    'resource_extensions'          => [],
    'event_before_upload_complete' => false, 
    'event_upload_complete'        => false,
],
```
Im Frontend legen Sie die Upload-Gruppe über den Aufruf von `setGroup('Gruppenname')` fest. Beachten Sie, dass die Gruppe bereits existieren muss und **keinen Unterstrich enthalten darf** (der Name geht in die Kodierung des Speicherpfads ein; ein Unterstrich macht die Ressourcen dieser Gruppe unauffindbar, und schon Konfiguration und Upload-Phase schlagen fehl).

**Sofort-Upload aktivieren (erfordert Redis und Browser-Unterstützung)**  

Installieren Sie die nötigen Abhängigkeiten wie im Redis-Abschnitt der webman-Dokumentation beschrieben.  
Installieren und starten Sie Redis.  
Installieren Sie das predis-Paket: `composer require predis/predis`.  
Setzen Sie in `config/redis.php` den Wert `client` auf `predis`.  
Setzen Sie in der Konfigurationsdatei dieses Plugins `instant_completion` auf `true`.

*Hinweis: In Redis wird eine Sofort-Upload-Liste geführt, die den tatsächlichen Ressourcendateien entspricht; jede Änderung am Bestand der Ressourcendateien muss in diese Liste übernommen werden, sonst entstehen verwaiste Daten.  
Das Hinzufügen ist im Paket bereits enthalten; beim Löschen von Ressourcendateien müssen Sie die Datei und den Eintrag in der Sofort-Upload-Liste jedoch selbst über die entsprechenden Methoden entfernen.* 
```php
\AetherUpload\Util::deleteResource($savedPath); //löscht die zugehörige Ressourcendatei
\AetherUpload\Util::deleteRedisSavedPath($savedPath); //löscht den zugehörigen Redis-Eintrag für den Sofort-Upload
``` 
*Beide Operationen sind idempotent: Auch wenn Datei oder Eintrag nicht mehr existieren, wird true zurückgegeben.* 

**Eigene Middleware**  

Orientieren Sie sich am Abschnitt zu Routen-Middleware in der webman-Dokumentation, erstellen Sie Ihre Middleware und tragen Sie deren Namen im entsprechenden Teil der Konfigurationsdatei ein.  
```php
'middleware_download' => [app\middleware\MiddlewareA::class,app\middleware\MiddlewareB::class],
```  
Mit dieser Funktion lassen sich Berechtigungen für das Hochladen, den Zugriff und den Download von Dateien steuern.

**Eigene Routen**  

Bearbeiten Sie in der Konfigurationsdatei dieses Plugins Optionen wie `'route_uploading' => '/aetherupload/uploading'` und rufen Sie im Frontend Methoden wie `setUploadingRoute('/aetherupload/uploading')` auf.  
Nach einer Änderung der Routen für Dateizugriff und -download können Sie diese direkt aufrufen; ein Frontend-Aufruf ist nicht nötig.
 
**Event bei abgeschlossenem Upload**  

Es gibt ein Event vor und eines nach Abschluss des Uploads; orientieren Sie sich am Abschnitt zu Event im Bereich gängige Komponenten der webman-Dokumentation.  
Konfigurieren Sie in `config/event.php` die passenden Event-Handler-Klassen für `aetherupload.before_upload_complete` und `aetherupload.upload_complete`.  
Setzen Sie in der Konfigurationsdatei dieses Plugins die entsprechenden Optionen unter `groups` auf `true`. 
```php
'event_before_upload_complete' => true, 
'event_upload_complete'        => true,
```
Damit können Sie vor und nach Abschluss des Uploads zusätzliche Verarbeitung einhängen.

**Lax-Modus**  

Setzen Sie in der Konfigurationsdatei dieses Plugins `'lax_mode' => true,` und rufen Sie im Frontend `setLaxMode(true)` auf.  
Durch das Überspringen der Hash-Berechnung vor dem Upload verkürzt sich die Gesamtdauer. Ist diese Option aktiv, sind Sofort-Upload und Integritätsprüfung nicht möglich.

**Mehrsprachigkeit**  

Das Frontend erkennt die Browsersprache und stellt die Sprache automatisch ein; derzeit werden Chinesisch und Englisch unterstützt.
  
**Praktische Konsolenbefehle**  

`php webman aetherupload:groups` listet alle Gruppen auf und legt die zugehörigen Verzeichnisse automatisch an  
`php webman aetherupload:build` baut die Sofort-Upload-Liste der Ressourcendateien in Redis neu auf  
`php webman aetherupload:clean 2` entfernt ungültige temporäre Dateien, die älter als 2 Tage sind  

# Optimierungsempfehlungen
* **(Empfohlen) Ungültige temporäre Dateien täglich automatisch entfernen**  
Da der Upload-Ablauf unerwartet abbrechen kann – etwa wenn die Seite oder der Browser während der Übertragung gewaltsam geschlossen wird – bleiben Teile der bereits entstandenen Datei als ungültige Dateien liegen und belegen viel Speicherplatz. Mit den zeitgesteuerten Aufgaben von crontab lassen sie sich regelmäßig entfernen.  
Führen Sie unter Linux `crontab -e` aus und stellen Sie sicher, dass die Datei diese Zeile enthält:  
```php
0 0 * * * php /absoluter/Pfad/zum/Projektstammverzeichnis/webman aetherupload:clean 1> /dev/null 2>&1  
```  

* **Die Sofort-Upload-Liste in Redis täglich automatisch neu aufbauen**  
Unsachgemäße Behandlung und bestimmte Extremfälle können verwaiste Daten in der Sofort-Upload-Liste hinterlassen und damit die Genauigkeit des Sofort-Uploads beeinträchtigen. Ein Neuaufbau entfernt diese Daten und stellt die Übereinstimmung mit den tatsächlichen Ressourcendateien wieder her.  
Führen Sie unter Linux `crontab -e` aus und stellen Sie sicher, dass die Datei diese Zeile enthält:  
```php
0 0 * * * php /absoluter/Pfad/zum/Projektstammverzeichnis/webman aetherupload:build 1> /dev/null 2>&1  
```  

* **(Optional) Interne Weiterleitung von nginx aktivieren – für fortgesetzten Download großer Dateien und das Spulen in Videos**  
Die Datei-Response von webman implementiert kein HTTP-Range; große Dateien werden daher als Ganzes ausgeliefert, bei Videos lässt sich die Zeitleiste nicht verschieben, und während der gesamten Übertragung ist ein Worker-Prozess belegt. Wenn Sie unter nginx betreiben, können Sie die Option `x_accel_redirect` dieses Plugins aktivieren: Die Dateien sendet dann nginx direkt, der Worker wird sofort frei, und Range wird unterstützt (fortgesetzter Download, Spulen in Videos).  
Setzen Sie in der Konfigurationsdatei dieses Plugins `'x_accel_redirect' => true,` und ergänzen Sie in der nginx-Konfiguration eine location für das interne Präfix, deren `alias` auf den absoluten Pfad des Upload-Wurzelverzeichnisses des Projekts zeigt (auf den abschließenden Schrägstrich achten):  
```nginx
location /internal-aetherupload/ {
    internal;
    alias /absoluter/Pfad/zum/Projektstammverzeichnis/storage/app/aetherupload/;
}
```
Diese location muss `internal` behalten, damit von außen niemand unter Umgehung des Programms direkt auf die Ressourcendateien zugreift; nach einer Änderung von `root_dir` ist `alias` entsprechend anzupassen.  

* **Lese- und Schreibgeschwindigkeit der temporären Chunk-Dateien erhöhen (wirkt nur für PHP)**  
Nutzen Sie das tmpfs-Dateisystem unter Linux, um die hochgeladenen Chunk-Dateien für schnelles Lesen und Schreiben im Speicher zu halten. Raum gegen Zeit: Das erhöht die Effizienz, belegt aber **zusätzlich** Speicher (etwa die Größe eines Chunks).  
Setzen Sie in der php.ini den Wert von `upload_tmp_dir` auf `"/dev/shm"` und starten Sie den Dienst neu.  

* **Lese- und Schreibgeschwindigkeit der temporären Chunk-Dateien erhöhen (wirkt auf das temporäre Systemverzeichnis)**  
Nutzen Sie das tmpfs-Dateisystem unter Linux, um die hochgeladenen Chunk-Dateien für schnelles Lesen und Schreiben im Speicher zu halten. Raum gegen Zeit: Das erhöht die Effizienz, belegt aber **zusätzlich** Speicher (etwa die Größe eines Chunks).  
Führen Sie folgende Befehle aus:    
`mkdir /dev/shm/tmp`  
`chmod 1777 /dev/shm/tmp`  
`mount --bind /dev/shm/tmp /tmp`  

# Kompatibilität
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
  <td>Sofort-Upload</td>
  <td>10+</td>
  <td>12+</td>
  <td>3.6+</td>
  <td>6+</td>
  <td>6+</td>
  </tr>
</table>

# Sicherheit
AetherUpload filtert Dateiendungen vor dem Upload über eine Whitelist und eine Blacklist und prüft nach dem Upload zusätzlich den Mime-Type der Datei. Die Whitelist schränkt die speicherbaren Dateiendungen unmittelbar ein, die Blacklist blockiert standardmäßig die gängigen ausführbaren Dateiendungen, um das Hochladen schädlicher Dateien zu verhindern. Aus Sicherheitsgründen sollte die Whitelist nicht leer bleiben.  

So viel Sicherheitsarbeit auch geleistet wurde – schädliche Datei-Uploads lassen sich kaum vollständig abwehren. Setzen Sie daher die Berechtigungen des Upload-Verzeichnisses korrekt und stellen Sie sicher, dass die beteiligten Programme auf den Ressourcendateien keine Ausführungsrechte haben.
