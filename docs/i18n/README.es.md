# AetherUpload-Webman  

<img src="../js/aetherupload-pet.svg" width="120" alt="Aether — mascota del proyecto AetherUpload">

[中文](../../README.md) · [English](README.en.md) · [한국어](README.ko.md) · [Русский](README.ru.md) · [Deutsch](README.de.md) · [Français](README.fr.md) · [Español](README.es.md) · [Português](README.pt.md) · [हिन्दी](README.hi.md) · [العربية](README.ar.md) · [বাংলা](README.bn.md) · [Bahasa Indonesia](README.id.md) · [日本語](README.ja.md)

Este proyecto es un port del aclamado paquete de Laravel para subir archivos grandes [AetherUpload-Laravel](https://github.com/peinhu/AetherUpload-Laravel), con la lectura de configuración, el manejo de concurrencia y la capa de almacenamiento reescritos para el modelo de proceso residente de [webman](https://www.workerman.net/webman).

**Qué resuelve**

Subir archivos grandes directamente desde el navegador choca siempre con tres problemas: si se supera `post_max_size` la subida no pasa; si se corta la red hay que empezar de cero; y el mismo archivo se transmite una y otra vez. El enfoque de AetherUpload es trocear el archivo en el navegador, anexar cada bloque a un archivo temporal del servidor y, al volcarlo a disco, nombrarlo con el md5 del contenido. Así, **subida, reanudación tras cortes, subida instantánea, deduplicación y verificación de integridad** comparten un mismo mecanismo, sin leer nunca el archivo completo en memoria ni mantener una tabla de base de datos para saber «dónde está el archivo».

**Su forma**

Un paquete de composer con **el núcleo desacoplado del framework anfitrión**: el mismo código funciona en webman, Laravel, ThinkPHP, Symfony, Slim, Hyperf e Yii2 (ver [Frameworks compatibles](#frameworks-compatibles)). La configuración, las rutas, los comandos de consola, los archivos de idioma y los scripts de frontend del anfitrión se distribuyen mediante los comandos de instalación / publicación; no depende de ninguna base de datos; Redis solo hace falta si se activa la subida instantánea, así que es una dependencia opcional.

![Página de ejemplo](http://wx2.sinaimg.cn/mw690/69e23056gy1fho6ymepjlg20go0aknar.gif) 

# Estructura del proyecto

```text
aetherupload-webman/
├── src/                          Código fuente del plugin
│   ├── Runtime.php                 Punto de enlace del anfitrión (fachada estática): a nivel de proceso solo guarda enlaces inmutables y falla de forma explícita si no hay ninguno
│   ├── RequestContext.php          Estado mutable por petición / por corrutina (instantánea de la configuración de grupo, idiomas ya cargados); con procesos residentes, las peticiones concurrentes no mezclan grupos
│   ├── Contract/                   11 interfaces (configuración / traducción / petición / archivo subido / respuesta / Redis / eventos / rutas / sistema de archivos / contexto / adaptador)
│   ├── Kernel/                     Implementaciones por defecto del lado del núcleo: AbstractAdapter, PrefixedConfig, Filesystem, NullRedis, NullEventDispatcher…
│   ├── Console/                    Lógica de negocio de los tres comandos de consola (Runner); las envolturas de los siete frameworks comparten una sola copia
│   ├── Adapter/                    Siete adaptadores: Webman / Laravel / ThinkPhp / Symfony / Slim / Hyperf / Yii
│   ├── UploadController.php        Entrada de subida: preprocess (preprocesado / decisión de subida instantánea) y saveChunk (escritura por bloques)
│   ├── ResourceController.php      Entrada de visualización y descarga: display / download; admite delegar el envío a nginx
│   ├── PartialResource.php         El archivo por bloques en sí: composición de la ruta, anexado bloque a bloque, renombrado, verificación de tamaño y tipo
│   ├── Header.php                  Archivo de estado de la reanudación: solo guarda un chunkIndex
│   ├── Resource.php                Objeto del archivo final, inyectado como parámetro en el evento «subida completada»
│   ├── RedisSavedPath.php          Índice de subida instantánea: un key por registro, con TTL independiente
│   ├── SavedPathResolver.php       Codificación / decodificación de la ruta de almacenamiento (direccionamiento sin estado en tres tramos)
│   ├── ConfigMapper.php            Singleton de configuración + instantánea de la configuración de grupo por petición
│   ├── MimeType.php                Mapa mime ↔ extensión; antes de escribir en disco se revisa contra la lista blanca
│   ├── Util.php                    Generación de nombres temporales, validación de seguridad de rutas, borrado de recursos
│   ├── Install.php                 Instalación / desinstalación: distribuye configuración y recursos a la aplicación anfitriona
│   ├── Responser.php               Envoltura de respuestas JSON
│   ├── SimpleValidateTrait.php     Validación mínima de campos obligatorios
│   ├── ExamplePageTrait.php        Página de ejemplo
│   └── helpers.php                 Ayudantes de plantilla: aetherupload_display_link / aetherupload_download_link
├── commands/                     Envolturas de consola para webman (al instalar se copian a app/command; la lógica está en src/Console)
│   ├── AetherUploadListGroups.php        php webman aetherupload:groups   Lista los grupos y crea sus directorios
│   ├── AetherUploadBuildRedisHashes.php  php webman aetherupload:build    Reconstruye el índice de subida instantánea según el estado del disco
│   └── AetherUploadCleanUpDirectory.php  php webman aetherupload:clean N  Elimina archivos temporales caducados según su mtime
├── config/
│   ├── app.php                   Configuración del plugin de webman: grupos, rutas, middleware y los distintos interruptores
│   ├── aetherupload.php          Esa misma configuración, independiente del framework, que sirve de base a los otros seis adaptadores (ConfigParityTest fija que ambas rutas coincidan)
│   └── route.php                 Cuatro rutas + sus puntos de enganche de middleware
├── docs/                         Documentación, imágenes y scripts de frontend
│   ├── aetherupload-architecture.svg  Diagrama de arquitectura
│   ├── aetherupload-design.svg        Diagrama de la idea de diseño
│   ├── aetherupload-lifecycle.svg     Diagrama del ciclo de vida de la subida
│   ├── HARNESS.md                     Contrato de las pruebas unitarias (lectura obligatoria antes de escribir pruebas)
│   ├── REPORT.md                      Informe resumen de una ejecución completa de pruebas y cobertura
│   ├── i18n/                          El README y los tres diagramas de diseño traducidos a otros idiomas (12 idiomas; para navegar entre traducciones, ver TRANSLATING.md)
│   └── js/                            Recursos de frontend (se publican en <raíz de documentos>/vendor/aetherupload/js)
│       ├── aetherupload-all.js        Versión empaquetada: núcleo + zepto + spark-md5
│       ├── aetherupload-core.js       Lógica del núcleo: calcular el hash, trocear, subir, progreso, reintento tras un corte
│       └── aetherupload-pet.svg       La mascota del proyecto, «Aether»; sirve a la vez de icono de la página de ejemplo y de icono del sitio
├── translations/{zh,en}/         Multiidioma (se publica en la ruta translations del anfitrión, bajo aetherupload/)
├── views/example.blade.php       Código fuente de la página de ejemplo, listo para consultar al integrar
├── tests/
│   ├── *.php                     Casos unitarios de PHPUnit (con dobles del anfitrión, PHP 8.0–8.4, PHPUnit 9.6 y 10.5)
│   └── Integration/<fw>/         Suites de extremo a extremo, una por framework: se instala el framework de verdad y se ejecuta la cadena de subida real (webman levanta un servicio real con phpunit.xml; los otros seis usan su propio ci.sh)
├── uploads/                      Directorio heredado; el plugin no lo usa en tiempo de ejecución
└── composer.json
```

> Los 15 archivos de la raíz de `src/` (controladores, bloques, puntos de reanudación, índice de subida instantánea, codificación de rutas, respuestas de error…) son el núcleo de subida independiente del framework: no referencian ningún espacio de nombres del anfitrión; la configuración / traducción / petición / respuesta / Redis / eventos se obtienen siempre a través de los puertos de `Runtime`.
> La única excepción es `Install.php` — el script de instalación que dispara `composer require` se ejecuta antes de que arranque la aplicación anfitriona, cuando `Runtime` todavía no está enlazado; en ese caso enlaza por su cuenta el adaptador de webman como respaldo y recurre al `config/app.php` incluido en el paquete.

> Antes de instalar, todos estos son archivos del paquete; después de `composer require`, `Install.php` distribuye la configuración, los comandos, los scripts de frontend y los archivos de idioma a las posiciones correspondientes de la aplicación anfitriona, siguiendo el mapeo indicado en la tabla anterior.

# Arquitectura del diseño

<img src="img/aetherupload-architecture.es.svg" alt="Diagrama de arquitectura de AetherUpload-Webman">

- **Cliente**: basta con incluir un `aetherupload-all.js` (lleva dentro zepto y spark-md5); se encarga de calcular el md5, trocear, la barra de progreso y el reintento automático tras un corte.
- **Servidor**: `UploadController` solo tiene dos entradas (preprocesado y escritura por bloques); `PartialResource` gestiona la ruta, el anexado y el renombrado, y `Header` solo registra el chunkIndex; la configuración se copia por petición mediante `ConfigMapper`, para que bajo un proceso residente las peticiones concurrentes no mezclen grupos.
- **Almacenamiento y operación**: en el disco solo hay siempre tres tipos de archivo: bloques `*.part`, puntos de reanudación en `_header/` y resultados `<md5>.<ext>`; Redis solo se usa si se activa la subida instantánea; con `x_accel_redirect` activado, nginx envía los archivos directamente y de paso cubre Range y la descarga reanudable.

# Idea de diseño

<img src="img/aetherupload-design.es.svg" alt="Idea de diseño de AetherUpload-Webman">

Además de las tres decisiones del diagrama, hay una elección independiente: **el índice de subida instantánea es una dependencia opcional + cada registro tiene su propio TTL**. Un sitio sin Redis sigue pudiendo subir (solo se pierde la subida instantánea); en un sitio con Redis, cada registro caduca por su cuenta con `SETEX`, de modo que no comparten fecha de caducidad ni crecen sin límite por subir archivos sin parar; y tras una actualización, los registros hash antiguos todavía se pueden leer como respaldo. La consistencia queda en manos de `aetherupload:build` (reconstrucción diaria) y `aetherupload:clean` (recupera archivos temporales según su mtime).

# Ciclo de vida de la subida

<img src="img/aetherupload-lifecycle.es.svg" alt="Ciclo de vida de la subida de AetherUpload-Webman">

La ruta principal tiene solo cuatro pasos: **preprocesado → bloques (bucle) → verificación del último bloque → volcado a disco**. El preprocesado crea un `.part` vacío y escribe `chunkIndex=0` en el header; el orden de cada bloque es fijo: «verificar → anexar → reescribir chunkIndex»; solo el último bloque verifica el tamaño y el MIME, recalcula el md5 completo, renombra a `<md5>.<ext>` y escribe el índice de subida instantánea.

Dos caminos alternativos merecen explicación aparte:

- **Acierto de subida instantánea**: durante el preprocesado se detecta que ya existe un resultado con el mismo md5, así que se devuelve su ruta directamente y no hay que subir ni un bloque.
- **Interrupción y recuperación**: reenviar el mismo número de secuencia se omite de forma idempotente; un salto en la secuencia o un bloque truncado solo devuelven error y **no limpian** el `.part` ni el header, así que con una red mala el cliente puede seguir reintentando hasta completar el bloque que falta. Solo hay dos casos que sí limpian: que la verificación del último bloque no pase (se descarta todo) y que, tras cerrar la página, cron ejecute `aetherupload:clean` para recuperar según el mtime.

# Características
- [x] Barra de progreso porcentual  
- [x] Restricción de tipo de archivo  
- [x] Restricción de tamaño de archivo  
- [x] Soporte multiidioma  
- [x] Configuración de grupos de recursos  
- [x] Evento al completar la subida   
- [x] Subida síncrona *①*  
- [x] Reanudación tras cortes *②*  
- [x] Subida instantánea de archivos *③*  
- [x] Middleware personalizado *④*  
- [x] Rutas personalizadas   
- [x] Modo permisivo

*①: frente a la subida asíncrona, la síncrona es algo más lenta cuando el ancho de banda de subida es suficientemente grande, pero la síncrona puede ir ensamblando el archivo a la vez que sube, mientras que la asíncrona, al no conocerse el orden en que terminan los bloques, solo puede ensamblar cuando están todos, lo que obliga a esperar bastante cerca del final. La subida síncrona solo tiene un bloque en vuelo cada vez, así que por unidad de tiempo ocupa menos memoria del servidor y admite a más gente subiendo a la vez que el modo asíncrono.*  

*②: la reanudación tras cortes no es lo mismo que la descarga reanudable: se refiere a que, si se cae la red o el wifi va inestable y no se cierra la página, el componente de subida reintenta solo cada cierto tiempo y, en cuanto vuelve la red, el archivo continúa desde el bloque que no llegó a subirse. Si se recarga la página o se cierra y se vuelve a abrir, ya no se puede reanudar: lo subido antes queda como archivo inválido.*  

*③: la subida instantánea de archivos necesita Redis en el servidor y soporte del navegador (FileReader, File.slice()); si falta cualquiera de los dos, no funciona. Está desactivada por defecto y hay que activarla en el archivo de configuración.*  

*④: junto con middleware personalizado, permite controlar por permisos el acceso y la descarga de los recursos ya subidos.*


# Frameworks compatibles

El núcleo (bloques, reanudación, subida instantánea, verificación, direccionamiento) está desacoplado del framework anfitrión; el mismo paquete funciona con los siguientes frameworks, y cada uno tiene pruebas de extremo a extremo que **instalan el framework de verdad y ejecutan la cadena de subida completa**:

| Framework | Forma de integración | Pruebas de extremo a extremo |
|---|---|---|
| webman | Soporte nativo (`composer require` ya distribuye configuración / rutas / comandos / scripts de frontend) | `tests/Integration/webman/` |
| Laravel | ServiceProvider + `vendor:publish` | `tests/Integration/laravel/` |
| ThinkPHP | Registrar el Service en `app/service.php` | `tests/Integration/thinkphp/` |
| Symfony | Bundle + importación de recursos de rutas | `tests/Integration/symfony/` |
| Slim | Una línea: `Bootstrap::create()` | `tests/Integration/slim/` |
| Hyperf | `ConfigProvider` | `tests/Integration/hyperf/` |
| Yii2 | Enganchar el Bootstrap en el `bootstrap` de la aplicación | `tests/Integration/yii/` |

> El `webman` del nombre del paquete es por razones históricas (al principio el plugin solo soportaba webman) y no impide usarlo con los demás frameworks.

## Dos pasos comunes

Sea cual sea el framework, después de instalar el paquete hay dos acciones **obligatorias** (en webman las hace solo el script de instalación; en los demás frameworks hay que ejecutar a mano el comando correspondiente):

1. **Crear los directorios de almacenamiento**: `aetherupload:groups` — crea `root_dir`, `_header` y los directorios de cada grupo.
   **Si no se crean, falla seguro**: el `createGroupSubDir()` del núcleo es un `mkdir` no recursivo y devuelve false directamente cuando falta el directorio padre, y el error se traduce siempre a un genérico `upload_error`, así que al depurar cuesta ver que el problema son los directorios.
2. **Publicar archivos**: `aetherupload:publish` (en Laravel, `vendor:publish --tag=aetherupload-*`) — deja los archivos de idioma y el `js` de frontend en una ubicación accesible para el anfitrión.

> El separador del nombre del comando sigue la convención de consola de cada framework: webman / Laravel / ThinkPHP / Symfony / Hyperf / Slim usan `:`, y **Yii usa `/`** (`php yii aetherupload/groups`). Los ejemplos de cada uno de los de abajo se pueden copiar y ejecutar tal cual.

## Integración por framework

**Laravel**

```php
// bootstrap/providers.php (Laravel 11+) o el array providers de config/app.php
AetherUpload\Adapter\Laravel\AetherUploadServiceProvider::class,
```
```bash
php artisan vendor:publish --tag=aetherupload-config         # publica config/aetherupload.php
php artisan vendor:publish --tag=aetherupload-translations   # publica los archivos de idioma
php artisan vendor:publish --tag=aetherupload-assets         # publica el js de frontend
php artisan aetherupload:groups
```
> El composer.json de este paquete **no incluye** `extra.laravel.providers` (las dependencias de los seis frameworks son mutuamente excluyentes, así que no se puede fijar el descubrimiento automático), por lo que hay que registrar el provider a mano.
> La fusión de configuración es **superficial**: en cuanto la aplicación publica `config/aetherupload.php`, su `groups` **reemplaza por completo** los valores por defecto del plugin; al añadir un grupo, copie también los grupos por defecto.

**ThinkPHP**

```php
// app/service.php
return [ \AetherUpload\Adapter\ThinkPhp\AetherUploadService::class ];
```
```bash
php think aetherupload:groups
php think aetherupload:publish
```
> Ojo igualmente con que `groups` se reemplaza por completo; además, `config/route.php` y `config/lang.php` son **archivos obligatorios**: si faltan, el framework recibe null en `array_merge`/`array_change_key_case` y lanza un TypeError.

**Symfony**

```php
// config/bundles.php
AetherUpload\Adapter\Symfony\AetherUploadBundle::class => ['all' => true],
```
```yaml
# config/routes.yaml (las rutas de un bundle de Symfony las tiene que importar la aplicación; no hay carga automática)
aetherupload:
    resource: '@AetherUploadBundle/Resources/config/routes.php'
    type: php
```
> El árbol de `Configuration` declara todas las claves de configuración; una clave no declarada hace fallar el arranque del contenedor (no se ignora en silencio).

**Slim**

```php
// public/index.php
$app = \AetherUpload\Adapter\Slim\Bootstrap::create([
    'config'    => require __DIR__ . '/../config/aetherupload.php',   // si se omite, se usan los valores por defecto del paquete
    'base_path' => dirname(__DIR__),
    'redis'     => static fn () => new Predis\Client(['database' => 0]),   // lo necesita la subida instantánea
]);
$app->run();
```
> Las respuestas PSR-7 de Slim son inmutables; el resultado del núcleo se convierte en PSR-7 real en la capa de `Bootstrap::handler()` (**el único punto de conversión**; si se pone dentro de un middleware, no atraviesa).
> Slim no tiene convención de consola; este paquete ofrece las clases de entrada ya listas para los cuatro comandos, pero el script de entrada hay que ponerlo uno mismo (cuatro líneas):
> ```php
> // bin/aetherupload
> require __DIR__ . '/../vendor/autoload.php';
> \AetherUpload\Adapter\Slim\Bootstrap::bind(require __DIR__ . '/../config/aetherupload.php', dirname(__DIR__));
> exit((new \AetherUpload\Adapter\Slim\Console\Application())->run());
> ```
> ```bash
> php bin/aetherupload aetherupload:groups     # también hay publish / build / clean
> ```
> Requiere `symfony/console` (require-dev / suggest de este paquete).

**Hyperf**

```php
// config/config.php — este paquete no escribe extra.hyperf.config (interferiría con los
// mecanismos de descubrimiento automático de los otros frameworks; se añadirá cuando se
// valide por separado), así que aquí se expande explícitamente el ConfigProvider (de él
// salen las rutas, los comandos y las claves de publish)
return [
    // …
] + (new \AetherUpload\Adapter\Hyperf\ConfigProvider())();
```
```bash
php bin/hyperf.php aetherupload:groups
php bin/hyperf.php aetherupload:publish
```
> Requiere `ext-swoole`. Otros dos puntos a tener en cuenta en desarrollo: `xdebug.mode=profile` hace que Hyperf **salga en silencio** al arrancar en frío (`exit 255` dentro de `ClassLoader::init()`, sin ningún error de PHP), así que tanto en CI como en local conviene poner `XDEBUG_MODE=off`; y el `Coroutine\run()` de swoole no puede convivir con PHPUnit en el mismo proceso, de modo que las pruebas de corrutinas tienen que lanzar la sonda en un proceso aparte.

**Yii2**

```php
// Configuración de la aplicación
return [
    'bootstrap' => ['aetherupload' => \AetherUpload\Adapter\Yii\Bootstrap::class],
    'params'    => ['aetherupload' => require __DIR__ . '/aetherupload.php'],   // puede basarse en el ejemplo que genera publish
    'components' => [
        // Requisito previo: tiene que ir dentro de components. Una clave con el mismo nombre
        // en el nivel superior la ignora Yii, con lo que enableStrictParsing no surte efecto
        // y se saltan las restricciones de verbo de las rutas (un GET llega a una acción
        // declarada solo como POST)
        'urlManager' => ['enableStrictParsing' => true],
        // lo necesita la subida instantánea; la aplicación de consola también tiene que configurarlo,
        // si no aetherupload/build da Unknown component ID: redis
        'redis' => [ /* … */ ],
    ],
];
```
```bash
php yii aetherupload/groups     # ojo: el separador de consola de Yii es "/"; si se escribe aetherupload:groups da Unknown command
php yii aetherupload/publish
```
> El destino de la publicación es `vendor/aetherupload/js` bajo la **raíz de documentos** (la de webman es `public/` y la de Yii es `web/`): la página de ejemplo referencia la ruta absoluta `/vendor/aetherupload/js/…`, cuya semántica es «raíz de documentos», así que `YiiPaths::assetPath()` toma primero el alias `@webroot` del anfitrión y, si no lo encuentra, recurre a `<app>/web`.

**webman**

```bash
# ejecutar en la raíz del proyecto webman
composer require erikwang2013/aetherupload-webman
```
> webman es el único anfitrión **sin configuración**: al hacer `composer require`, `Install.php` distribuye automáticamente la configuración, las rutas, los comandos, los archivos de idioma y los scripts de frontend, y deja creados los directorios de almacenamiento. Al terminar, visitar `http://dominio/aetherupload` muestra la página de ejemplo.
>
> Nota: para cambiar las opciones de configuración, edite `config/plugin/erikwang2013/aetherupload-webman/app.php`.

> Los otros seis frameworks tienen que registrar primero el adaptador y luego ejecutar los «dos pasos comunes». **webman también ofrece los mismos comandos** (`php webman aetherupload:groups` / `aetherupload:publish`), solo que ya se han ejecutado solos durante la instalación.

# Uso  
**Subida de archivos**  

Consulte el archivo de ejemplo y sus comentarios, e incluya el archivo y el código correspondientes en la página donde se suban archivos grandes.

**Configuración de grupos**  

Añada un grupo bajo `groups` en el archivo de configuración de este plugin y ejecute `php webman aetherupload:groups` para crear su directorio automáticamente.  
```php
'video' => [
    'group_dir'                    => 'video',
    'resource_maxsize'             => 0,
    'resource_extensions'          => [],
    'event_before_upload_complete' => false, 
    'event_upload_complete'        => false,
],
```
En el frontend, indique el grupo de subida llamando a `setGroup('nombre del grupo')`; tenga en cuenta que el grupo tiene que existir ya y que **no puede contener guiones bajos** (participa en la codificación de la ruta de almacenamiento, y un guion bajo haría que los recursos de ese grupo no se pudieran localizar: fallaría ya en la configuración y en la fase de subida).

**Añadir la subida instantánea (requiere Redis y soporte del navegador)**  

Consulte la sección de Redis de la documentación de webman e instale las dependencias necesarias.  
Instale Redis y arranque el servicio.  
Instale el paquete predis: `composer require predis/predis`.  
En `config/redis.php`, ponga `client` a `predis`.  
En el archivo de configuración de este plugin, ponga `instant_completion` a `true`.

*Nota: en Redis se mantiene una lista de subida instantánea que se corresponde con los archivos de recursos reales; cualquier alta o baja de archivos reales hay que sincronizarla con esa lista, o quedarán datos sucios.  
El paquete ya cubre la parte de alta; cuando haya que borrar un archivo de recurso, es quien lo use quien debe llamar a los métodos correspondientes para borrar el archivo y el registro de la lista de subida instantánea.* 
```php
\AetherUpload\Util::deleteResource($savedPath); //borra el archivo de recurso correspondiente
\AetherUpload\Util::deleteRedisSavedPath($savedPath); //borra el registro de subida instantánea de Redis correspondiente
``` 
*Ambas son operaciones idempotentes: si el archivo o el registro de subida instantánea ya no existen, también devuelven true.* 

**Middleware personalizado**  

Consulte la sección de middleware de rutas de la documentación de webman, cree su middleware y escriba el nombre del middleware que haya creado en la parte correspondiente del archivo de configuración.  
```php
'middleware_download' => [app\middleware\MiddlewareA::class,app\middleware\MiddlewareB::class],
```  
Con esta función puede controlar por permisos la subida, el acceso y la descarga de archivos.

**Rutas personalizadas**  

Edite opciones como `'route_uploading' => '/aetherupload/uploading'` en el archivo de configuración de este plugin y llame en el frontend a métodos como `setUploadingRoute('/aetherupload/uploading')`.  
Las rutas de acceso y descarga, una vez editadas, se pueden visitar directamente, sin llamar a ningún método del frontend.
 
**Evento al completar la subida**  

Hay eventos de antes y de después de completar la subida; consulte la sección Event de los componentes habituales en la documentación de webman.  
En `config/event.php`, configure las clases manejadoras correspondientes para `aetherupload.before_upload_complete` y `aetherupload.upload_complete`.  
En el archivo de configuración de este plugin, ponga a `true` las opciones correspondientes bajo `groups`. 
```php
'event_before_upload_complete' => true, 
'event_upload_complete'        => true,
```
Con esta función puede hacer un procesamiento adicional antes y después de completar la subida.

**Modo permisivo**  

Edite `'lax_mode' => true,` en el archivo de configuración de este plugin y llame en el frontend a `setLaxMode(true)`.  
Al saltarse el cálculo del hash antes de subir, se reduce el tiempo total. Con esta opción activada no funcionan la subida instantánea ni la verificación de integridad.

**Multiidioma**  

El frontend lo ajusta solo tras detectar el idioma del navegador; por ahora admite chino e inglés.
  
**Comandos de consola prácticos**  

`php webman aetherupload:groups` lista todos los grupos y crea sus directorios automáticamente  
`php webman aetherupload:build` reconstruye en Redis la lista de subida instantánea de los archivos de recursos  
`php webman aetherupload:clean 2` borra los archivos temporales inválidos de hace más de 2 días  

# Sugerencias de optimización
* **(Recomendado) Programar la limpieza diaria de los archivos temporales inválidos**  
Como el proceso de subida puede terminar de forma inesperada (por ejemplo, si se cierra la página o el navegador a la fuerza durante la transmisión), los bloques ya generados quedan como archivos inválidos y ocupan mucho espacio de almacenamiento; se pueden borrar periódicamente con una tarea programada de crontab.  
En Linux, ejecute `crontab -e` y asegúrese de que el archivo contenga esta línea:  
```php
0 0 * * * php /ruta/absoluta/de/la/raíz/del/proyecto/webman aetherupload:clean 1> /dev/null 2>&1  
```  

* **Programar la reconstrucción diaria de la lista de subida instantánea en Redis**  
Un manejo inadecuado o ciertos casos extremos pueden dejar datos sucios en la lista de subida instantánea y afectar a su exactitud; reconstruirla elimina los datos sucios y restaura la sincronización con los archivos de recursos reales.  
En Linux, ejecute `crontab -e` y asegúrese de que el archivo contenga esta línea:  
```php
0 0 * * * php /ruta/absoluta/de/la/raíz/del/proyecto/webman aetherupload:build 1> /dev/null 2>&1  
```  

* **(Opcional) Activar la redirección interna de nginx, para admitir descargas reanudables de archivos grandes y el avance del vídeo**  
La respuesta de archivos de webman no implementa HTTP Range: una descarga grande se envía entera, el vídeo no permite mover la barra de progreso y durante toda la transmisión se ocupa un proceso worker. Si se despliega detrás de nginx, se puede activar la opción `x_accel_redirect` de este plugin para que los archivos los envíe nginx directamente: el worker se libera de inmediato y se admite Range (descarga reanudable, avance del vídeo).  
Edite `'x_accel_redirect' => true,` en el archivo de configuración de este plugin y añada un location para el prefijo interno en la configuración de nginx, con `alias` apuntando a la ruta absoluta de la raíz de subidas del proyecto (ojo con la barra final):  
```nginx
location /internal-aetherupload/ {
    internal;
    alias /ruta/absoluta/de/la/raíz/del/proyecto/storage/app/aetherupload/;
}
```
Ese location tiene que conservar `internal`, para impedir que desde fuera se acceda directamente a los archivos de recursos saltándose el programa; si se cambia `root_dir`, hay que ajustar `alias` en consecuencia.  

* **Acelerar la lectura y escritura de los archivos temporales de bloque (solo afecta a PHP)**  
Aproveche el sistema de archivos tmpfs de Linux para tener los archivos temporales de bloque en memoria y leerlos y escribirlos rápido: se cambia espacio por tiempo y mejora la eficiencia, a costa de **ocupar memoria adicional** (aproximadamente el tamaño de un bloque).  
Ponga el valor `"/dev/shm"` en la opción `upload_tmp_dir` (directorio temporal de subidas) de php.ini y reinicie el servicio.  

* **Acelerar la lectura y escritura de los archivos temporales de bloque (afecta al directorio temporal del sistema)**  
Aproveche el sistema de archivos tmpfs de Linux para tener los archivos temporales de bloque en memoria y leerlos y escribirlos rápido: se cambia espacio por tiempo y mejora la eficiencia, a costa de **ocupar memoria adicional** (aproximadamente el tamaño de un bloque).  
Ejecute los siguientes comandos:    
`mkdir /dev/shm/tmp`  
`chmod 1777 /dev/shm/tmp`  
`mount --bind /dev/shm/tmp /tmp`  

# Compatibilidad
<table>
  <th></th>
  <th>IE</th>
  <th>Edge</th>
  <th>Firefox</th>
  <th>Chrome</th>
  <th>Safari</th>
  <tr>
  <td>Subida</td>
  <td>10+</td>
  <td>12+</td>
  <td>3.6+</td>
  <td>6+</td>
  <td>5.1+</td>
  </tr>
  <tr>
  <td>Subida instantánea</td>
  <td>10+</td>
  <td>12+</td>
  <td>3.6+</td>
  <td>6+</td>
  <td>6+</td>
  </tr>
</table>

# Seguridad
AetherUpload filtra la extensión del archivo antes de subir con una lista blanca más una lista negra, y después de subir comprueba el tipo Mime-Type del archivo. La lista blanca limita directamente las extensiones con las que se guarda el archivo y la lista negra bloquea por defecto las extensiones ejecutables más habituales, para impedir la subida de archivos maliciosos; por seguridad, la lista blanca no debería quedar vacía.  

Aunque se han hecho bastantes tareas de seguridad, la subida de archivos maliciosos es imposible de evitar del todo; se recomienda configurar bien los permisos del directorio de subidas y asegurarse de que los programas correspondientes no tengan permiso de ejecución sobre los archivos de recursos.
