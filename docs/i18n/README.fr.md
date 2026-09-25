# AetherUpload-Webman  

<img src="../js/aetherupload-pet.svg" width="120" alt="Aetherbeast — la mascotte du projet AetherUpload">

[中文](../../README.md) · [English](README.en.md) · [한국어](README.ko.md) · [Русский](README.ru.md) · [Deutsch](README.de.md) · [Français](README.fr.md) · [Español](README.es.md) · [Português](README.pt.md) · [हिन्दी](README.hi.md) · [العربية](README.ar.md) · [বাংলা](README.bn.md) · [Bahasa Indonesia](README.id.md) · [日本語](README.ja.md)

Ce projet est le portage de l'extension Laravel d'envoi de gros fichiers, très appréciée, [AetherUpload-Laravel](https://github.com/peinhu/AetherUpload-Laravel) : la lecture de la configuration, la gestion de la concurrence et la couche de stockage ont été réécrites pour le modèle à processus persistant de [webman](https://www.workerman.net/webman).

**Ce qu'il résout**

L'envoi direct d'un gros fichier depuis le navigateur se heurte à trois obstacles incontournables : au-delà de `post_max_size`, l'envoi échoue ; la moindre coupure réseau oblige à tout reprendre depuis le début ; et un même fichier est transféré encore et encore. La méthode d'AetherUpload est la suivante : découper le fichier en chunks dans le navigateur, les ajouter un par un à un fichier temporaire côté serveur, puis nommer le fichier écrit sur disque d'après le md5 de son contenu. **Envoi, reprise après coupure, envoi instantané, déduplication et contrôle d'intégrité** partagent ainsi un seul et même mécanisme : le fichier n'est jamais lu entièrement en mémoire, et aucune table de base de données n'est nécessaire pour savoir « où se trouve le fichier ».

**Sa forme**

Un paquet composer, **dont le noyau est découplé du framework hôte** : le même code fonctionne sous webman, PHP natif (sans framework), Laravel, ThinkPHP, Symfony, Slim, Hyperf et Yii2 (voir [Frameworks pris en charge](#frameworks-pris-en-charge)). La configuration, les routes, les commandes de console, les fichiers de langue et les scripts front-end de l'hôte sont distribués par les commandes d'installation / de publication ; aucune base de données n'est requise ; Redis n'est nécessaire que pour activer l'envoi instantané, c'est une dépendance optionnelle.

![Page d'exemple](http://wx2.sinaimg.cn/mw690/69e23056gy1fho6ymepjlg20go0aknar.gif) 

# Structure du projet

```text
aetherupload-webman/
├── src/                          Code source du plugin
│   ├── Runtime.php                 Point d'ancrage hôte (façade statique) : au niveau du processus, il ne détient que des liaisons immuables et signale clairement une liaison manquante
│   ├── RequestContext.php          État mutable par requête / par coroutine (instantané de la configuration de groupe, langues chargées) ; sous un processus persistant, les requêtes concurrentes ne mélangent pas les groupes
│   ├── Contract/                   11 interfaces (configuration / traduction / requête / fichier envoyé / réponse / Redis / événements / chemins / système de fichiers / contexte / adaptateur)
│   ├── Kernel/                     Implémentations par défaut côté noyau : AbstractAdapter, PrefixedConfig, Filesystem, NullRedis, NullEventDispatcher, PhpFileTranslator, ClientRedis…
│   ├── Console/                    Logique métier des trois commandes (Runner) + points d'entrée prêts à l'emploi pour les hôtes sans convention de console, partagée par les sept shells de commande
│   ├── Adapter/                    Huit adaptateurs : Webman / Native / Laravel / ThinkPhp / Symfony / Slim / Hyperf / Yii
│   ├── UploadController.php        Entrée de l'envoi : preprocess (prétraitement / détermination de l'envoi instantané) et saveChunk (écriture par chunks)
│   ├── ResourceController.php      Entrée de l'affichage et du téléchargement : display / download, avec remise possible de l'envoi à nginx
│   ├── PartialResource.php         Le fichier en chunks lui-même : assemblage du chemin, ajout chunk après chunk, renommage, contrôle de la taille et du type
│   ├── Header.php                  Fichier d'état de reprise : ne stocke qu'un seul chunkIndex
│   ├── Resource.php                Objet fichier final, injecté en paramètre dans l'événement « envoi terminé »
│   ├── RedisSavedPath.php          Index d'envoi instantané : une clé par enregistrement, TTL indépendant
│   ├── SavedPathResolver.php       Encodage / décodage du chemin de stockage (adressage sans état en trois segments)
│   ├── ConfigMapper.php            Singleton de configuration + instantané de la configuration de groupe par requête
│   ├── MimeType.php                Table mime ↔ extension, revérifiée sur la liste blanche avant l'écriture sur disque
│   ├── Util.php                    Génération de noms temporaires, contrôle de sécurité des chemins, suppression de ressources
│   ├── Install.php                 Installation / désinstallation : distribution de la configuration et des ressources à l'application hôte
│   ├── Responser.php               Encapsulation des réponses JSON
│   ├── SimpleValidateTrait.php     Validation minimale des champs obligatoires
│   ├── ExamplePageTrait.php        Page d'exemple
│   └── helpers.php                 Assistants de template : aetherupload_display_link / aetherupload_download_link
├── commands/                     Shells de console de webman (copiés dans app/command à l'installation ; la logique est dans src/Console)
│   ├── AetherUploadListGroups.php        php webman aetherupload:groups   liste les groupes et crée les répertoires correspondants
│   ├── AetherUploadBuildRedisHashes.php  php webman aetherupload:build    reconstruit l'index d'envoi instantané d'après l'état du disque
│   └── AetherUploadCleanUpDirectory.php  php webman aetherupload:clean N  purge selon le mtime les fichiers temporaires expirés
├── config/
│   ├── app.php                   Configuration du plugin webman : groupes, routes, middleware et divers interrupteurs
│   ├── aetherupload.php          La même configuration, indépendante du framework, servant de base aux sept autres adaptateurs (les deux chemins sont verrouillés par ConfigParityTest)
│   └── route.php                 Quatre routes + leurs points d'ancrage middleware respectifs
├── docs/                         Documentation, images et scripts front-end
│   ├── aetherupload-architecture.svg  Schéma d'architecture
│   ├── aetherupload-design.svg        Schéma de la démarche de conception
│   ├── aetherupload-lifecycle.svg     Schéma du cycle de vie d'un envoi
│   ├── HARNESS.md                     Contrat des tests unitaires (à lire avant d'écrire le moindre test)
│   ├── REPORT.md                      Rapport de synthèse d'une campagne de tests complète et de sa couverture
│   ├── i18n/                          Traductions du README et des trois schémas (12 langues ; navigation entre traductions décrite dans TRANSLATING.md)
│   └── js/                            Ressources front-end (publiées dans <racine du document>/vendor/aetherupload/js)
│       ├── aetherupload-all.js        Version packagée : noyau + zepto + spark-md5
│       ├── aetherupload-core.js       Logique centrale : calcul du hash, découpage, envoi, progression, reprise après coupure
│       └── aetherupload-pet.svg       La mascotte du projet, « Aetherbeast », sert à la fois d'icône à la page d'exemple et de favicon
├── translations/{zh,en}/         Traductions (publiées dans le chemin translations de l'hôte, sous aetherupload/)
├── views/example.blade.php       Source de la page d'exemple, à consulter directement lors de l'intégration
├── tests/
│   ├── *.php                     Cas de test unitaires PHPUnit (hôte simulé, PHP 8.0–8.4, PHPUnit 9.6 et 10.5)
│   └── Integration/<fw>/         Suites de bout en bout, une par framework : vrai framework installé, vrai parcours d'envoi (webman démarre un vrai service via phpunit.xml, les sept autres passent par leur propre ci.sh)
├── uploads/                      Répertoire hérité, non utilisé à l'exécution du plugin
└── composer.json
```

> Les 15 fichiers à la racine de `src/` (contrôleurs, chunks, reprise, index d'envoi instantané, encodage des chemins, réponses d'erreur…) constituent le noyau d'envoi indépendant du framework : ils ne référencent aucun espace de noms hôte ; configuration / traduction / requête / réponse / Redis / événements passent tous par les ports exposés par `Runtime`.
> La seule exception est `Install.php` — le script d'installation déclenché par `composer require` s'exécute avant que l'application hôte ne soit démarrée : `Runtime` n'est alors pas encore lié, et il se rabat dans ce cas sur une auto-liaison de l'adaptateur webman, avant de retomber sur le `config/app.php` du paquet.

> Avant l'installation, tout ceci est interne au paquet ; après `composer require`, `Install.php` distribue la configuration, les commandes, les scripts front-end et les fichiers de langue aux emplacements correspondants de l'application hôte, selon la correspondance indiquée dans l'arborescence ci-dessus.

# Architecture

<img src="img/aetherupload-architecture.fr.svg" alt="Schéma d'architecture d'AetherUpload-Webman">

- **Côté client** : il suffit d'inclure un `aetherupload-all.js` (qui embarque zepto et spark-md5) ; il se charge du calcul du md5, du découpage, de la barre de progression et de la reprise automatique après coupure.
- **Côté serveur** : `UploadController` n'a que deux points d'entrée (prétraitement, écriture par chunks) ; `PartialResource` gère les chemins, l'ajout et le renommage, `Header` ne retient que le chunkIndex ; la configuration est instantanée par requête via `ConfigMapper`, ce qui évite que des requêtes concurrentes se mélangent les groupes sous un processus persistant.
- **Stockage et exploitation** : le disque ne contient jamais que trois types de fichiers — des chunks `*.part`, des points de reprise `_header/` et des fichiers finaux `<md5>.<ext>` ; Redis n'est utilisé que lorsque l'envoi instantané est activé ; une fois `x_accel_redirect` activé, c'est nginx qui envoie les fichiers directement, ce qui apporte au passage le support de Range et la reprise d'un téléchargement interrompu.

# Démarche de conception

<img src="img/aetherupload-design.fr.svg" alt="Démarche de conception d'AetherUpload-Webman">

Au-delà des trois décisions illustrées, un choix indépendant mérite d'être signalé : **l'index d'envoi instantané est une dépendance optionnelle, avec un TTL indépendant par enregistrement**. Un site sans Redis peut quand même envoyer des fichiers (seul l'envoi instantané est alors inopérant) ; sur un site équipé de Redis, chaque enregistrement expire de son côté via `SETEX` — les durées de vie ne sont pas partagées, et l'index ne gonfle pas indéfiniment au fil des envois ; après une mise à jour, les anciens enregistrements de hash restent lisibles en repli. La cohérence est assurée par `aetherupload:build` (reconstruction quotidienne) et `aetherupload:clean` (récupération des fichiers temporaires selon le mtime).

# Cycle de vie d'un envoi

<img src="img/aetherupload-lifecycle.fr.svg" alt="Cycle de vie d'un envoi avec AetherUpload-Webman">

Le chemin principal ne compte que quatre étapes : **prétraitement → chunks (en boucle) → contrôle du dernier chunk → écriture sur disque**. Le prétraitement crée un `.part` vide et écrit `chunkIndex=0` dans le header ; l'ordre de chaque chunk est fixe — « contrôle → ajout → réécriture du chunkIndex » ; seul le dernier chunk déclenche le contrôle de la taille et du MIME, le recalcul du md5 complet, le renommage en `<md5>.<ext>` et l'écriture de l'index d'envoi instantané.

Deux voies parallèles méritent une explication à part :

- **Envoi instantané (hit)** : si le prétraitement trouve un fichier final portant le même md5, il en renvoie directement le chemin, sans qu'un seul chunk soit transmis.
- **Interruption et reprise** : renvoyer le même numéro d'ordre est ignoré de façon idempotente ; un saut de numéro ou un chunk tronqué ne renvoie qu'une erreur, et **ne nettoie pas** le `.part` ni le header — sur un réseau faible, le client peut donc réessayer jusqu'à ce que le chunk manquant soit complété. Seuls deux cas déclenchent réellement un nettoyage : l'échec du contrôle du dernier chunk (rejet de l'ensemble) et, après la fermeture de la page, le passage de `aetherupload:clean` par cron, qui récupère les fichiers selon le mtime.

# Fonctionnalités
- [x] Barre de progression en pourcentage  
- [x] Limitation du type de fichier  
- [x] Limitation de la taille de fichier  
- [x] Support multilingue  
- [x] Configuration par groupes de ressources  
- [x] Événement de fin d'envoi  
- [x] Envoi synchrone *①*  
- [x] Reprise après coupure *②*  
- [x] Envoi instantané de fichiers *③*  
- [x] Middleware personnalisé *④*  
- [x] Routes personnalisées  
- [x] Mode permissif

*① : Comparé à l'envoi asynchrone, l'envoi synchrone est un peu plus lent lorsque la bande passante d'envoi est suffisante ; en revanche, le mode synchrone assemble le fichier au fil de l'envoi, alors que le mode asynchrone, dont l'ordre d'arrivée des chunks est incertain, doit attendre que tous les chunks soient terminés pour assembler — ce qui l'oblige à patienter longtemps juste avant la fin. L'envoi synchrone ne fait transiter qu'un seul chunk à la fois, occupe moins de mémoire serveur par unité de temps et supporte donc plus d'envois simultanés que le mode asynchrone.*  

*② : La reprise après coupure diffère de la reprise au point d'interruption : il s'agit, en cas de coupure réseau ou de réseau sans fil instable, de la nouvelle tentative automatique et périodique du composant d'envoi tant que la page n'est pas fermée — une fois le réseau rétabli, l'envoi repart du chunk qui avait échoué. La reprise après coupure ne fonctionne pas après un rafraîchissement de la page, ni après sa fermeture puis réouverture : la partie déjà envoyée est alors devenue un fichier inutilisable.*  

*③ : L'envoi instantané exige le support de Redis côté serveur et du navigateur côté client (FileReader, File.slice()) ; si l'un des deux manque, la fonction ne peut pas opérer. Désactivé par défaut, à activer dans le fichier de configuration.*  

*④ : Combiné à un middleware personnalisé, il permet de contrôler les droits d'accès et de téléchargement des ressources déjà envoyées.*


# Frameworks pris en charge

Le noyau (chunks, reprise, envoi instantané, contrôle, adressage) est découplé du framework hôte : le même paquet fonctionne sous les hôtes ci-dessous, chacun étant adossé à des tests de bout en bout qui **installent réellement le framework et exécutent réellement toute la chaîne d'envoi** :

| Hôte | Intégration | Tests de bout en bout |
|---|---|---|
| webman | support natif (`composer require` distribue automatiquement configuration / routes / commandes / scripts front-end) | `tests/Integration/webman/` |
| **PHP natif (sans framework)** | une ligne `Bootstrap::handle()`, le routage distribué par ce paquet selon la configuration | `tests/Integration/native/` |
| Laravel | ServiceProvider + `vendor:publish` | `tests/Integration/laravel/` |
| ThinkPHP | enregistrement du Service dans `app/service.php` | `tests/Integration/thinkphp/` |
| Symfony | Bundle + import des ressources de routage | `tests/Integration/symfony/` |
| Slim | une ligne `Bootstrap::create()` | `tests/Integration/slim/` |
| Hyperf | `ConfigProvider` | `tests/Integration/hyperf/` |
| Yii2 | Bootstrap accroché au `bootstrap` de l'application | `tests/Integration/yii/` |

> Le `webman` du nom du paquet s'explique par l'historique (le plugin ne supportait au départ que webman) ; cela n'empêche pas de l'utiliser sous les autres hôtes.

## Deux étapes communes

Quel que soit l'hôte, deux actions **obligatoires** suivent l'installation du paquet (webman les exécute automatiquement via le script d'installation ; pour les autres hôtes, il faut lancer soi-même les commandes correspondantes) :

1. **Créer les répertoires de stockage** : `aetherupload:groups` — crée `root_dir`, `_header` et les répertoires de chaque groupe.
   **Sans cette étape, l'échec est certain** : le `createGroupSubDir()` du noyau fait un `mkdir` non récursif ; si le répertoire parent manque, il renvoie false, et l'erreur est traduite uniformément en un vague `upload_error`, ce qui rend le diagnostic pénible — difficile d'y voir un problème de répertoire.
2. **Publier les fichiers** : `aetherupload:publish` (sous Laravel, `vendor:publish --tag=aetherupload-*`) — place les fichiers de langue et le `js` front-end dans un emplacement accessible à l'hôte.

> Le séparateur des noms de commandes suit la convention de console de chaque framework : webman / Laravel / ThinkPHP / Symfony / Hyperf / Slim / PHP natif utilisent `:`, **Yii utilise `/`** (`php yii aetherupload/groups`). Les exemples ci-dessous, framework par framework, sont directement copiables.

## Intégration par framework

**PHP natif (sans framework)**

Toute application tournant sous PHP peut l'utiliser directement — sans installer de framework, sans middleware, sans enregistrer de service, un seul fichier d'entrée :

```php
// public/index.php (script d'entrée FPM / Apache / nginx+php-fpm)
require __DIR__ . '/../vendor/autoload.php';

exit(\AetherUpload\Adapter\Native\Bootstrap::handle([
    'base_path' => dirname(__DIR__),
    'config'    => require __DIR__ . '/../config/aetherupload.php',   // omise, cette clé laisse jouer les valeurs par défaut du paquet
    'redis'     => static fn () => new \Redis(),                       // nécessaire à l'envoi instantané, optionnel
]));
```

```bash
php bin/aetherupload aetherupload:groups     # crée les répertoires
php bin/aetherupload aetherupload:publish    # publie les fichiers de langue et le js front-end
```

`bin/aetherupload` tient lui aussi en trois lignes, à vous d'en placer une copie :

```php
#!/usr/bin/env php
<?php
require __DIR__ . '/../vendor/autoload.php';
\AetherUpload\Adapter\Native\Bootstrap::bind(require __DIR__ . '/../config/aetherupload.php', dirname(__DIR__));
exit((new \AetherUpload\Console\Application())->run());
```

> **Pas de routage à écrire vous-même** : `Bootstrap::handle()` distribue la requête courante selon `route_preprocess` / `route_uploading` / `route_display` / `route_download` de la configuration ; sans correspondance, 404 ; mauvaise méthode, 405 avec l'en-tête `Allow`.
> **Middleware** : dans cet hôte, les `middleware_*` de la configuration sont des **appelables sans argument** ; renvoyer un objet réponse court-circuite le traitement — droits insuffisants, faites directement `return Runtime::response()->text('forbidden', 403);` ; toute autre valeur renvoyée est ignorée et le traitement se poursuit (c'est ainsi que se branche le contrôle des droits de la note ④).
> **Serveur intégré** : `php -S 127.0.0.1:8080 -t public public/index.php` suffit. Pour que le serveur intégré serve lui-même les fichiers statiques de `public/` (c'est par là que passe le js front-end publié), ajoutez avant `handle()` la ligne `if (is_file(__DIR__ . parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH))) { return false; }`.
> **Pas de repli sur les fonctions intégrées** : la requête est lue dans `$_GET`/`$_POST`/`$_FILES` (PHP ne filtre rien de plus, les gardes de type du noyau s'appliquent normalement) et la réponse est émise d'un seul coup par `NativeResponse::send()`, avec `header()` + `echo`, sans couche intermédiaire de framework.

**Laravel**

```php
// bootstrap/providers.php (Laravel 11+) ou le tableau providers de config/app.php
AetherUpload\Adapter\Laravel\AetherUploadServiceProvider::class,
```
```bash
php artisan vendor:publish --tag=aetherupload-config         # publie config/aetherupload.php
php artisan vendor:publish --tag=aetherupload-translations   # publie les fichiers de langue
php artisan vendor:publish --tag=aetherupload-assets         # publie le js front-end
php artisan aetherupload:groups
```
> Le composer.json de ce paquet **ne contient pas** `extra.laravel.providers` (les dépendances des frameworks s'excluent mutuellement, impossible de figer la découverte automatique) : l'enregistrement manuel du provider est donc obligatoire.
> La fusion de configuration est une **fusion superficielle** : dès que l'application publie son `config/aetherupload.php`, le `groups` qu'il contient **remplace intégralement** les valeurs par défaut du plugin — quand vous ajoutez un groupe, recopiez aussi les groupes par défaut.

**ThinkPHP**

```php
// app/service.php
return [ \AetherUpload\Adapter\ThinkPhp\AetherUploadService::class ];
```
```bash
php think aetherupload:groups
php think aetherupload:publish
```
> Même remarque : `groups` est remplacé intégralement. Par ailleurs, `config/route.php` et `config/lang.php` sont des **fichiers obligatoires** : s'ils manquent, le framework reçoit null dans `array_merge` / `array_change_key_case` et lève une TypeError.

**Symfony**

```php
// config/bundles.php
AetherUpload\Adapter\Symfony\AetherUploadBundle::class => ['all' => true],
```
```yaml
# config/routes.yaml (les routes d'un bundle Symfony doivent être importées côté application, il n'existe pas de chargement automatique)
aetherupload:
    resource: '@AetherUploadBundle/Resources/config/routes.php'
    type: php
```
> L'arbre `Configuration` déclare toutes les clés de configuration ; une clé non déclarée fait échouer le démarrage du conteneur (elle n'est pas ignorée en silence).

**Slim**

```php
// public/index.php
$app = \AetherUpload\Adapter\Slim\Bootstrap::create([
    'config'    => require __DIR__ . '/../config/aetherupload.php',   // omise, cette clé laisse jouer les valeurs par défaut du paquet
    'base_path' => dirname(__DIR__),
    'redis'     => static fn () => new Predis\Client(['database' => 0]),   // nécessaire à l'envoi instantané
]);
$app->run();
```
> Les réponses PSR-7 de Slim sont immuables : les productions du noyau sont converties en vraies PSR-7 par la couche `Bootstrap::handler()` (**l'unique point de conversion** ; placée dans un middleware, elle ne passerait pas).
> Slim n'a pas de convention de console : ce paquet fournit les classes d'entrée prêtes à l'emploi pour ses quatre commandes, à vous de placer le script d'entrée (quatre lignes) :
> ```php
> // bin/aetherupload
> require __DIR__ . '/../vendor/autoload.php';
> \AetherUpload\Adapter\Slim\Bootstrap::bind(require __DIR__ . '/../config/aetherupload.php', dirname(__DIR__));
> exit((new \AetherUpload\Adapter\Slim\Console\Application())->run());
> ```
> ```bash
> php bin/aetherupload aetherupload:groups     # ainsi que publish / build / clean
> ```
> Nécessite `symfony/console` (require-dev / suggest de ce paquet).

**Hyperf**

```php
// config/config.php — ce paquet n'écrit pas extra.hyperf.config (cela interférerait avec la
// découverte automatique des autres frameworks ; à compléter après vérification indépendante),
// d'où le dépliage explicite du ConfigProvider ici (routes, commandes et clés de publish en viennent)
return [
    // …
] + (new \AetherUpload\Adapter\Hyperf\ConfigProvider())();
```
```bash
php bin/hyperf.php aetherupload:groups
php bin/hyperf.php aetherupload:publish
```
> Nécessite `ext-swoole`. Deux autres points à connaître en développement : `xdebug.mode=profile` fait **échouer silencieusement** le démarrage à froid d'Hyperf (`exit 255` dans `ClassLoader::init()`, sans la moindre erreur PHP), donc réglez `XDEBUG_MODE=off` en CI comme en local ; et le `Coroutine\run()` de swoole ne peut pas cohabiter avec PHPUnit dans le même processus, les tests liés aux coroutines doivent donc exécuter leur sonde dans un processus séparé.

**Yii2**

```php
// configuration de l'application
return [
    'bootstrap' => ['aetherupload' => \AetherUpload\Adapter\Yii\Bootstrap::class],
    'params'    => ['aetherupload' => require __DIR__ . '/aetherupload.php'],   // peut se baser sur l'exemple produit par publish
    'components' => [
        // Prérequis : à placer sous components. Une clé de même nom au premier niveau serait ignorée
        // par Yii : enableStrictParsing ne prendrait pas effet et la contrainte de verbe des routes
        // serait contournée (un GET atteindrait une action déclarée POST uniquement)
        'urlManager' => ['enableStrictParsing' => true],
        // nécessaire à l'envoi instantané ; à configurer aussi pour l'application console,
        // sinon aetherupload/build renvoie Unknown component ID: redis
        'redis' => [ /* … */ ],
    ],
];
```
```bash
php yii aetherupload/groups     # attention : le séparateur de console de Yii est "/" ; une commande écrite aetherupload:groups renvoie Unknown command
php yii aetherupload/publish
```
> L'emplacement de publication est `vendor/aetherupload/js` sous la **racine du document** (celle de webman est `public/`, celle de Yii `web/`) : la page d'exemple référence le chemin absolu `/vendor/aetherupload/js/…`, dont la sémantique est « racine du document » ; c'est pourquoi `YiiPaths::assetPath()` prend d'abord l'alias `@webroot` de l'hôte et se rabat sur `<app>/web` à défaut.

**webman**

```bash
# à exécuter à la racine du projet webman
composer require erikwang2013/aetherupload-webman
```
> webman est le seul hôte **zéro configuration** : au moment du `composer require`, `Install.php` distribue automatiquement la configuration, les routes, les commandes, les fichiers de langue et les scripts front-end, et crée les répertoires de stockage. Une fois installé, `http://votre-domaine/aetherupload` affiche directement la page d'exemple.
>
> Remarque : pour modifier les options de configuration, éditez `config/plugin/erikwang2013/aetherupload-webman/app.php`.

> Les sept autres hôtes doivent d'abord enregistrer leur adaptateur, puis exécuter les « deux étapes communes ». **webman fournit les mêmes commandes** (`php webman aetherupload:groups` / `aetherupload:publish`), sauf que l'installation les a déjà exécutées une fois.

# Utilisation  
**Envoi de fichiers**  

Référez-vous au fichier d'exemple et à ses commentaires : incluez les fichiers et le code correspondants dans la page qui doit envoyer un gros fichier.

**Configuration des groupes**  

Ajoutez un groupe sous `groups` dans le fichier de configuration du plugin, puis lancez `php webman aetherupload:groups` pour créer automatiquement le répertoire correspondant.  
```php
'video' => [
    'group_dir'                    => 'video',
    'resource_maxsize'             => 0,
    'resource_extensions'          => [],
    'event_before_upload_complete' => false, 
    'event_upload_complete'        => false,
],
```
Côté front-end, désignez le groupe d'envoi en appelant la méthode `setGroup('nom du groupe')` ; attention, ce groupe doit déjà exister et **ne doit pas contenir de souligné** (il participe à l'encodage du chemin de stockage : avec un souligné, les ressources de ce groupe deviennent introuvables, et l'échec survient dès la configuration et l'envoi).

**Activer l'envoi instantané (nécessite Redis et un navigateur compatible)**  

Consultez la section Redis de la documentation webman et installez les dépendances nécessaires.  
Installez Redis et démarrez le service.  
Installez le paquet predis : `composer require predis/predis`.  
Dans `config/redis.php`, réglez `client` sur `predis`.  
Dans le fichier de configuration du plugin, passez `instant_completion` à `true`.

*Remarque : Redis contient une liste d'envoi instantané tenant lieu de correspondance avec les fichiers réels ; toute modification des fichiers réels (ajout ou suppression) doit être répercutée dans cette liste, sans quoi des données obsolètes s'accumulent.  
L'extension prend déjà en charge les ajouts ; en revanche, lorsque vous supprimez un fichier, c'est à vous d'appeler la méthode correspondante pour supprimer le fichier et son enregistrement dans la liste d'envoi instantané.* 
```php
\AetherUpload\Util::deleteResource($savedPath); //supprime le fichier de ressource correspondant
\AetherUpload\Util::deleteRedisSavedPath($savedPath); //supprime l'enregistrement d'envoi instantané Redis correspondant
``` 
*Les deux opérations sont idempotentes : elles renvoient true même si le fichier ou l'enregistrement d'envoi instantané n'existe plus.* 

**Middleware personnalisé**  

Consultez la section sur les middleware de routage de la documentation webman, créez votre middleware et renseignez son nom dans la partie correspondante du fichier de configuration.  
```php
'middleware_download' => [app\middleware\MiddlewareA::class,app\middleware\MiddlewareB::class],
```  
Cette fonction permet de contrôler les droits d'envoi, d'accès et de téléchargement des fichiers.

**Routes personnalisées**  

Dans le fichier de configuration du plugin, modifiez des options telles que `'route_uploading' => '/aetherupload/uploading'`, puis appelez côté front-end des méthodes comme `setUploadingRoute('/aetherupload/uploading')`.  
Une fois les routes d'accès et de téléchargement des fichiers modifiées, l'accès direct à ces fichiers fonctionne, sans appel côté front-end.
 
**Événement de fin d'envoi**  

Il se décompose en un événement avant la fin de l'envoi et un événement après la fin de l'envoi ; voir la section Event des composants courants de la documentation webman.  
Dans `config/event.php`, associez les classes de gestion correspondantes à `aetherupload.before_upload_complete` et `aetherupload.upload_complete`.  
Dans le fichier de configuration du plugin, passez à `true` l'option correspondante sous `groups`. 
```php
'event_before_upload_complete' => true, 
'event_upload_complete'        => true,
```
Cette fonction permet d'effectuer des traitements supplémentaires avant et après la fin de l'envoi.

**Mode permissif**  

Dans le fichier de configuration du plugin, modifiez `'lax_mode' => true,` et appelez côté front-end la méthode `setLaxMode(true)`.  
Sauter le calcul du hash avant l'envoi raccourcit la durée totale. Une fois cette option activée, l'envoi instantané et le contrôle d'intégrité deviennent impossibles.

**Multilingue**  

Le front-end détecte la langue du navigateur et la définit automatiquement ; le chinois et l'anglais sont pris en charge pour l'instant.
  
**Commandes de console pratiques**  

`php webman aetherupload:groups` liste tous les groupes et crée automatiquement les répertoires correspondants  
`php webman aetherupload:build` reconstruit dans Redis la liste d'envoi instantané des fichiers  
`php webman aetherupload:clean 2` supprime les fichiers temporaires invalides de plus de 2 jours  

# Conseils d'optimisation
* **(Recommandé) Purger chaque jour automatiquement les fichiers temporaires invalides**  
L'envoi peut se terminer accidentellement — fermeture forcée de la page ou du navigateur pendant le transfert — auquel cas la partie déjà reçue devient un fichier invalide qui occupe une grande quantité d'espace de stockage ; nous pouvons utiliser les tâches planifiées de crontab pour les purger régulièrement.  
Sous Linux, lancez `crontab -e` et assurez-vous que le fichier contient cette ligne :  
```php
0 0 * * * php /chemin/absolu/vers/la/racine/du/projet/webman aetherupload:clean 1> /dev/null 2>&1  
```  

* **Reconstruire chaque jour automatiquement la liste d'envoi instantané dans Redis**  
Un traitement inadapté et certaines situations extrêmes peuvent faire apparaître des données obsolètes dans la liste d'envoi instantané, ce qui nuit à la fiabilité de la fonction ; reconstruire la liste les élimine et rétablit la synchronisation avec les fichiers réels.  
Sous Linux, lancez `crontab -e` et assurez-vous que le fichier contient cette ligne :  
```php
0 0 * * * php /chemin/absolu/vers/la/racine/du/projet/webman aetherupload:build 1> /dev/null 2>&1  
```  

* **(Optionnel) Activer la redirection interne de nginx, pour la reprise des gros téléchargements et le déplacement dans la vidéo**  
La réponse de fichiers de webman n'implémente pas HTTP Range : un gros fichier est envoyé d'un seul bloc, le curseur d'une vidéo ne peut pas être déplacé, et un processus worker est mobilisé pendant tout le transfert. Si le déploiement passe par nginx, activez l'option `x_accel_redirect` du plugin : nginx enverra alors les fichiers directement, le worker sera libéré immédiatement, et le support de Range (reprise d'un téléchargement interrompu, déplacement dans la vidéo) sera assuré.  
Dans le fichier de configuration du plugin, modifiez `'x_accel_redirect' => true,` et ajoutez dans la configuration nginx un location pour le préfixe interne, dont l'`alias` pointe vers le chemin absolu de la racine d'envoi du projet (attention à la barre oblique finale) :  
```nginx
location /internal-aetherupload/ {
    internal;
    alias /chemin/absolu/vers/la/racine/du/projet/storage/app/aetherupload/;
}
```
Ce location doit conserver `internal`, pour empêcher tout accès externe direct aux fichiers de ressource en contournant l'application ; si vous modifiez `root_dir`, pensez à ajuster `alias` en conséquence.  

* **Accélérer la lecture / écriture des chunks temporaires (effet limité à PHP)**  
Le système de fichiers tmpfs de Linux permet de placer les chunks temporaires en mémoire afin de les lire et écrire rapidement : on échange de l'espace contre du temps et on gagne en efficacité de lecture / écriture, au prix d'une occupation mémoire **supplémentaire** (environ la taille d'un chunk).  
Dans php.ini, réglez la valeur de `upload_tmp_dir`, le répertoire temporaire d'envoi, sur `"/dev/shm"`, puis redémarrez le service.  

* **Accélérer la lecture / écriture des chunks temporaires (effet sur le répertoire temporaire du système)**  
Le système de fichiers tmpfs de Linux permet de placer les chunks temporaires en mémoire afin de les lire et écrire rapidement : on échange de l'espace contre du temps et on gagne en efficacité de lecture / écriture, au prix d'une occupation mémoire **supplémentaire** (environ la taille d'un chunk).  
Exécutez les commandes suivantes :    
`mkdir /dev/shm/tmp`  
`chmod 1777 /dev/shm/tmp`  
`mount --bind /dev/shm/tmp /tmp`  

# Compatibilité
<table>
  <th></th>
  <th>IE</th>
  <th>Edge</th>
  <th>Firefox</th>
  <th>Chrome</th>
  <th>Safari</th>
  <tr>
  <td>Envoi</td>
  <td>10+</td>
  <td>12+</td>
  <td>3.6+</td>
  <td>6+</td>
  <td>5.1+</td>
  </tr>
  <tr>
  <td>Envoi instantané</td>
  <td>10+</td>
  <td>12+</td>
  <td>3.6+</td>
  <td>6+</td>
  <td>6+</td>
  </tr>
</table>

# Sécurité
AetherUpload filtre les extensions de fichier avant l'envoi au moyen d'une liste blanche et d'une liste noire, puis vérifie le Mime-Type du fichier après l'envoi. La liste blanche restreint directement les extensions autorisées à l'enregistrement, la liste noire bloque par défaut les extensions exécutables courantes afin d'empêcher l'envoi de fichiers malveillants ; par sécurité, la liste blanche ne doit jamais être laissée vide.  

Malgré tous ces garde-fous, l'envoi de fichiers malveillants reste impossible à prévenir entièrement : veillez à définir correctement les permissions du répertoire d'envoi et à garantir que les programmes concernés n'ont pas le droit d'exécuter les fichiers de ressource.
