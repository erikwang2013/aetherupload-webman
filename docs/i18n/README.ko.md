# AetherUpload-Webman  

<img src="../js/aetherupload-pet.svg" width="120" alt="에테르비스트 — AetherUpload 프로젝트 펫">

[中文](../../README.md) · [English](README.en.md) · [한국어](README.ko.md) · [Русский](README.ru.md) · [Deutsch](README.de.md) · [Français](README.fr.md) · [Español](README.es.md) · [Português](README.pt.md) · [हिन्दी](README.hi.md) · [العربية](README.ar.md) · [বাংলা](README.bn.md) · [Bahasa Indonesia](README.id.md) · [日本語](README.ja.md)

본 프로젝트는 널리 쓰이는 Laravel 대용량 파일 업로드 확장 패키지 [AetherUpload-Laravel](https://github.com/peinhu/AetherUpload-Laravel)을 이식하고, [webman](https://www.workerman.net/webman)의 상주 프로세스 모델에 맞춰 설정 로딩, 동시성 처리, 스토리지 계층을 다시 작성했습니다.

**무엇을 해결하는가**

브라우저에서 대용량 파일을 바로 업로드할 때는 세 가지 난관을 피할 수 없습니다. `post_max_size`를 넘으면 올라가지 않고, 네트워크가 한 번 끊기면 처음부터 다시 보내야 하며, 같은 파일이 반복해서 전송됩니다. AetherUpload의 방식은 이렇습니다. 브라우저에서 파일을 청크로 나누고, 조각을 하나씩 서버의 임시 파일에 추가하고, 저장할 때는 파일 내용의 md5로 이름을 붙입니다. 그래서 **업로드, 이어올리기, 초고속 업로드, 중복 제거, 무결성 검증**이 하나의 메커니즘을 공유하고, 전체 파일을 메모리로 읽어들이지 않으며, "파일이 어디 있는가"를 관리할 데이터베이스 테이블도 필요하지 않습니다.

**그 형태**

composer 패키지 하나이며, **커널이 호스트 프레임워크와 분리**되어 있습니다. 같은 코드가 webman, 네이티브 PHP(프레임워크 없음), Laravel, ThinkPHP, Symfony, Slim, Hyperf, Yii2에서 동작합니다([지원하는 프레임워크](#지원하는-프레임워크) 참고). 호스트의 설정, 라우트, 콘솔 명령, 언어 파일, 프런트엔드 스크립트는 설치 / 배포 명령으로 배포됩니다. 데이터베이스에 의존하지 않으며, Redis는 초고속 업로드를 켤 때만 필요하므로 선택적 의존성입니다.

![예제 페이지](http://wx2.sinaimg.cn/mw690/69e23056gy1fho6ymepjlg20go0aknar.gif) 

# 프로젝트 구조

```text
aetherupload-webman/
├── src/                          플러그인 소스
│   ├── Runtime.php                 호스트 바인딩 지점(정적 파사드): 프로세스 수준에서는 불변 바인딩만 보유하고, 바인딩되지 않았으면 명확히 오류를 냅니다
│   ├── RequestContext.php          요청 / 코루틴별 가변 상태(그룹 설정 스냅샷, 로드된 언어), 상주 프로세스에서 동시 요청이 그룹을 뒤섞지 않습니다
│   ├── Contract/                   인터페이스 11개(설정 / 번역 / 요청 / 업로드 파일 / 응답 / Redis / 이벤트 / 경로 / 파일시스템 / 컨텍스트 / 어댑터)
│   ├── Kernel/                     커널 측 기본 구현: AbstractAdapter, PrefixedConfig, Filesystem, NullRedis, PhpFileTranslator, ClientRedis…
│   ├── Console/                    콘솔 명령 세 개의 비즈니스 로직(Runner) + 콘솔 관례가 없는 호스트의 진입점, 일곱 가지 명령 셸이 같은 구현을 공유합니다
│   ├── Adapter/                    어댑터 여덟 개: Webman / Native / Laravel / ThinkPhp / Symfony / Slim / Hyperf / Yii
│   ├── UploadController.php        업로드 진입점: preprocess(전처리 / 초고속 판정)와 saveChunk(청크 쓰기)
│   ├── ResourceController.php      표시와 다운로드 진입점: display / download, nginx 직송 지원
│   ├── PartialResource.php         청크 파일 본체: 경로 조립, 청크 단위 추가, 이름 변경, 크기와 타입 검증
│   ├── Header.php                  이어올리기 상태 파일: chunkIndex 하나만 저장합니다
│   ├── Resource.php                완성본 파일 객체, "업로드 완료" 이벤트에 인자로 주입됩니다
│   ├── RedisSavedPath.php          초고속 업로드 인덱스: 레코드마다 key 하나, TTL 독립
│   ├── SavedPathResolver.php       저장 경로 인코딩/디코딩(3단 무상태 주소 지정)
│   ├── ConfigMapper.php            설정 싱글턴 + 요청별 그룹 설정 스냅샷
│   ├── MimeType.php                mime ↔ 확장자 매핑, 저장 전 화이트리스트로 재확인
│   ├── Util.php                    임시 이름 생성, 경로 안전성 검증, 리소스 삭제
│   ├── Install.php                 설치 / 제거: 호스트 애플리케이션에 설정과 리소스를 배포
│   ├── Responser.php               JSON 응답 래퍼
│   ├── SimpleValidateTrait.php     최소한의 필수값 검증
│   ├── ExamplePageTrait.php        예제 페이지
│   └── helpers.php                 템플릿 헬퍼: aetherupload_display_link / aetherupload_download_link
├── commands/                     webman의 콘솔 셸(설치 시 app/command로 복사되며, 로직은 src/Console에 있습니다)
│   ├── AetherUploadListGroups.php        php webman aetherupload:groups    그룹을 나열하고 해당 디렉터리 생성
│   ├── AetherUploadBuildRedisHashes.php  php webman aetherupload:build     디스크 현황에 맞춰 초고속 업로드 인덱스 재구축
│   └── AetherUploadCleanUpDirectory.php  php webman aetherupload:clean N   mtime 기준으로 만료된 임시 파일 정리
├── config/
│   ├── app.php                   webman 플러그인 설정: 그룹, 라우트, 미들웨어와 각종 스위치
│   ├── aetherupload.php          프레임워크에 독립적인 동일한 설정, 나머지 일곱 어댑터의 기준선(두 경로는 ConfigParityTest가 일치를 강제합니다)
│   └── route.php                 라우트 네 개 + 각각의 미들웨어 장착 지점
├── docs/                         문서, 이미지, 프런트엔드 스크립트
│   ├── aetherupload-architecture.svg  설계 아키텍처 다이어그램
│   ├── aetherupload-design.svg        설계 접근법 다이어그램
│   ├── aetherupload-lifecycle.svg     업로드 생명주기 다이어그램
│   ├── HARNESS.md                     단위 테스트 계약(테스트를 작성하기 전에 반드시 읽어야 합니다)
│   ├── REPORT.md                      특정 시점의 전체 테스트와 커버리지 요약 보고서
│   ├── i18n/                          다국어 README와 세 장의 설계도 번역(12개 언어, 번역 내비게이션은 TRANSLATING.md 참고)
│   └── js/                            프런트엔드 리소스(<문서 루트>/vendor/aetherupload/js로 배포됩니다)
│       ├── aetherupload-all.js        번들판: 코어 + zepto + spark-md5
│       ├── aetherupload-core.js       핵심 로직: hash 계산, 청크 분할, 업로드, 진행률, 연결 끊김 재시도
│       └── aetherupload-pet.svg       프로젝트 펫 "에테르비스트", 예제 페이지 아이콘과 사이트 아이콘으로 함께 사용됩니다
├── translations/{zh,en}/         다국어(호스트의 translations 경로 아래 aetherupload/로 배포됩니다)
├── views/example.blade.php       예제 페이지 소스, 연동할 때 그대로 참고할 수 있습니다
├── tests/
│   ├── *.php                     PHPUnit 단위 케이스(호스트는 대역을 사용, PHP 8.0–8.4, PHPUnit 9.6과 10.5 두 버전)
│   └── Integration/<fw>/         엔드투엔드 스위트, 프레임워크마다 한 벌: 프레임워크를 실제로 설치하고 업로드 경로를 실제로 실행합니다(webman은 phpunit.xml로 실제 서비스를 띄우고, 나머지 일곱은 각자의 ci.sh)
├── uploads/                      레거시 디렉터리, 플러그인 실행 시에는 사용하지 않습니다
└── composer.json
```

> `src/` 루트 아래의 파일 15개(컨트롤러, 청크, 이어올리기, 초고속 업로드 인덱스, 경로 인코딩/디코딩, 오류 응답…)는 프레임워크에 독립적인 업로드 커널입니다. 이들은 어떤 호스트 네임스페이스도 참조하지 않고, 설정 / 번역 / 요청 / 응답 / Redis / 이벤트를 모두 `Runtime`을 통해 포트로 받습니다.
> 유일한 예외는 `Install.php`입니다. `composer require`가 트리거하는 설치 스크립트는 호스트 애플리케이션이 뜨기 전에 실행되어 `Runtime`이 아직 바인딩되지 않았고, 그런 상황에서는 webman 어댑터를 스스로 바인딩하는 폴백을 거쳐 패키지 안의 `config/app.php`로 되돌아갑니다.

> 설치 전에는 이들 모두 패키지 내부 파일입니다. `composer require` 이후 `Install.php`가 위 표에 표시된 매핑에 따라 설정, 명령, 프런트엔드 스크립트, 언어 파일을 호스트 애플리케이션의 해당 위치로 배포합니다.

# 설계 아키텍처

<img src="img/aetherupload-architecture.ko.svg" alt="AetherUpload-Webman 아키텍처 다이어그램">

- **클라이언트**: `aetherupload-all.js` 하나만 포함하면 됩니다(zepto와 spark-md5가 들어 있습니다). 이 스크립트가 md5 계산, 청크 분할, 진행률 표시줄, 연결 끊김 자동 재시도를 담당합니다.
- **서버**: `UploadController`에는 진입점이 두 개뿐입니다(전처리, 청크 쓰기). `PartialResource`가 경로, 추가, 이름 변경을 맡고 `Header`는 chunkIndex만 기록합니다. 설정은 `ConfigMapper`가 요청 단위로 스냅샷을 떠서, 상주 프로세스에서 동시 요청이 서로 그룹을 섞지 않게 합니다.
- **스토리지와 운영**: 디스크에는 언제나 세 종류의 파일만 있습니다. `*.part` 청크, `_header/` 이어올리기, `<md5>.<ext>` 완성본입니다. Redis는 초고속 업로드를 켰을 때만 사용하고, `x_accel_redirect`를 켜면 nginx가 파일을 직접 보내며 Range와 이어받기까지 함께 해결됩니다.

# 설계 접근법

<img src="img/aetherupload-design.ko.svg" alt="AetherUpload-Webman 설계 접근법">

그림의 세 가지 결정 외에도 독립적인 선택이 하나 더 있습니다. **초고속 업로드 인덱스를 선택적 의존성으로 두고, 레코드마다 TTL을 독립시키는 것**입니다. Redis를 설치하지 않은 사이트도 업로드는 그대로 됩니다(초고속 업로드만 동작하지 않습니다). Redis를 설치한 사이트에서는 레코드마다 `SETEX`로 각자 만료되어, 만료 시간을 공유하지도 않고 계속 업로드해도 무한정 커지지도 않습니다. 업그레이드 후에도 예전 hash 레코드를 폴백으로 읽을 수 있습니다. 일관성은 `aetherupload:build`(매일 재구축)와 `aetherupload:clean`(mtime 기준으로 임시 파일 회수)이 맡습니다.

# 업로드 생명주기

<img src="img/aetherupload-lifecycle.ko.svg" alt="AetherUpload-Webman 업로드 생명주기">

주 경로는 네 단계뿐입니다. **전처리 → 청크(반복) → 마지막 청크 검증 → 저장**. 전처리는 빈 `.part`를 만들고 `chunkIndex=0`을 header에 씁니다. 각 청크의 순서는 "검증 → 추가 → chunkIndex 되쓰기"로 고정됩니다. 크기와 MIME 검증, 전체 md5 재계산, `<md5>.<ext>`로 이름 변경, 초고속 업로드 인덱스 기록은 마지막 청크에서만 일어납니다.

따로 설명할 가치가 있는 두 갈래 우회 경로가 있습니다.

- **초고속 업로드 적중**: 전처리 단계에서 같은 md5의 완성본을 발견하면 그 경로를 바로 돌려주고, 청크는 하나도 전송하지 않습니다.
- **중단과 복구**: 같은 번호를 다시 보내면 멱등하게 건너뜁니다. 번호가 건너뛰거나 청크가 잘린 경우에는 오류만 반환하고 `.part`와 header를 **정리하지 않습니다**. 그래서 약한 네트워크에서도 클라이언트가 빠진 청크를 채울 때까지 계속 재시도할 수 있습니다. 실제로 정리되는 경우는 두 가지뿐입니다. 마지막 청크 검증에 실패했을 때(전체 폐기)와, 페이지를 닫은 뒤 cron이 `aetherupload:clean`을 돌려 mtime 기준으로 회수할 때입니다.

# 기능 특성
- [x] 백분율 진행률 표시줄  
- [x] 파일 타입 제한  
- [x] 파일 크기 제한  
- [x] 다국어 지원  
- [x] 리소스 그룹 설정  
- [x] 업로드 완료 이벤트   
- [x] 동기 업로드 *①*  
- [x] 이어올리기 *②*  
- [x] 파일 초고속 업로드 *③*  
- [x] 사용자 정의 미들웨어 *④*  
- [x] 사용자 정의 라우트   
- [x] 완화 모드

*①: 동기 업로드는 비동기 업로드에 비해 업로드 대역폭이 충분히 클 때 속도가 조금 느립니다. 다만 동기는 업로드와 동시에 파일을 합칠 수 있는 반면, 비동기는 파일 청크의 업로드 완료 순서가 일정하지 않아 모든 청크가 끝나야 합칠 수 있으므로, 비동기 업로드는 완료에 가까워질수록 오래 기다려야 합니다. 동기 업로드는 한 번에 하나의 청크만 업로드하므로 단위 시간당 서버가 점유하는 메모리가 적어, 비동기 방식보다 동시 업로드 인원을 더 많이 수용할 수 있습니다.*  

*②: 이어올리기는 이어받기와 다릅니다. 이어올리기란 네트워크가 끊기거나 무선 네트워크가 불안정할 때 페이지를 닫지 않은 상태에서 업로드 컴포넌트가 주기적으로 자동 재시도하고, 네트워크가 복구되면 업로드에 성공하지 못한 그 청크부터 이어서 올리는 것을 말합니다. 이어올리기는 페이지를 새로 고치거나 닫았다가 다시 열면 이어올릴 수 없고, 이전에 업로드된 부분은 무효 파일이 됩니다.*  

*③: 파일 초고속 업로드는 서버 측 Redis와 클라이언트 브라우저의 지원(FileReader, File.slice())이 모두 필요하며, 둘 중 하나라도 없으면 초고속 업로드가 동작하지 않습니다. 기본값은 꺼짐이며 설정 파일에서 켜야 합니다.*  

*④: 사용자 정의 미들웨어와 결합하면 이미 업로드된 리소스의 접근과 다운로드 동작에 대한 권한 제어를 할 수 있습니다.*


# 지원하는 프레임워크

커널(청크, 이어올리기, 초고속 업로드, 검증, 주소 지정)은 호스트 프레임워크와 분리되어 있고, 같은 패키지가 아래 **호스트**에서 동작합니다. 각각 **프레임워크를 실제로 설치하고 전체 업로드 경로를 실제로 실행하는** 엔드투엔드 테스트가 뒷받침합니다.

| 호스트 | 연동 방식 | 엔드투엔드 테스트 |
|---|---|---|
| webman | 네이티브 지원(`composer require`만 하면 설정/라우트/명령/프런트엔드 스크립트가 자동 배포) | `tests/Integration/webman/` |
| **네이티브 PHP(프레임워크 없음)** | `Bootstrap::handle()` 한 줄, 라우트는 이 패키지가 설정에 따라 분배 | `tests/Integration/native/` |
| Laravel | ServiceProvider + `vendor:publish` | `tests/Integration/laravel/` |
| ThinkPHP | `app/service.php`에 Service 등록 | `tests/Integration/thinkphp/` |
| Symfony | Bundle + 라우트 리소스 import | `tests/Integration/symfony/` |
| Slim | `Bootstrap::create()` 한 줄 | `tests/Integration/slim/` |
| Hyperf | `ConfigProvider` | `tests/Integration/hyperf/` |
| Yii2 | 애플리케이션 `bootstrap`에 Bootstrap 등록 | `tests/Integration/yii/` |

> 패키지 이름에 `webman`이 들어간 것은 역사적인 이유입니다(이 플러그인이 처음에는 webman만 지원했습니다). 나머지 호스트에서 사용하는 데에는 영향을 주지 않습니다.

## 공통 두 단계

어떤 호스트든 패키지를 설치한 뒤 **반드시** 해야 하는 작업이 두 가지 있습니다(webman은 설치 스크립트가 자동으로 처리하고, 나머지 호스트는 해당 명령을 직접 실행해야 합니다).

1. **스토리지 디렉터리 생성**: `aetherupload:groups` —— `root_dir`, `_header`와 각 그룹 디렉터리를 만듭니다.
   **만들지 않으면 반드시 실패합니다**: 커널의 `createGroupSubDir()`는 재귀가 아닌 `mkdir`이라 부모 디렉터리가 없으면 바로 false를 반환하는데, 그 오류가 포괄적인 `upload_error`로 번역되어 버려서 문제를 추적할 때 디렉터리 문제라는 것을 알아내기 어렵습니다.
2. **파일 배포**: `aetherupload:publish`(Laravel은 `vendor:publish --tag=aetherupload-*`) —— 언어 파일과 프런트엔드 `js`를 호스트가 접근할 수 있는 위치에 넣습니다.

> 명령 이름의 구분자는 각 프레임워크의 콘솔 관례를 따릅니다. webman / Laravel / ThinkPHP / Symfony / Hyperf / Slim / 네이티브 PHP는 `:`를 쓰고, **Yii는 `/`**를 씁니다(`php yii aetherupload/groups`). 아래 각 프레임워크의 예시는 그대로 복사해서 실행할 수 있는 형태입니다.

## 각 프레임워크 연동

**네이티브 PHP(프레임워크 없음)**

PHP에서 돌아가는 애플리케이션이라면 무엇이든 바로 쓸 수 있습니다 —— 프레임워크도, 미들웨어도, 서비스 등록도 없이 진입 스크립트는 한 줄입니다:

```php
// public/index.php(FPM / Apache / nginx+php-fpm 진입 스크립트)
require __DIR__ . '/../vendor/autoload.php';

exit(\AetherUpload\Adapter\Native\Bootstrap::handle([
    'base_path' => dirname(__DIR__),
    'config'    => require __DIR__ . '/../config/aetherupload.php',   // 생략하면 패키지 내부 기본값을 사용
    'redis'     => static fn () => new \Redis(),                       // 초고속 업로드에 필요, 선택 사항
]));
```

```bash
php bin/aetherupload aetherupload:groups     # 디렉터리 생성
php bin/aetherupload aetherupload:publish    # 언어 파일과 프런트엔드 js 배포
```

`bin/aetherupload`도 세 줄이면 되며, 직접 한 벌 두면 됩니다:

```php
#!/usr/bin/env php
<?php
require __DIR__ . '/../vendor/autoload.php';
\AetherUpload\Adapter\Native\Bootstrap::bind(require __DIR__ . '/../config/aetherupload.php', dirname(__DIR__));
exit((new \AetherUpload\Console\Application())->run());
```

> **라우트를 직접 작성할 필요가 없습니다**: `Bootstrap::handle()`이 설정의 `route_preprocess` / `route_uploading` / `route_display` / `route_download`에 따라 현재 요청을 분배하며, 매칭되는 것이 없으면 404, 메서드가 맞지 않으면 405와 함께 `Allow`를 돌려줍니다.
> **미들웨어**: 설정의 `middleware_*`는 이 호스트에서 **인자를 받지 않는 호출 가능 객체**이며, 응답 객체를 반환하면 거기서 바로 단락됩니다 —— 권한이 없으면 바로 `return Runtime::response()->text('forbidden', 403);` 하고, 다른 값을 반환하면 무시하고 계속 진행합니다(네 번째 각주에서 말한 권한 제어가 바로 이렇게 연결됩니다).
> **내장 서버**: `php -S 127.0.0.1:8080 -t public public/index.php` 하면 됩니다. 내장 서버가 `public/` 안의 정적 파일을 직접 내보내게 하려면(배포된 프런트엔드 js가 이 경로를 탑니다), `handle()` 앞에 `if (is_file(__DIR__ . parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH))) { return false; }`를 한 줄 넣으십시오.
> **내장 함수로 폴백하지 않습니다**: 요청은 `$_GET`/`$_POST`/`$_FILES`를 읽고(PHP가 추가로 필터링하지 않으며 커널의 타입 가드는 그대로 적용됩니다), 응답은 `NativeResponse::send()`가 `header()` + `echo`로 한 번에 내보냅니다. 프레임워크의 중간 계층이 없습니다.

**Laravel**

```php
// bootstrap/providers.php(Laravel 11+) 또는 config/app.php의 providers 배열
AetherUpload\Adapter\Laravel\AetherUploadServiceProvider::class,
```
```bash
php artisan vendor:publish --tag=aetherupload-config         # config/aetherupload.php 배포
php artisan vendor:publish --tag=aetherupload-translations   # 언어 파일 배포
php artisan vendor:publish --tag=aetherupload-assets         # 프런트엔드 js 배포
php artisan aetherupload:groups
```
> 이 패키지의 composer.json에는 `extra.laravel.providers`가 **없습니다**(각 프레임워크의 의존성이 상호 배타적이라 자동 발견을 하드코딩할 수 없습니다). 따라서 provider를 직접 등록해야 합니다.
> 설정 병합은 **얕은 병합**입니다. 애플리케이션이 `config/aetherupload.php`를 한 번 배포하면 그 안의 `groups`가 플러그인 기본값을 **통째로 교체**합니다. 그룹을 추가할 때는 기본 그룹도 함께 복사해 넣으십시오.

**ThinkPHP**

```php
// app/service.php
return [ \AetherUpload\Adapter\ThinkPhp\AetherUploadService::class ];
```
```bash
php think aetherupload:groups
php think aetherupload:publish
```
> 마찬가지로 `groups`는 통째로 교체됩니다. 또한 `config/route.php`와 `config/lang.php`는 **필수 파일**이며, 없으면 프레임워크가 `array_merge`/`array_change_key_case`에서 null을 받아 TypeError를 던집니다.

**Symfony**

```php
// config/bundles.php
AetherUpload\Adapter\Symfony\AetherUploadBundle::class => ['all' => true],
```
```yaml
# config/routes.yaml(Symfony의 bundle 라우트는 애플리케이션 쪽에서 import해야 하며, 자동 로딩 기제가 없습니다)
aetherupload:
    resource: '@AetherUploadBundle/Resources/config/routes.php'
    type: php
```
> `Configuration` 트리가 모든 설정 키를 선언하며, 선언되지 않은 키가 있으면 컨테이너 시작이 실패합니다(조용히 무시하지 않습니다).

**Slim**

```php
// public/index.php
$app = \AetherUpload\Adapter\Slim\Bootstrap::create([
    'config'    => require __DIR__ . '/../config/aetherupload.php',   // 생략하면 패키지 내부 기본값을 사용
    'base_path' => dirname(__DIR__),
    'redis'     => static fn () => new Predis\Client(['database' => 0]),   // 초고속 업로드에 필요
]);
$app->run();
```
> Slim의 PSR-7 응답은 불변이므로, 커널 산출물은 `Bootstrap::handler()` 계층에서 진짜 PSR-7로 만들어집니다(**유일한 변환 지점**이며, 미들웨어에 넣으면 통과하지 못합니다).
> Slim에는 콘솔 관례가 없어서, 이 패키지가 네 명령의 진입 클래스를 제공합니다. 진입 스크립트는 직접 한 벌 두어야 합니다(네 줄):
> ```php
> // bin/aetherupload
> require __DIR__ . '/../vendor/autoload.php';
> \AetherUpload\Adapter\Slim\Bootstrap::bind(require __DIR__ . '/../config/aetherupload.php', dirname(__DIR__));
> exit((new \AetherUpload\Adapter\Slim\Console\Application())->run());
> ```
> ```bash
> php bin/aetherupload aetherupload:groups     # publish / build / clean도 있습니다
> ```
> `symfony/console`이 필요합니다(이 패키지의 require-dev / suggest).

**Hyperf**

```php
// config/config.php —— 이 패키지는 extra.hyperf.config를 쓰지 않습니다(다른 프레임워크의 자동 발견
// 기제와 간섭하므로 독립 검증 뒤에 보강 예정). 그래서 여기서 ConfigProvider를 명시적으로 펼칩니다(라우트, 명령, publish 키를 여기서 제공)
return [
    // …
] + (new \AetherUpload\Adapter\Hyperf\ConfigProvider())();
```
```bash
php bin/hyperf.php aetherupload:groups
php bin/hyperf.php aetherupload:publish
```
> `ext-swoole`이 필요합니다. 개발 중 주의할 점이 두 가지 더 있습니다. `xdebug.mode=profile`이면 Hyperf 콜드 스타트가 **조용히 종료됩니다**(`ClassLoader::init()`에서 exit 255, PHP 오류는 전혀 없음). CI와 로컬에서는 `XDEBUG_MODE=off`로 두십시오. swoole의 `Coroutine\run()`은 PHPUnit과 같은 프로세스에서 돌 수 없으므로, 코루틴 관련 테스트는 프로브를 별도 프로세스에서 실행해야 합니다.

**Yii2**

```php
// 애플리케이션 설정
return [
    'bootstrap' => ['aetherupload' => \AetherUpload\Adapter\Yii\Bootstrap::class],
    'params'    => ['aetherupload' => require __DIR__ . '/aetherupload.php'],   // publish가 생성한 샘플을 기반으로 해도 됩니다
    'components' => [
        // 전제 조건: 반드시 components 아래에 써야 합니다. 최상위의 같은 이름 키는 Yii가 무시하고,
        // 그러면 enableStrictParsing이 동작하지 않아 라우트의 verb 제약이 우회됩니다(GET이 POST만 선언한 액션에 도달)
        'urlManager' => ['enableStrictParsing' => true],
        // 초고속 업로드에 필요합니다. 콘솔 애플리케이션에도 설정해야 하며, 그렇지 않으면 aetherupload/build가 Unknown component ID: redis를 냅니다
        'redis' => [ /* … */ ],
    ],
];
```
```bash
php yii aetherupload/groups     # 주의: Yii의 콘솔 구분자는 "/"입니다. aetherupload:groups로 쓰면 Unknown command가 납니다
php yii aetherupload/publish
```
> 배포 위치는 **문서 루트** 아래의 `vendor/aetherupload/js`입니다(webman의 문서 루트는 `public/`, Yii는 `web/`). 예제 페이지가 참조하는 절대 경로 `/vendor/aetherupload/js/…`의 의미가 "문서 루트"이므로, `YiiPaths::assetPath()`는 호스트의 `@webroot` 별칭을 우선 사용하고, 얻지 못하면 `<app>/web`으로 물러납니다.

**webman**

```bash
# webman 프로젝트 루트에서 실행
composer require erikwang2013/aetherupload-webman
```
> webman은 유일하게 **설정이 필요 없는** 호스트입니다. `composer require` 시점에 `Install.php`가 설정, 라우트, 명령, 언어 파일, 프런트엔드 스크립트를 자동 배포하고 스토리지 디렉터리까지 만들어 둡니다. 설치 후 `http://도메인/aetherupload`에 바로 접속하면 예제 페이지입니다.
>
> 참고: 관련 설정 옵션을 바꾸려면 `config/plugin/erikwang2013/aetherupload-webman/app.php`를 편집하십시오.

> 나머지 일곱 호스트는 먼저 어댑터를 등록한 뒤 "공통 두 단계"를 실행해야 합니다. **webman도 같은 명령을 제공합니다**(`php webman aetherupload:groups` / `aetherupload:publish`). 다만 설치할 때 이미 자동으로 한 번 돌았습니다.

# 사용  
**파일 업로드**  

예제 파일과 주석을 참고하여, 대용량 파일을 업로드할 페이지에 해당 파일과 코드를 포함시키십시오.

**그룹 설정**  

이 플러그인 설정 파일의 `groups` 아래에 그룹을 추가하고 `php webman aetherupload:groups`를 실행하면 해당 디렉터리가 자동 생성됩니다.  
```php
'video' => [
    'group_dir'                    => 'video',
    'resource_maxsize'             => 0,
    'resource_extensions'          => [],
    'event_before_upload_complete' => false, 
    'event_upload_complete'        => false,
],
```
프런트엔드에서 `setGroup('그룹 이름')` 메서드를 호출해 업로드 그룹을 지정합니다. 그룹 이름은 반드시 이미 존재해야 하고, **밑줄을 포함할 수 없습니다**(그룹 이름은 저장 경로 인코딩에 참여하므로 밑줄이 들어가면 해당 그룹의 리소스를 찾을 수 없게 되어 설정과 업로드 단계에서 바로 실패합니다).

**초고속 업로드 기능 추가(Redis와 브라우저 지원 필요)**  

Webman 문서의 Redis 부분을 참고하여 필요한 의존성을 설치하십시오.  
Redis를 설치하고 서비스를 시작합니다.  
predis 패키지를 설치합니다 `composer require predis/predis`.  
`config/redis.php`에서 `client`를 `predis`로 설정합니다.  
이 플러그인 설정 파일에서 `instant_completion`을 `true`로 설정합니다.

*참고: Redis에는 실제 리소스 파일에 대응하는 초고속 업로드 목록이 유지되며, 실제 리소스 파일의 추가와 삭제로 생긴 변화를 모두 이 목록에 동기화해야 합니다. 그렇지 않으면 더티 데이터가 생깁니다.  
확장 패키지에 추가 부분은 이미 들어 있습니다. 리소스 파일을 삭제해야 할 때는 사용자가 해당 메서드를 직접 호출해 파일과 초고속 업로드 목록의 레코드를 지워야 합니다.* 
```php
\AetherUpload\Util::deleteResource($savedPath); //해당 리소스 파일 삭제
\AetherUpload\Util::deleteRedisSavedPath($savedPath); //해당 Redis 초고속 업로드 레코드 삭제
``` 
*둘 다 멱등 연산입니다. 파일이나 초고속 업로드 레코드가 이미 없어도 true를 반환합니다.* 

**사용자 정의 미들웨어**  

Webman 문서의 라우트 미들웨어 부분을 참고하여 미들웨어를 만들고, 작성한 미들웨어 이름을 설정 파일의 해당 부분에 넣으십시오.  
```php
'middleware_download' => [app\middleware\MiddlewareA::class,app\middleware\MiddlewareB::class],
```  
이 기능으로 파일의 업로드, 접근, 다운로드 동작에 대한 권한 제어를 할 수 있습니다.

**사용자 정의 라우트**  

이 플러그인 설정 파일에서 `'route_uploading' => '/aetherupload/uploading'` 등의 옵션을 편집하고, 프런트엔드에서 `setUploadingRoute('/aetherupload/uploading')` 등의 메서드를 호출합니다.  
파일 접근, 다운로드 라우트는 편집한 뒤 바로 접근할 수 있으며 프런트엔드 메서드를 호출할 필요가 없습니다.
 
**업로드 완료 이벤트**  

업로드 완료 전과 완료 후 이벤트로 나뉘며, Webman 문서의 공용 컴포넌트 Event 부분을 참고하십시오.  
`config/event.php`에서 `aetherupload.before_upload_complete`와 `aetherupload.upload_complete`에 대응하는 이벤트 처리 클래스를 설정합니다.  
이 플러그인 설정 파일에서 `groups` 아래의 해당 옵션을 `true`로 설정합니다. 
```php
'event_before_upload_complete' => true, 
'event_upload_complete'        => true,
```
이 기능으로 업로드 완료 전과 완료 후에 추가 처리를 할 수 있습니다.

**완화 모드**  

이 플러그인 설정 파일에서 `'lax_mode' => true,`로 편집하고, 프런트엔드에서 `setLaxMode(true)` 메서드를 호출합니다.  
업로드 전에 hash 계산을 건너뛰어 전체 소요 시간을 줄일 수 있습니다. 이 옵션을 켜면 초고속 업로드와 무결성 검증을 할 수 없습니다.

**다국어**  

프런트엔드가 브라우저 언어를 감지해 자동으로 설정하며, 현재 중국어와 영어를 지원합니다.
  
**편리한 콘솔 명령**  

`php webman aetherupload:groups` 모든 그룹을 나열하고 해당 디렉터리를 자동 생성  
`php webman aetherupload:build` Redis에서 리소스 파일의 초고속 업로드 목록 재구축  
`php webman aetherupload:clean 2` 2일 전의 무효 임시 파일 정리  

# 최적화 제안
* **(권장) 매일 무효 임시 파일을 자동으로 정리하도록 설정**  
업로드 과정에는 예기치 않게 종료되는 경우가 있습니다. 전송 중에 페이지나 브라우저를 강제로 닫으면 이미 생성된 파일 조각이 무효 파일이 되어 많은 저장 공간을 차지합니다. crontab의 정기 작업 기능으로 주기적으로 정리할 수 있습니다.  
Linux에서 `crontab -e` 명령을 실행하고 파일에 다음 줄이 들어 있는지 확인하십시오.  
```php
0 0 * * * php /프로젝트 루트의 절대 경로/webman aetherupload:clean 1> /dev/null 2>&1  
```  

* **매일 Redis의 초고속 업로드 목록을 자동으로 재구축하도록 설정**  
부적절한 처리와 일부 극단적인 상황은 초고속 업로드 목록에 더티 데이터를 만들 수 있고, 그러면 초고속 업로드의 정확성에 영향을 줍니다. 목록을 재구축하면 더티 데이터를 없애고 실제 리소스 파일과의 동기화를 되찾을 수 있습니다.  
Linux에서 `crontab -e` 명령을 실행하고 파일에 다음 줄이 들어 있는지 확인하십시오.  
```php
0 0 * * * php /프로젝트 루트의 절대 경로/webman aetherupload:build 1> /dev/null 2>&1  
```  

* **(선택) nginx 내부 리다이렉트를 켜서 대용량 파일 이어받기와 동영상 탐색 지원**  
webman의 파일 응답은 HTTP Range를 구현하지 않으므로, 대용량 파일은 통째로 내려가고 동영상은 진행 바를 끌 수 없으며, 전송이 끝날 때까지 worker 프로세스 하나를 계속 점유합니다. nginx 아래에 배포했다면 이 플러그인의 `x_accel_redirect` 옵션을 켜서 파일을 nginx가 직접 보내게 할 수 있습니다. worker는 즉시 반환되고 Range(이어받기, 동영상 탐색)도 지원됩니다.  
이 플러그인 설정 파일에서 `'x_accel_redirect' => true,`로 편집하고, nginx 설정에서 내부 프리픽스에 대한 location을 추가하며 `alias`가 프로젝트 업로드 루트의 절대 경로를 가리키게 하십시오(끝의 슬래시에 주의).  
```nginx
location /internal-aetherupload/ {
    internal;
    alias /프로젝트 루트의 절대 경로/storage/app/aetherupload/;
}
```
이 location은 반드시 `internal`을 유지해야 외부에서 프로그램을 우회해 리소스 파일에 직접 접근하는 것을 막을 수 있습니다. `root_dir`을 바꾸면 `alias`도 함께 조정해야 합니다.  

* **청크 임시 파일 읽기/쓰기 속도 향상(PHP에만 적용)**  
Linux의 tmpfs 파일 시스템을 이용해 업로드된 청크 임시 파일을 메모리에 두어 빠르게 읽고 쓰는 방법입니다. 공간을 시간과 맞바꿔 읽기/쓰기 효율을 높이며, 메모리를 **추가로 점유**합니다(대략 청크 하나 크기).  
php.ini의 업로드 임시 디렉터리 `upload_tmp_dir` 항목 값을 `"/dev/shm"`으로 설정하고 서비스를 재시작하십시오.  

* **청크 임시 파일 읽기/쓰기 속도 향상(시스템 임시 디렉터리에 적용)**  
Linux의 tmpfs 파일 시스템을 이용해 업로드된 청크 임시 파일을 메모리에 두어 빠르게 읽고 쓰는 방법입니다. 공간을 시간과 맞바꿔 읽기/쓰기 효율을 높이며, 메모리를 **추가로 점유**합니다(대략 청크 하나 크기).  
다음 명령을 실행하십시오:    
`mkdir /dev/shm/tmp`  
`chmod 1777 /dev/shm/tmp`  
`mount --bind /dev/shm/tmp /tmp`  

# 호환성
<table>
  <th></th>
  <th>IE</th>
  <th>Edge</th>
  <th>Firefox</th>
  <th>Chrome</th>
  <th>Safari</th>
  <tr>
  <td>업로드</td>
  <td>10+</td>
  <td>12+</td>
  <td>3.6+</td>
  <td>6+</td>
  <td>5.1+</td>
  </tr>
  <tr>
  <td>초고속 업로드</td>
  <td>10+</td>
  <td>12+</td>
  <td>3.6+</td>
  <td>6+</td>
  <td>6+</td>
  </tr>
</table>

# 보안
AetherUpload는 업로드 전에 화이트리스트 + 블랙리스트 형태로 파일 확장자를 필터링하고, 업로드 후에는 파일의 Mime-Type을 검사합니다. 화이트리스트는 저장되는 파일 확장자를 직접 제한하고, 블랙리스트는 기본적으로 흔한 실행 파일 확장자를 차단하여 악성 파일 업로드를 막습니다. 보안을 위해 화이트리스트 항목은 비워 두지 마십시오.  

많은 보안 조치를 했더라도 악성 파일 업로드는 막기 어렵습니다. 업로드 디렉터리 권한을 올바르게 설정하여 관련 프로그램이 리소스 파일에 실행 권한을 갖지 않도록 하십시오.
