# AetherUpload-Webman  

<img src="../js/aetherupload-pet.svg" width="120" alt="以太獣 — AetherUpload のマスコット">

[中文](../../README.md) · [English](README.en.md) · [한국어](README.ko.md) · [Русский](README.ru.md) · [Deutsch](README.de.md) · [Français](README.fr.md) · [Español](README.es.md) · [Português](README.pt.md) · [हिन्दी](README.hi.md) · [العربية](README.ar.md) · [বাংলা](README.bn.md) · [Bahasa Indonesia](README.id.md) · [日本語](README.ja.md)

本プロジェクトは、高い評価を得ている Laravel 向け大容量ファイルアップロード拡張パッケージ [AetherUpload-Laravel](https://github.com/peinhu/AetherUpload-Laravel) を移植したもので、[webman](https://www.workerman.net/webman) の常駐プロセスモデルに合わせて、設定の読み込み・並行処理・ストレージ層を書き直しています。

**解決する課題**

ブラウザから直接大容量ファイルをアップロードするときには、避けて通れない三つの問題があります。`post_max_size` を超えると送信できません。通信が切れると最初からやり直しになります。同じファイルが何度も転送されます。AetherUpload の方式はこうです。ブラウザ側でファイルを分割し、サーバー上の一時ファイルへ 1 ブロックずつ追記し、書き込みが終わった時点でファイル内容の md5 を名前として付けます。こうして**アップロード・レジューム・秒伝（instant upload）・重複排除・完全性チェック**が同じ仕組みを共有し、処理の途中でファイル全体をメモリに読み込むことも、「ファイルがどこにあるか」を管理するデータベーステーブルを用意することもありません。

**全体像**

composer パッケージが 1 つで、**カーネルはホストフレームワークから分離**されています。同じコードが webman、Laravel、ThinkPHP、Symfony、Slim、Hyperf、Yii2 で動作します（[対応フレームワーク](#対応フレームワーク) を参照）。ホスト側の設定・ルート・コンソールコマンド・言語ファイル・フロントエンドスクリプトは、インストール / パブリッシュコマンドが配布します。データベースには依存しません。Redis は秒伝を有効にしたときだけ必要になる任意の依存です。

![サンプルページ](http://wx2.sinaimg.cn/mw690/69e23056gy1fho6ymepjlg20go0aknar.gif) 

# プロジェクト構成

```text
aetherupload-webman/
├── src/                          プラグインのソース
│   ├── Runtime.php                 ホストへのバインディング点（静的ファサード）：プロセス単位では不変のバインディングだけを保持し、未バインド時は明示的にエラーを出す
│   ├── RequestContext.php          リクエスト単位 / コルーチン単位の可変状態（グループ設定のスナップショット、読み込み済みの言語）。常駐プロセス下でも並行リクエスト同士でグループが混ざらない
│   ├── Contract/                   11 個のインターフェース（設定 / 翻訳 / リクエスト / アップロードファイル / レスポンス / Redis / イベント / パス / ファイルシステム / コンテキスト / アダプタ）
│   ├── Kernel/                     カーネル側のデフォルト実装：AbstractAdapter、PrefixedConfig、Filesystem、NullRedis、NullEventDispatcher…
│   ├── Console/                    3 つのコンソールコマンドの業務ロジック（Runner）。7 つのフレームワークのコマンドシェルが同じ実装を共有する
│   ├── Adapter/                    7 つのアダプタ：Webman / Laravel / ThinkPhp / Symfony / Slim / Hyperf / Yii
│   ├── UploadController.php        アップロードの入口：preprocess（前処理 / 秒伝判定）と saveChunk（分割書き込み）
│   ├── ResourceController.php      表示とダウンロードの入口：display / download。nginx に直接送出させることもできる
│   ├── PartialResource.php         分割ファイルの本体：パスの組み立て、ブロック単位の追記、リネーム、サイズと種別の検証
│   ├── Header.php                  レジューム状態ファイル：chunkIndex を 1 つだけ保持する
│   ├── Resource.php                完成ファイルのオブジェクト。「アップロード完了」イベントに引数として注入される
│   ├── RedisSavedPath.php          秒伝インデックス：1 レコード 1 キー、TTL はレコードごとに独立
│   ├── SavedPathResolver.php       保存パスのエンコード / デコード（三段式のステートレスアドレッシング）
│   ├── ConfigMapper.php            設定のシングルトン + リクエスト単位のグループ設定スナップショット
│   ├── MimeType.php                mime ↔ 拡張子のマッピング。書き込み前にホワイトリストで再確認する
│   ├── Util.php                    一時名の生成、パスの安全性検証、リソースの削除
│   ├── Install.php                 インストール / アンインストール：ホストアプリへ設定とリソースを配布する
│   ├── Responser.php               JSON レスポンスのラッパー
│   ├── SimpleValidateTrait.php     最小限の必須チェック
│   ├── ExamplePageTrait.php        サンプルページ
│   └── helpers.php                 テンプレートヘルパー：aetherupload_display_link / aetherupload_download_link
├── commands/                     webman のコンソールシェル（インストール時に app/command へコピーされ、ロジックは src/Console にある）
│   ├── AetherUploadListGroups.php        php webman aetherupload:groups   グループを一覧表示し、対応するディレクトリを作成する
│   ├── AetherUploadBuildRedisHashes.php  php webman aetherupload:build    ディスクの現状から秒伝インデックスを再構築する
│   └── AetherUploadCleanUpDirectory.php  php webman aetherupload:clean N  mtime に基づいて期限切れの一時ファイルを削除する
├── config/
│   ├── app.php                   webman プラグイン設定：グループ、ルート、ミドルウェアと各種スイッチ
│   ├── aetherupload.php          フレームワーク非依存の同一の設定。残り 6 つのアダプタのベースラインになる（2 つのパスは ConfigParityTest が一致を固定する）
│   └── route.php                 4 本のルート + それぞれのミドルウェア適用点
├── docs/                         ドキュメント、画像、フロントエンドスクリプト
│   ├── aetherupload-architecture.svg  設計アーキテクチャ図
│   ├── aetherupload-design.svg        設計思想図
│   ├── aetherupload-lifecycle.svg     アップロードライフサイクル図
│   ├── HARNESS.md                     ユニットテストの契約（テストを書く前に必読）
│   ├── REPORT.md                      ある時点の全量テストとカバレッジの要約レポート
│   ├── i18n/                          多言語 README と 3 つの設計図の翻訳（12 言語、翻訳のナビゲーションは TRANSLATING.md を参照）
│   └── js/                            フロントエンド資産（<ドキュメントルート>/vendor/aetherupload/js へパブリッシュ）
│       ├── aetherupload-all.js        バンドル版：コア + zepto + spark-md5
│       ├── aetherupload-core.js       コアロジック：ハッシュの計算、分割、アップロード、進捗、通信断時の再試行
│       └── aetherupload-pet.svg       プロジェクトのマスコット「以太獣」。サンプルページのアイコンとサイトアイコンを兼ねる
├── translations/{zh,en}/         多言語（ホストの translations 配下の aetherupload/ へパブリッシュ）
├── views/example.blade.php       サンプルページのソース。導入時にはこれをそのまま参考にできる
├── tests/
│   ├── *.php                     PHPUnit のユニットケース（ホストはスタブ。PHP 8.0–8.4、PHPUnit 9.6 と 10.5 の 2 バージョン）
│   └── Integration/<fw>/         フレームワークごとの E2E スイート：実際にフレームワークを導入し、アップロード経路を実際に通す（webman は phpunit.xml で実サーバーを起動し、残り 6 つは各 ci.sh を使う）
├── uploads/                      レガシーディレクトリ。プラグインの実行時には使用しない
└── composer.json
```

> `src/` の直下にある 15 個のファイル（コントローラ、分割、レジューム、秒伝インデックス、パスの符号化、エラーレスポンス…）はフレームワーク非依存のアップロードカーネルです。ホストの名前空間を一切参照せず、設定 / 翻訳 / リクエスト / レスポンス / Redis / イベントはすべて `Runtime` を通じてポートを取得します。
> 唯一の例外は `Install.php` です。`composer require` が起動するインストールスクリプトはホストアプリが立ち上がるより前に動くため、`Runtime` はまだバインドされていません。その場合は webman アダプタを自前でバインドし、パッケージ内の `config/app.php` にフォールバックします。

> インストール前はこれらはすべてパッケージ内のファイルです。`composer require` の後は、`Install.php` が上表の対応に従って、設定・コマンド・フロントエンドスクリプト・言語ファイルをホストアプリの該当位置へ配布します。

# 設計アーキテクチャ

<img src="img/aetherupload-architecture.ja.svg" alt="AetherUpload-Webman アーキテクチャ図">

- **クライアント**：`aetherupload-all.js` を 1 つ読み込むだけです（zepto と spark-md5 を内包）。md5 の計算、分割、進捗バー、通信断時の自動再試行を担当します。
- **サーバー側**：`UploadController` の入口は 2 つだけです（前処理、分割書き込み）。`PartialResource` がパス・追記・リネームを担い、`Header` は chunkIndex だけを記録します。設定は `ConfigMapper` がリクエスト単位でスナップショットするため、常駐プロセス下でも並行リクエスト同士でグループが混ざりません。
- **ストレージと運用**：ディスク上に存在するファイルは常に 3 種類だけです。`*.part`（分割データ）、`_header/`（レジューム状態）、`<md5>.<ext>`（完成ファイル）。Redis は秒伝を有効にしたときだけ使用します。`x_accel_redirect` を有効にすると、ファイルは nginx が直接送信するようになり、Range と途中再開ダウンロードも合わせて補えます。

# 設計思想

<img src="img/aetherupload-design.ja.svg" alt="AetherUpload-Webman 設計思想">

図の 3 つの意思決定とは別に、もう 1 つ独立した選択があります。**秒伝インデックスを任意の依存とし、レコードごとに独立した TTL を持たせる**、というものです。Redis を導入していないサイトでもアップロードはできます（秒伝だけが機能しません）。Redis を導入したサイトでは、`SETEX` によってレコードごとにそれぞれ期限切れになるため、期限を共有することも、アップロードが続く限り無限に増え続けることもありません。アップグレード後も古い hash のレコードは引き続き読み出せます。整合性は `aetherupload:build`（毎日再構築）と `aetherupload:clean`（mtime に基づく一時ファイルの回収）が担います。

# アップロードライフサイクル

<img src="img/aetherupload-lifecycle.ja.svg" alt="AetherUpload-Webman アップロードライフサイクル">

主経路は 4 ステップだけです。**前処理 → 分割（ループ）→ 最終ブロックの検証 → 書き込み確定**。前処理では空の `.part` を作成し、`chunkIndex=0` を header に書き込みます。各ブロックの順序は常に「検証 → 追記 → chunkIndex の書き戻し」です。サイズと MIME の検証、ファイル全体の md5 の再計算、`<md5>.<ext>` へのリネーム、秒伝インデックスの書き込みは、最終ブロックのときだけ行います。

2 本のバイパス経路は個別に説明する価値があります。

- **秒伝ヒット**：前処理の段階で同じ md5 の完成ファイルが既にあれば、そのパスをそのまま返し、ブロックを 1 つも送信しません。
- **中断と復帰**：同じ番号を再送しても冪等にスキップされます。番号が飛んだ場合やブロックが切り詰められた場合はエラーを返すだけで、`.part` と header は**削除しません**。そのため通信が不安定な環境でも、欠けたブロックを補うまでクライアントは再試行を続けられます。実際に削除が起きるのは 2 つの場合だけです。最終ブロックの検証に通らなかった場合（全体を破棄）と、ページを閉じた後に cron が `aetherupload:clean` を実行して mtime に基づき回収する場合です。

# 機能一覧
- [x] パーセント進捗バー  
- [x] ファイル種別の制限  
- [x] ファイルサイズの制限  
- [x] 多言語対応  
- [x] リソースのグループ設定  
- [x] アップロード完了イベント   
- [x] 同期アップロード *①*  
- [x] レジューム *②*  
- [x] ファイル秒伝 *③*  
- [x] カスタムミドルウェア *④*  
- [x] カスタムルート   
- [x] 緩和モード

*①：同期アップロードは非同期アップロードに比べ、アップロード帯域が十分に広い場合には速度がやや劣ります。ただし同期ではアップロードと同時にファイルを結合できますが、非同期ではファイルブロックの完了順が決まらないため、すべてのブロックが完了するまで結合できず、完了間際に長い待ち時間が生じます。同期アップロードでは常に 1 つのファイルブロックだけを送信するので、単位時間あたりにサーバーが使うメモリが少なく、非同期方式に比べて同時にアップロードできる人数を増やせます。*  

*②：レジュームはレンジによる途中再開とは異なります。レジュームとは、通信の切断や無線ネットワークの不安定さに遭遇したとき、ページを閉じないままであれば、アップロードコンポーネントが定期的に自動再試行し、通信が復旧した時点で最後に失敗したファイルブロックから続きをアップロードする仕組みです。ページを再読み込みしたり、閉じてから開き直したりした場合はレジュームできず、それまでにアップロードされた部分は無効なファイルになります。*  

*③：ファイル秒伝にはサーバー側の Redis とクライアントブラウザの対応（FileReader、File.slice()）が必要で、どちらか一方でも欠けると秒伝は機能しません。デフォルトでは無効で、設定ファイルで有効にする必要があります。*  

*④：カスタムミドルウェアと組み合わせることで、アップロード済みリソースへのアクセスとダウンロードに対して権限を制御できます。*


# 対応フレームワーク

カーネル（分割、レジューム、秒伝、検証、アドレッシング）はホストフレームワークから分離されており、同じパッケージが以下のフレームワークで利用できます。いずれも**実際にフレームワークを導入し、アップロード経路を実際に通す** E2E テストで裏付けられています。

| フレームワーク | 導入手順 | E2E テスト |
|---|---|---|
| webman | ネイティブ対応（`composer require` だけで設定 / ルート / コマンド / フロントエンドスクリプトを自動配布） | `tests/Integration/webman/` |
| Laravel | ServiceProvider + `vendor:publish` | `tests/Integration/laravel/` |
| ThinkPHP | `app/service.php` に Service を登録 | `tests/Integration/thinkphp/` |
| Symfony | Bundle + ルートリソースのインポート | `tests/Integration/symfony/` |
| Slim | `Bootstrap::create()` を 1 行 | `tests/Integration/slim/` |
| Hyperf | `ConfigProvider` | `tests/Integration/hyperf/` |
| Yii2 | アプリの `bootstrap` に Bootstrap を登録 | `tests/Integration/yii/` |

> パッケージ名の `webman` は歴史的な経緯によるものです（このプラグインが当初は webman だけをサポートしていたため）。他のフレームワークでの利用には影響しません。

## 共通の 2 ステップ

どのフレームワークでも、パッケージ導入後に**必ず行う**作業が 2 つあります（webman ではインストールスクリプトが自動で行い、それ以外のフレームワークでは対応するコマンドを手動で実行します）。

1. **ストレージディレクトリの作成**：`aetherupload:groups` —— `root_dir`、`_header` と各グループのディレクトリを作成します。
   **作成しないと必ず失敗します**。カーネルの `createGroupSubDir()` は非再帰の `mkdir` なので、親ディレクトリが無い場合はそのまま false を返し、しかもエラーは一律で大まかな `upload_error` に翻訳されるため、原因がディレクトリだと突き止めるのは困難です。
2. **ファイルのパブリッシュ**：`aetherupload:publish`（Laravel では `vendor:publish --tag=aetherupload-*`）—— 言語ファイルとフロントエンドの `js` をホストからアクセスできる位置へ配置します。

> コマンド名の区切り文字は各フレームワークのコンソール規約に従います。webman / Laravel / ThinkPHP / Symfony / Hyperf / Slim は `:`、**Yii は `/`** です（`php yii aetherupload/groups`）。以下、各フレームワークの例はそのままコピーして実行できる書き方になっています。

## フレームワークごとの導入手順

**Laravel**

```php
// bootstrap/providers.php（Laravel 11+）または config/app.php の providers 配列
AetherUpload\Adapter\Laravel\AetherUploadServiceProvider::class,
```
```bash
php artisan vendor:publish --tag=aetherupload-config         # config/aetherupload.php をパブリッシュ
php artisan vendor:publish --tag=aetherupload-translations   # 言語ファイルをパブリッシュ
php artisan vendor:publish --tag=aetherupload-assets         # フロントエンド js をパブリッシュ
php artisan aetherupload:groups
```
> 本パッケージの composer.json には `extra.laravel.providers` を**含みません**（6 つのフレームワークの依存は互いに排他で、自動検出を固定できないため）。したがって provider は手動で登録する必要があります。
> 設定のマージは**浅いマージ**です。アプリが `config/aetherupload.php` をパブリッシュすると、その中の `groups` はプラグインのデフォルト値を**丸ごと置き換え**ます。グループを追加するときは、デフォルトのグループも一緒に書き写してください。

**ThinkPHP**

```php
// app/service.php
return [ \AetherUpload\Adapter\ThinkPhp\AetherUploadService::class ];
```
```bash
php think aetherupload:groups
php think aetherupload:publish
```
> ここでも `groups` は丸ごと置き換わる点に注意してください。また `config/route.php` と `config/lang.php` は**必須ファイル**で、欠けるとフレームワークが `array_merge` / `array_change_key_case` で null を受け取り、TypeError を送出します。

**Symfony**

```php
// config/bundles.php
AetherUpload\Adapter\Symfony\AetherUploadBundle::class => ['all' => true],
```
```yaml
# config/routes.yaml（Symfony の bundle ルートはアプリ側で import する必要があり、自動読み込みの仕組みはない）
aetherupload:
    resource: '@AetherUploadBundle/Resources/config/routes.php'
    type: php
```
> `Configuration` ツリーがすべての設定キーを宣言しており、宣言されていないキーがあるとコンテナの起動に失敗します（黙って無視されるのではありません）。

**Slim**

```php
// public/index.php
$app = \AetherUpload\Adapter\Slim\Bootstrap::create([
    'config'    => require __DIR__ . '/../config/aetherupload.php',   // 省略するとパッケージ内のデフォルト値を使う
    'base_path' => dirname(__DIR__),
    'redis'     => static fn () => new Predis\Client(['database' => 0]),   // 秒伝に必要
]);
$app->run();
```
> Slim の PSR-7 レスポンスは不変なので、カーネルの出力は `Bootstrap::handler()` の層で実際の PSR-7 に変換されます（**唯一の変換点**であり、ミドルウェアに置いても通り抜けられません）。
> Slim にはコンソールの規約が無いため、本パッケージは 4 つのコマンド用のエントリクラスを用意しています。エントリスクリプトは各自で 1 つ（4 行）置いてください。
> ```php
> // bin/aetherupload
> require __DIR__ . '/../vendor/autoload.php';
> \AetherUpload\Adapter\Slim\Bootstrap::bind(require __DIR__ . '/../config/aetherupload.php', dirname(__DIR__));
> exit((new \AetherUpload\Adapter\Slim\Console\Application())->run());
> ```
> ```bash
> php bin/aetherupload aetherupload:groups     # ほかに publish / build / clean
> ```
> `symfony/console` が必要です（本パッケージの require-dev / suggest）。

**Hyperf**

```php
// config/config.php —— 本パッケージは extra.hyperf.config を書いていません（他のフレームワークの自動検出機構と
// 互いに干渉するため、独立して検証できてから補います）。そのためここで ConfigProvider を展開します（ルート、コマンド、publish キーはすべてこれが提供します）
return [
    // …
] + (new \AetherUpload\Adapter\Hyperf\ConfigProvider())();
```
```bash
php bin/hyperf.php aetherupload:groups
php bin/hyperf.php aetherupload:publish
```
> `ext-swoole` が必要です。開発時の注意点がもう 2 つあります。`xdebug.mode=profile` を設定すると Hyperf のコールドスタートが**無言で終了**します（`ClassLoader::init()` の中で exit 255、PHP のエラーは一切出ません）。CI とローカルでは `XDEBUG_MODE=off` を設定してください。また swoole の `Coroutine\run()` は PHPUnit と同じプロセスでは使えないため、コルーチン関連のテストはプローブを別プロセスで動かす必要があります。

**Yii2**

```php
// アプリの設定
return [
    'bootstrap' => ['aetherupload' => \AetherUpload\Adapter\Yii\Bootstrap::class],
    'params'    => ['aetherupload' => require __DIR__ . '/aetherupload.php'],   // publish が生成するサンプルをベースにできる
    'components' => [
        // 前提条件：components の下に書く必要がある。トップレベルの同名キーは Yii に無視され、
        // そうなると enableStrictParsing が効かず、ルートの verb 制約が迂回される（GET が POST しか宣言していないアクションに届く）
        'urlManager' => ['enableStrictParsing' => true],
        // 秒伝に必要。コンソールアプリにも設定しないと aetherupload/build が Unknown component ID: redis を出す
        'redis' => [ /* … */ ],
    ],
];
```
```bash
php yii aetherupload/groups     # 注意：Yii のコンソール区切り文字は "/" で、aetherupload:groups と書くと Unknown command になる
php yii aetherupload/publish
```
> パブリッシュ先は**ドキュメントルート**配下の `vendor/aetherupload/js` です（webman のドキュメントルートは `public/`、Yii は `web/`）。サンプルページが参照するのは絶対パス `/vendor/aetherupload/js/…` で、その意味は「ドキュメントルート」です。そのため `YiiPaths::assetPath()` はホストの `@webroot` エイリアスを優先して取得し、取得できない場合は `<app>/web` にフォールバックします。

**webman**

```bash
# webman プロジェクトのルートディレクトリで実行する
composer require erikwang2013/aetherupload-webman
```
> webman は唯一の**設定不要**のホストです。`composer require` の時点で `Install.php` が設定・ルート・コマンド・言語ファイル・フロントエンドスクリプトを自動配布し、ストレージディレクトリも作成します。インストール後は `http://ドメイン/aetherupload` にアクセスすればそのままサンプルページになります。
>
> ヒント：関連する設定を変更する場合は `config/plugin/erikwang2013/aetherupload-webman/app.php` を編集してください。

> 残り 6 つのフレームワークでは、先にアダプタを登録してから「共通の 2 ステップ」を実行する必要があります。**webman も同じコマンドを提供しています**（`php webman aetherupload:groups` / `aetherupload:publish`）が、インストール時にすでに自動で実行済みです。

# 使い方  
**ファイルのアップロード**  

サンプルファイルとコメントを参考に、大容量ファイルをアップロードするページへ該当するファイルとコードを読み込んでください。

**グループ設定**  

本プラグインの設定ファイルの `groups` にグループを追加し、`php webman aetherupload:groups` を実行すると対応するディレクトリが自動で作成されます。  
```php
'video' => [
    'group_dir'                    => 'video',
    'resource_maxsize'             => 0,
    'resource_extensions'          => [],
    'event_before_upload_complete' => false, 
    'event_upload_complete'        => false,
],
```
フロントエンドでは `setGroup('グループ名')` を呼び出してアップロード先のグループを指定します。グループ名はすでに存在している必要があり、さらに**アンダースコアを含められません**（グループ名は保存パスのエンコードに関与するため、アンダースコアを含むとそのグループのリソースを特定できなくなり、設定とアップロードの段階で失敗します）。

**秒伝を有効にする（Redis とブラウザの対応が必要）**  

Webman ドキュメントの Redis の項を参考に、必要な依存をインストールします。  
Redis をインストールしてサービスを起動します。  
predis パッケージをインストールします `composer require predis/predis`。  
`config/redis.php` で `client` を `predis` に設定します。  
本プラグインの設定ファイルで `instant_completion` を `true` に設定します。

*ヒント：Redis には、実際のリソースファイルに対応する秒伝リストが 1 部保持されています。実際のリソースファイルの追加・削除による変化はすべて秒伝リストへ同期させる必要があり、そうしないと不整合なデータが生じます。  
拡張パッケージには追加の処理が含まれていますが、リソースファイルを削除する必要がある場合は、利用者が対応するメソッドを手動で呼び出して、ファイルと秒伝リストのレコードを削除する必要があります。* 
```php
\AetherUpload\Util::deleteResource($savedPath); //対応するリソースファイルを削除する
\AetherUpload\Util::deleteRedisSavedPath($savedPath); //対応する Redis の秒伝レコードを削除する
``` 
*どちらも冪等な操作です。ファイルや秒伝レコードがすでに存在しない場合も同様に true を返します。* 

**カスタムミドルウェア**  

Webman ドキュメントのルートミドルウェアの項を参考に、独自のミドルウェアを作成し、その名前を設定ファイルの該当箇所に記入します。  
```php
'middleware_download' => [app\middleware\MiddlewareA::class,app\middleware\MiddlewareB::class],
```  
この機能を使うと、ファイルのアップロード・アクセス・ダウンロードの権限を制御できます。

**カスタムルート**  

本プラグインの設定ファイルで `'route_uploading' => '/aetherupload/uploading'` などの項目を編集し、フロントエンドで `setUploadingRoute('/aetherupload/uploading')` などのメソッドを呼び出します。  
ファイルのアクセス・ダウンロードのルートは、編集後は直接アクセスでき、フロントエンドのメソッドを呼び出す必要はありません。
 
**アップロード完了イベント**  

アップロード完了前と完了後のイベントに分かれます。Webman ドキュメントのよく使うコンポーネント → Event イベントの項を参考にしてください。  
`config/event.php` で `aetherupload.before_upload_complete` と `aetherupload.upload_complete` に対応するイベントハンドラクラスを設定します。  
本プラグインの設定ファイルで `groups` 下の該当オプションを `true` に設定します。 
```php
'event_before_upload_complete' => true, 
'event_upload_complete'        => true,
```
この機能を使うと、アップロード完了の前後に追加の処理を行えます。

**緩和モード**  

本プラグインの設定ファイルで `'lax_mode' => true,` を編集し、フロントエンドで `setLaxMode(true)` を呼び出します。  
アップロード前の hash 計算をスキップすることで、全体の所要時間を短縮できます。このオプションを有効にすると、秒伝と完全性チェックは利用できなくなります。

**多言語**  

フロントエンドがブラウザの言語を検出して自動的に設定します。現在は中国語と英語に対応しています。
  
**便利なコンソールコマンド**  

`php webman aetherupload:groups` すべてのグループを一覧表示し、対応するディレクトリを自動で作成します  
`php webman aetherupload:build` Redis 内のリソースファイルの秒伝リストを再構築します  
`php webman aetherupload:clean 2` 2 日前の無効な一時ファイルを削除します  

# 最適化のヒント
* **（推奨）無効な一時ファイルを毎日自動で削除する**  
アップロード処理が予期せず終了することがあります。たとえば転送中にページやブラウザを強制的に閉じると、それまでに生成されたファイルの断片が無効なファイルとして残り、大量のストレージを占有します。crontab の定期実行機能を使って、これらを定期的に削除できます。  
Linux で `crontab -e` コマンドを実行し、次の行が含まれていることを確認してください。  
```php
0 0 * * * php /プロジェクトルートの絶対パス/webman aetherupload:clean 1> /dev/null 2>&1  
```  

* **Redis 内の秒伝リストを毎日自動で再構築する**  
不適切な処理や一部の極端な状況によって秒伝リストに不整合なデータが入り込み、秒伝の正確性に影響することがあります。秒伝リストを再構築すれば不整合なデータを解消し、実際のリソースファイルとの同期を回復できます。  
Linux で `crontab -e` コマンドを実行し、次の行が含まれていることを確認してください。  
```php
0 0 * * * php /プロジェクトルートの絶対パス/webman aetherupload:build 1> /dev/null 2>&1  
```  

* **（任意）nginx の内部リダイレクトを有効にして、大容量ファイルの途中再開と動画のシークを可能にする**  
webman のファイルレスポンスは HTTP Range を実装していないため、大容量ファイルのダウンロードは全体をまとめて送信する形になり、動画のシークもできず、しかも転送中は worker プロセスを 1 つ占有し続けます。nginx 配下にデプロイしている場合は、本プラグインの `x_accel_redirect` オプションを有効にすると、ファイルは nginx が直接送信するようになり、worker は即座に解放され、Range（途中再開、動画のシーク）にも対応します。  
本プラグインの設定ファイルで `'x_accel_redirect' => true,` を編集し、nginx の設定に内部プレフィックス用の location を追加して、`alias` をプロジェクトのアップロードルートの絶対パスに向けます（末尾のスラッシュに注意）。  
```nginx
location /internal-aetherupload/ {
    internal;
    alias /プロジェクトルートの絶対パス/storage/app/aetherupload/;
}
```
この location では `internal` を必ず残してください。外部からプログラムを経由せずにリソースファイルへ直接アクセスされるのを防ぎます。`root_dir` を変更した場合は `alias` も合わせて調整する必要があります。  

* **分割一時ファイルの読み書き速度を上げる（PHP にのみ有効）**  
Linux の tmpfs ファイルシステムを利用して、アップロードされた分割一時ファイルをメモリ上に置き、読み書きを高速化します。空間を時間に替えて読み書きの効率を高める方法で、**追加で**メモリを消費します（おおよそ 1 ブロック分のサイズ）。  
php.ini のアップロード一時ディレクトリ `upload_tmp_dir` の値を `"/dev/shm"` に設定し、サービスを再起動します。  

* **分割一時ファイルの読み書き速度を上げる（システムの一時ディレクトリに適用）**  
Linux の tmpfs ファイルシステムを利用して、アップロードされた分割一時ファイルをメモリ上に置き、読み書きを高速化します。空間を時間に替えて読み書きの効率を高める方法で、**追加で**メモリを消費します（おおよそ 1 ブロック分のサイズ）。  
次のコマンドを実行します。    
`mkdir /dev/shm/tmp`  
`chmod 1777 /dev/shm/tmp`  
`mount --bind /dev/shm/tmp /tmp`  

# 互換性
<table>
  <th></th>
  <th>IE</th>
  <th>Edge</th>
  <th>Firefox</th>
  <th>Chrome</th>
  <th>Safari</th>
  <tr>
  <td>アップロード</td>
  <td>10+</td>
  <td>12+</td>
  <td>3.6+</td>
  <td>6+</td>
  <td>5.1+</td>
  </tr>
  <tr>
  <td>秒伝</td>
  <td>10+</td>
  <td>12+</td>
  <td>3.6+</td>
  <td>6+</td>
  <td>6+</td>
  </tr>
</table>

# セキュリティ
AetherUpload はアップロード前にホワイトリスト + ブラックリストの形でファイルの拡張子をフィルタリングし、アップロード後にファイルの Mime-Type を確認します。ホワイトリストは保存するファイル拡張子を直接制限し、ブラックリストは一般的な実行可能ファイルの拡張子をデフォルトで遮断することで、悪意あるファイルのアップロードを防ぎます。安全のため、ホワイトリストの欄は空にしないでください。  

さまざまな安全対策を行っていますが、悪意あるファイルのアップロードを完全に防ぐことはできません。アップロードディレクトリのパーミッションを適切に設定し、関連するプログラムがリソースファイルに対して実行権限を持たないようにすることを推奨します。
