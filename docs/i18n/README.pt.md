# AetherUpload-Webman  

<img src="../js/aetherupload-pet.svg" width="120" alt="Aether Beast — mascote do projeto AetherUpload">

[中文](../../README.md) · [English](README.en.md) · [한국어](README.ko.md) · [Русский](README.ru.md) · [Deutsch](README.de.md) · [Français](README.fr.md) · [Español](README.es.md) · [Português](README.pt.md) · [हिन्दी](README.hi.md) · [العربية](README.ar.md) · [বাংলা](README.bn.md) · [Bahasa Indonesia](README.id.md) · [日本語](README.ja.md)

> Esta é a versão em português brasileiro (pt-BR) deste documento.

Este projeto é um porte do consagrado pacote de upload de arquivos grandes para Laravel [AetherUpload-Laravel](https://github.com/peinhu/AetherUpload-Laravel), com a leitura de configuração, o tratamento de concorrência e a camada de armazenamento reescritos para o modelo de processo persistente do [webman](https://www.workerman.net/webman).

**O que ele resolve**

Enviar arquivos grandes direto do navegador esbarra sempre em três problemas: acima de `post_max_size` o upload simplesmente não passa; se a rede cai, tudo recomeça do zero; e o mesmo arquivo acaba trafegando várias vezes. A abordagem do AetherUpload é esta — fatiar o arquivo no navegador, anexar cada pedaço a um arquivo temporário no servidor e, na hora de gravar em disco, nomear pelo md5 do conteúdo. Com isso, **upload, retomada após queda de conexão, upload instantâneo, deduplicação e verificação de integridade** compartilham o mesmo mecanismo, sem nunca carregar o arquivo inteiro na memória e sem manter uma tabela de banco só para saber "onde está o arquivo".

**Sua forma**

Um pacote composer, com **núcleo desacoplado do framework host**: o mesmo código roda em webman, PHP nativo (sem framework), Laravel, ThinkPHP, Symfony, Slim, Hyperf e Yii2 (veja [Frameworks suportados](#frameworks-suportados)). Configuração, rotas, comandos de console, arquivos de idioma e scripts de front-end do host são distribuídos pelos comandos de instalação / publicação; não depende de banco de dados; o Redis só é necessário quando o upload instantâneo está ligado, ou seja, é uma dependência opcional.

![Página de exemplo](http://wx2.sinaimg.cn/mw690/69e23056gy1fho6ymepjlg20go0aknar.gif) 

# Estrutura do projeto

```text
aetherupload-webman/
├── src/                          código do plugin
│   ├── Runtime.php                 ponto de vinculação do host (fachada estática): mantém por processo apenas vínculos imutáveis e dá erro explícito quando não há vínculo
│   ├── RequestContext.php          estado mutável por requisição / por corrotina (snapshot da configuração de grupo, idiomas já carregados); sob processo persistente, requisições concorrentes não misturam grupos
│   ├── Contract/                   11 interfaces (configuração / tradução / requisição / arquivo enviado / resposta / Redis / eventos / caminhos / filesystem / contexto / adaptador)
│   ├── Kernel/                     implementações padrão do lado do núcleo: AbstractAdapter, PrefixedConfig, Filesystem, NullRedis, PhpFileTranslator, ClientRedis…
│   ├── Console/                    lógica de negócio dos três comandos (Runner) + entrada pronta para hosts sem convenção de console, compartilhada pelas cascas de comando dos sete hosts
│   ├── Adapter/                    oito adaptadores: Webman / Native / Laravel / ThinkPhp / Symfony / Slim / Hyperf / Yii
│   ├── UploadController.php        entrada do upload: preprocess (pré-processamento / decisão de upload instantâneo) e saveChunk (gravação por chunk)
│   ├── ResourceController.php      entrada de exibição e download: display / download, com suporte a entrega direta pelo nginx
│   ├── PartialResource.php         o próprio arquivo em chunks: montagem do caminho, anexação pedaço a pedaço, renomeação, checagem de tamanho e tipo
│   ├── Header.php                  arquivo de estado da retomada: guarda apenas um chunkIndex
│   ├── Resource.php                objeto do arquivo final, injetado como parâmetro no evento "upload concluído"
│   ├── RedisSavedPath.php          índice de upload instantâneo: uma key por registro, TTL independente
│   ├── SavedPathResolver.php       codificação / decodificação do caminho de armazenamento (endereçamento sem estado em três segmentos)
│   ├── ConfigMapper.php            singleton de configuração + snapshot da configuração de grupo por requisição
│   ├── MimeType.php                mapa mime ↔ extensão, com revisão pela lista de permissões antes de gravar em disco
│   ├── Util.php                    geração de nome temporário, validação de segurança de caminho, remoção de recursos
│   ├── Install.php                 instalação / desinstalação: distribui configuração e recursos para a aplicação host
│   ├── Responser.php               encapsulamento da resposta JSON
│   ├── SimpleValidateTrait.php     validação mínima de campos obrigatórios
│   ├── ExamplePageTrait.php        página de exemplo
│   └── helpers.php                 helpers de template: aetherupload_display_link / aetherupload_download_link
├── commands/                     cascas de console do webman (copiadas para app/command na instalação; a lógica fica em src/Console)
│   ├── AetherUploadListGroups.php        php webman aetherupload:groups   lista os grupos e cria os diretórios correspondentes
│   ├── AetherUploadBuildRedisHashes.php  php webman aetherupload:build    reconstrói o índice de upload instantâneo a partir do estado do disco
│   └── AetherUploadCleanUpDirectory.php  php webman aetherupload:clean N  limpa por mtime os arquivos temporários expirados
├── config/
│   ├── app.php                   configuração do plugin webman: grupos, rotas, middleware e os diversos interruptores
│   ├── aetherupload.php          a mesma configuração, independente de framework, usada como base pelos outros sete adaptadores (os dois caminhos são travados como idênticos pelo ConfigParityTest)
│   └── route.php                 quatro rotas + os respectivos pontos de montagem de middleware
├── docs/                         documentação, imagens e scripts de front-end
│   ├── aetherupload-architecture.svg  diagrama da arquitetura
│   ├── aetherupload-design.svg        diagrama da proposta de design
│   ├── aetherupload-lifecycle.svg     diagrama do ciclo de vida do upload
│   ├── HARNESS.md                     contrato dos testes unitários (leitura obrigatória antes de escrever testes)
│   ├── REPORT.md                      resumo de uma rodada completa de testes e de cobertura
│   ├── i18n/                          README multilíngue e traduções dos três diagramas (12 idiomas; a navegação das traduções está em TRANSLATING.md)
│   └── js/                            recursos de front-end (publicados em <raiz de documentos>/vendor/aetherupload/js)
│       ├── aetherupload-all.js        versão empacotada: núcleo + zepto + spark-md5
│       ├── aetherupload-core.js       lógica central: calcula hash, fatia, envia, mostra progresso e reconecta
│       └── aetherupload-pet.svg       mascote do projeto "Aether Beast", usado também como ícone da página de exemplo e favicon
├── translations/{zh,en}/         internacionalização (publicada em aetherupload/ sob o caminho translations do host)
├── views/example.blade.php        código-fonte da página de exemplo, para consulta direta na integração
├── tests/
│   ├── *.php                     casos unitários PHPUnit (host substituído por dublês, PHP 8.0–8.4, PHPUnit 9.6 e 10.5)
│   └── Integration/<fw>/         suítes ponta a ponta, uma por framework: framework de verdade instalado e cadeia real de upload (webman sobe um serviço real via phpunit.xml; os outros sete usam o respectivo ci.sh)
├── uploads/                      diretório legado, não usado pelo plugin em tempo de execução
└── composer.json
```

> Os 15 arquivos na raiz de `src/` (controllers, chunks, retomada, índice de upload instantâneo, codificação de caminho, resposta de erro…) formam o núcleo de upload independente de framework: eles não referenciam nenhum namespace do host, e configuração / tradução / requisição / resposta / Redis / eventos são todos obtidos como portas através do `Runtime`.
> A única exceção é o `Install.php` — o script de instalação disparado por `composer require` roda antes de a aplicação host subir, quando o `Runtime` ainda não foi vinculado; nesse caso ele vincula o adaptador webman como fallback e recorre ao `config/app.php` de dentro do pacote.

> Antes da instalação, tudo isso são arquivos internos do pacote; depois do `composer require`, o `Install.php` distribui configuração, comandos, scripts de front-end e arquivos de idioma para os locais correspondentes da aplicação host, conforme o mapeamento indicado na tabela acima.

# Arquitetura de design

<img src="img/aetherupload-architecture.pt.svg" alt="Diagrama da arquitetura do AetherUpload-Webman">

- **Cliente**: basta incluir um `aetherupload-all.js` (que já traz zepto e spark-md5 embutidos); ele calcula o md5, fatia, exibe a barra de progresso e reconecta sozinho quando a conexão cai.
- **Servidor**: o `UploadController` tem apenas duas entradas (pré-processamento e gravação por chunk); o `PartialResource` cuida de caminho, anexação e renomeação, e o `Header` registra só o chunkIndex; a configuração vira um snapshot por requisição via `ConfigMapper`, evitando que requisições concorrentes misturem grupos sob processo persistente.
- **Armazenamento e operação**: no disco existem sempre apenas três tipos de arquivo — `*.part` em chunks, `_header/` de retomada e `<md5>.<ext>` final; o Redis só é usado com o upload instantâneo ligado; com `x_accel_redirect` habilitado, o nginx entrega o arquivo direto e, de quebra, cobre Range e download retomável.

# Proposta de design

<img src="img/aetherupload-design.pt.svg" alt="Proposta de design do AetherUpload-Webman">

Além das três decisões do diagrama, há uma escolha independente: **o índice de upload instantâneo é uma dependência opcional + TTL independente por registro**. Sites sem Redis continuam enviando arquivos normalmente (só o upload instantâneo deixa de funcionar); nos sites com Redis, cada registro expira por conta própria via `SETEX` — sem compartilhar tempo de expiração e sem inchar indefinidamente conforme os uploads seguem acontecendo; e, depois de uma atualização, os registros antigos de hash ainda podem ser lidos como fallback. A consistência fica por conta de `aetherupload:build` (reconstrução diária) e `aetherupload:clean` (recuperação de arquivos temporários por mtime).

# Ciclo de vida do upload

<img src="img/aetherupload-lifecycle.pt.svg" alt="Ciclo de vida do upload do AetherUpload-Webman">

O caminho principal tem só quatro passos: **pré-processamento → chunks (em laço) → verificação do último chunk → gravação em disco**. O pré-processamento cria um `.part` vazio e grava `chunkIndex=0` no header; a ordem de cada chunk é sempre "valida → anexa → regrava chunkIndex"; só o último chunk valida tamanho e MIME, recalcula o md5 do arquivo inteiro, renomeia para `<md5>.<ext>` e grava o índice de upload instantâneo.

Dois desvios merecem explicação à parte:

- **Acerto do upload instantâneo**: na fase de pré-processamento, se já existe um arquivo final com o mesmo md5, o caminho dele é devolvido na hora, sem enviar um único chunk.
- **Interrupção e recuperação**: reenviar o mesmo número de sequência é ignorado de forma idempotente; um salto de sequência ou um chunk truncado apenas devolve erro, e **não limpa** o `.part` nem o header — por isso, em rede ruim, o cliente pode tentar de novo até completar o pedaço que falta. Só dois casos realmente limpam: quando a verificação do último chunk não passa (descarta tudo) e quando, depois que a página é fechada, o cron roda `aetherupload:clean` para recuperar por mtime.

# Recursos
- [x] Barra de progresso percentual  
- [x] Restrição de tipo de arquivo  
- [x] Restrição de tamanho de arquivo  
- [x] Suporte a múltiplos idiomas  
- [x] Configuração de grupos de recursos  
- [x] Evento de upload concluído   
- [x] Upload síncrono *①*  
- [x] Retomada após queda de conexão *②*  
- [x] Upload instantâneo *③*  
- [x] Middleware personalizado *④*  
- [x] Rotas personalizadas   
- [x] Modo relaxado

*①: Comparado ao upload assíncrono, o upload síncrono é um pouco mais lento quando a banda de envio é suficientemente grande, mas o síncrono consegue montar o arquivo enquanto ele é enviado, ao passo que o assíncrono, por não ter ordem definida de conclusão dos blocos, só pode montar quando todos os blocos terminam — o que faz o upload assíncrono esperar bastante perto do fim. No upload síncrono só há um chunk subindo por vez, então ele ocupa menos memória do servidor por unidade de tempo e suporta mais gente enviando ao mesmo tempo do que o modo assíncrono.*  

*②: Retomada após queda de conexão é diferente de retomada a partir do ponto. Aquela se refere a quedas de rede ou instabilidade de rede sem fio: sem fechar a página, o componente de upload tenta de novo automaticamente em intervalos regulares e, assim que a rede volta, o arquivo continua a ser enviado a partir do bloco que não subiu. A retomada após queda não funciona depois de atualizar ou fechar e reabrir a página — a parte já enviada vira arquivo inválido.*  

*③: O upload instantâneo exige Redis no servidor e suporte do navegador do lado do cliente (FileReader, File.slice()); faltando qualquer um dos dois, o recurso não funciona. Vem desligado por padrão e precisa ser habilitado no arquivo de configuração.*  

*④: Combinado com middleware personalizado, permite controlar o acesso e o download dos recursos já enviados.*


# Frameworks suportados

O núcleo (chunks, retomada, upload instantâneo, verificação, endereçamento) é desacoplado do framework host, e o mesmo pacote funciona nos hosts abaixo, cada um respaldado por testes ponta a ponta que **instalam o framework de verdade e rodam a cadeia completa de upload**:

| Host | Forma de integração | Teste ponta a ponta |
|---|---|---|
| webman | Suporte nativo (`composer require` já distribui configuração / rotas / comandos / scripts de front-end) | `tests/Integration/webman/` |
| **PHP nativo (sem framework)** | Uma linha de `Bootstrap::handle()`; as rotas são distribuídas pelo próprio pacote conforme a configuração | `tests/Integration/native/` |
| Laravel | ServiceProvider + `vendor:publish` | `tests/Integration/laravel/` |
| ThinkPHP | Registra o Service em `app/service.php` | `tests/Integration/thinkphp/` |
| Symfony | Bundle + import de arquivo de rotas | `tests/Integration/symfony/` |
| Slim | Uma linha de `Bootstrap::create()` | `tests/Integration/slim/` |
| Hyperf | `ConfigProvider` | `tests/Integration/hyperf/` |
| Yii2 | Bootstrap no `bootstrap` da aplicação | `tests/Integration/yii/` |

> O `webman` no nome do pacote é por motivo histórico (o plugin só suportava webman no início) e não atrapalha o uso nos outros hosts.

## Dois passos comuns

Independentemente do host, depois de instalar o pacote há duas ações **obrigatórias** (no webman o script de instalação já as executa; nos outros hosts é preciso rodar o comando correspondente na mão):

1. **Criar os diretórios de armazenamento**: `aetherupload:groups` —— cria o `root_dir`, o `_header` e os diretórios de cada grupo.
   **Sem isso o upload falha com certeza**: o `createGroupSubDir()` do núcleo é um `mkdir` não recursivo e devolve false direto quando o diretório pai não existe, e o erro acaba traduzido para o genérico `upload_error`, o que dificulta muito perceber que o problema é de diretório.
2. **Publicar os arquivos**: `aetherupload:publish` (no Laravel, `vendor:publish --tag=aetherupload-*`) —— coloca os arquivos de idioma e o `js` de front-end em locais acessíveis ao host.

> O separador dos nomes de comando segue a convenção de console de cada framework: webman / Laravel / ThinkPHP / Symfony / Hyperf / Slim / PHP nativo usam `:`, e o **Yii usa `/`** (`php yii aetherupload/groups`). Os exemplos de cada framework abaixo podem ser copiados e rodados direto.

## Integração por framework

**PHP nativo (sem framework)**

Qualquer aplicação que rode em PHP pode usar isto direto —— sem instalar framework, sem instalar middleware, sem registrar serviço; o script de entrada é uma linha:

```php
// public/index.php (script de entrada do FPM / Apache / nginx+php-fpm)
require __DIR__ . '/../vendor/autoload.php';

exit(\AetherUpload\Adapter\Native\Bootstrap::handle([
    'base_path' => dirname(__DIR__),
    'config'    => require __DIR__ . '/../config/aetherupload.php',   // se omitido, usa o padrão de dentro do pacote
    'redis'     => static fn () => new \Redis(),                       // necessário para o upload instantâneo; opcional
]));
```

```bash
php bin/aetherupload aetherupload:groups     # cria os diretórios
php bin/aetherupload aetherupload:publish    # publica os arquivos de idioma e o js de front-end
```

O `bin/aetherupload` também tem três linhas; basta colocar a sua cópia:

```php
#!/usr/bin/env php
<?php
require __DIR__ . '/../vendor/autoload.php';
\AetherUpload\Adapter\Native\Bootstrap::bind(require __DIR__ . '/../config/aetherupload.php', dirname(__DIR__));
exit((new \AetherUpload\Console\Application())->run());
```

> **As rotas não precisam ser escritas à mão**: o `Bootstrap::handle()` distribui a requisição atual conforme `route_preprocess` / `route_uploading` / `route_display` / `route_download` da configuração; sem correspondência devolve 404, método errado devolve 405 com o `Allow`.
> **Middleware**: neste host, os `middleware_*` da configuração são **objetos chamáveis sem parâmetros**, e devolver um objeto de resposta já curto-circuita —— sem permissão, basta `return Runtime::response()->text('forbidden', 403);`; qualquer outro valor devolvido é ignorado e o fluxo segue (o controle de permissão da nota de rodapé ④ se liga exatamente assim).
> **Servidor embutido**: basta `php -S 127.0.0.1:8080 -t public public/index.php`. Para o servidor embutido servir sozinho os arquivos estáticos de `public/` (é por aí que passa o js de front-end publicado), acrescente antes do `handle()` uma linha: `if (is_file(__DIR__ . parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH))) { return false; }`.
> **Sem funções embutidas como rede de segurança**: a requisição lê `$_GET`/`$_POST`/`$_FILES` (o PHP não filtra nada além, e as guardas de tipo do núcleo continuam valendo), e a resposta sai de uma vez com `header()` + `echo` em `NativeResponse::send()`, sem camada intermediária de framework.

**Laravel**

```php
// bootstrap/providers.php (Laravel 11+) ou o array providers de config/app.php
AetherUpload\Adapter\Laravel\AetherUploadServiceProvider::class,
```
```bash
php artisan vendor:publish --tag=aetherupload-config         # publica config/aetherupload.php
php artisan vendor:publish --tag=aetherupload-translations   # publica os arquivos de idioma
php artisan vendor:publish --tag=aetherupload-assets         # publica o js de front-end
php artisan aetherupload:groups
```
> O `composer.json` deste pacote **não inclui** `extra.laravel.providers` (as dependências de cada framework são mutuamente exclusivas, o que impede fixar a descoberta automática), portanto o provider precisa ser registrado na mão.
> A mesclagem de configuração é **rasa**: assim que a aplicação publica `config/aetherupload.php`, o `groups` de dentro dele **substitui por inteiro** o valor padrão do plugin — ao adicionar grupos, copie também os grupos padrão.

**ThinkPHP**

```php
// app/service.php
return [ \AetherUpload\Adapter\ThinkPhp\AetherUploadService::class ];
```
```bash
php think aetherupload:groups
php think aetherupload:publish
```
> Vale a mesma observação: `groups` é substituído por inteiro. Além disso, `config/route.php` e `config/lang.php` são **arquivos obrigatórios**; se faltarem, o framework recebe null em `array_merge`/`array_change_key_case` e lança TypeError.

**Symfony**

```php
// config/bundles.php
AetherUpload\Adapter\Symfony\AetherUploadBundle::class => ['all' => true],
```
```yaml
# config/routes.yaml (as rotas do bundle do Symfony precisam ser importadas pelo lado da aplicação; não há carregamento automático)
aetherupload:
    resource: '@AetherUploadBundle/Resources/config/routes.php'
    type: php
```
> A árvore `Configuration` declara todas as chaves de configuração; uma chave não declarada faz o container falhar na subida (não é ignorada em silêncio).

**Slim**

```php
// public/index.php
$app = \AetherUpload\Adapter\Slim\Bootstrap::create([
    'config'    => require __DIR__ . '/../config/aetherupload.php',   // se omitido, usa o padrão de dentro do pacote
    'base_path' => dirname(__DIR__),
    'redis'     => static fn () => new Predis\Client(['database' => 0]),   // necessário para o upload instantâneo
]);
$app->run();
```
> A resposta PSR-7 do Slim é imutável, e o produto do núcleo só vira PSR-7 de verdade na camada `Bootstrap::handler()` (**o único ponto de conversão**; se for colocado em middleware, não passa).
> O Slim não tem convenção de console; este pacote fornece as classes de entrada prontas para os quatro comandos, e o script de entrada precisa ser criado por você (quatro linhas):
> ```php
> // bin/aetherupload
> require __DIR__ . '/../vendor/autoload.php';
> \AetherUpload\Adapter\Slim\Bootstrap::bind(require __DIR__ . '/../config/aetherupload.php', dirname(__DIR__));
> exit((new \AetherUpload\Adapter\Slim\Console\Application())->run());
> ```
> ```bash
> php bin/aetherupload aetherupload:groups     # também há publish / build / clean
> ```
> Requer `symfony/console` (require-dev / suggest deste pacote).

**Hyperf**

```php
// config/config.php —— este pacote não declara extra.hyperf.config (isso interferiria no mecanismo
// de descoberta automática dos outros frameworks; fica para depois de uma validação isolada), então
// o ConfigProvider é expandido explicitamente aqui (rotas, comandos e chaves de publish vêm todos dele)
return [
    // …
] + (new \AetherUpload\Adapter\Hyperf\ConfigProvider())();
```
```bash
php bin/hyperf.php aetherupload:groups
php bin/hyperf.php aetherupload:publish
```
> Requer `ext-swoole`. Mais dois pontos de atenção em desenvolvimento: `xdebug.mode=profile` faz a inicialização a frio do Hyperf **sair em silêncio** (`exit 255` dentro de `ClassLoader::init()`, sem nenhum erro do PHP), então defina `XDEBUG_MODE=off` no CI e na máquina local; e o `Coroutine\run()` do swoole não pode rodar no mesmo processo do PHPUnit, então testes de corrotina precisam levar a sonda para um processo separado.

**Yii2**

```php
// configuração da aplicação
return [
    'bootstrap' => ['aetherupload' => \AetherUpload\Adapter\Yii\Bootstrap::class],
    'params'    => ['aetherupload' => require __DIR__ . '/aetherupload.php'],   // pode partir do exemplo gerado pelo publish
    'components' => [
        // pré-requisito: precisa ficar sob components. Uma chave de mesmo nome no nível
        // superior é ignorada pelo Yii, e aí o enableStrictParsing não faz efeito e as
        // restrições de verb das rotas são contornadas (um GET chega numa action que só declara POST)
        'urlManager' => ['enableStrictParsing' => true],
        // necessário para o upload instantâneo; a aplicação de console também precisa, senão aetherupload/build dá Unknown component ID: redis
        'redis' => [ /* … */ ],
    ],
];
```
```bash
php yii aetherupload/groups     # atenção: o separador de console do Yii é "/"; escrever aetherupload:groups dá Unknown command
php yii aetherupload/publish
```
> O destino da publicação é `vendor/aetherupload/js` sob a **raiz de documentos** (a raiz de documentos do webman é `public/` e a do Yii é `web/`): a página de exemplo referencia o caminho absoluto `/vendor/aetherupload/js/…`, cujo significado é "raiz de documentos", por isso o `YiiPaths::assetPath()` prioriza o alias `@webroot` do host e recorre a `<app>/web` quando não o encontra.

**webman**

```bash
# execute na raiz do projeto webman
composer require erikwang2013/aetherupload-webman
```
> O webman é o único host **zero-configuração**: no `composer require` o `Install.php` distribui automaticamente configuração, rotas, comandos, arquivos de idioma e scripts de front-end, e já cria os diretórios de armazenamento. Depois de instalar, acessar `http://domínio/aetherupload` já mostra a página de exemplo.
>
> Dica: para alterar as opções de configuração relacionadas, edite `config/plugin/erikwang2013/aetherupload-webman/app.php`.

> Os outros sete hosts precisam registrar o adaptador primeiro e depois rodar os "dois passos comuns". **O webman também oferece os mesmos comandos** (`php webman aetherupload:groups` / `aetherupload:publish`); a diferença é que lá eles já rodaram sozinhos na instalação.

# Uso  
**Envio de arquivo**  

Consulte o arquivo de exemplo e os comentários, e inclua os arquivos e o código correspondentes na página em que for enviar arquivos grandes.

**Configuração de grupos**  

Adicione um grupo em `groups` no arquivo de configuração deste plugin e rode `php webman aetherupload:groups` para criar o diretório correspondente.  
```php
'video' => [
    'group_dir'                    => 'video',
    'resource_maxsize'             => 0,
    'resource_extensions'          => [],
    'event_before_upload_complete' => false, 
    'event_upload_complete'        => false,
],
```
No front-end, informe o grupo de upload chamando o método `setGroup('nome do grupo')`. Atenção: o nome do grupo precisa já existir e **não pode conter underscore** (ele participa da codificação do caminho de armazenamento; com underscore, os recursos desse grupo deixam de ser localizáveis e a falha acontece já na configuração e na fase de upload).

**Adicionar o upload instantâneo (requer Redis e suporte do navegador)**  

Consulte a parte de Redis da documentação do Webman e instale as dependências necessárias.  
Instale o Redis e suba o serviço.  
Instale o pacote predis: `composer require predis/predis`.  
Em `config/redis.php`, defina `client` como `predis`.  
No arquivo de configuração deste plugin, defina `instant_completion` como `true`.

*Dica: existe no Redis uma lista de upload instantâneo correspondente aos arquivos reais, e qualquer inclusão ou remoção de arquivo real precisa ser sincronizada nessa lista, senão surgem dados sujos.  
O pacote já cobre a parte de inclusão; quando for preciso remover um arquivo, é o usuário que deve chamar o método correspondente para apagar o arquivo e o registro na lista de upload instantâneo.* 
```php
\AetherUpload\Util::deleteResource($savedPath); //apaga o arquivo de recurso correspondente
\AetherUpload\Util::deleteRedisSavedPath($savedPath); //apaga o registro de upload instantâneo correspondente no Redis
``` 
*As duas operações são idempotentes: também devolvem true quando o arquivo ou o registro de upload instantâneo já não existe.* 

**Middleware personalizado**  

Consulte a parte de middleware de rota da documentação do Webman, crie o seu middleware e coloque o nome dele na parte correspondente do arquivo de configuração.  
```php
'middleware_download' => [app\middleware\MiddlewareA::class,app\middleware\MiddlewareB::class],
```  
Você pode usar esse recurso para controlar permissões de upload, acesso e download de arquivos.

**Rotas personalizadas**  

Edite opções como `'route_uploading' => '/aetherupload/uploading'` no arquivo de configuração deste plugin e chame métodos como `setUploadingRoute('/aetherupload/uploading')` no front-end.  
Depois de editar as rotas de acesso e download de arquivos, dá para acessá-las direto, sem chamar método de front-end.
 
**Evento de upload concluído**  

Divide-se em evento antes da conclusão e evento depois da conclusão; consulte a parte de eventos da seção de componentes comuns da documentação do Webman.  
Em `config/event.php`, configure as classes de tratamento correspondentes para `aetherupload.before_upload_complete` e `aetherupload.upload_complete`.  
No arquivo de configuração deste plugin, defina como `true` as opções correspondentes dentro de `groups`. 
```php
'event_before_upload_complete' => true, 
'event_upload_complete'        => true,
```
Você pode usar esse recurso para fazer processamentos extras antes e depois da conclusão do upload.

**Modo relaxado**  

No arquivo de configuração deste plugin, edite `'lax_mode' => true,` e chame o método `setLaxMode(true)` no front-end.  
Pular o cálculo do hash antes do upload encurta o tempo total. Com essa opção ligada, não é possível usar o upload instantâneo nem a verificação de integridade.

**Múltiplos idiomas**  

O front-end detecta o idioma do navegador e se ajusta sozinho; hoje há suporte a chinês e inglês.
  
**Comandos de console práticos**  

`php webman aetherupload:groups` lista todos os grupos e cria automaticamente os diretórios correspondentes  
`php webman aetherupload:build` reconstrói no Redis a lista de upload instantâneo dos arquivos  
`php webman aetherupload:clean 2` remove arquivos temporários inválidos com mais de 2 dias  

# Sugestões de otimização
* **(Recomendado) Configurar a limpeza automática diária dos arquivos temporários inválidos**  
Como o fluxo de upload pode terminar de forma inesperada — fechar a página ou o navegador no meio da transferência, por exemplo —, a parte já produzida do arquivo vira arquivo inválido e ocupa bastante espaço em disco; dá para usar as tarefas agendadas do crontab para limpá-los periodicamente.  
No Linux, rode o comando `crontab -e` e garanta que o arquivo contenha esta linha:  
```php
0 0 * * * php /caminho/absoluto/da/raiz/do/projeto/webman aetherupload:clean 1> /dev/null 2>&1  
```  

* **Configurar a reconstrução diária e automática da lista de upload instantâneo no Redis**  
Tratamentos inadequados e algumas situações extremas podem gerar dados sujos na lista de upload instantâneo, prejudicando a precisão do recurso; reconstruir a lista elimina os dados sujos e restaura a sincronia com os arquivos reais.  
No Linux, rode o comando `crontab -e` e garanta que o arquivo contenha esta linha:  
```php
0 0 * * * php /caminho/absoluto/da/raiz/do/projeto/webman aetherupload:build 1> /dev/null 2>&1  
```  

* **(Opcional) Habilitar o redirecionamento interno do nginx, com suporte a retomada de download de arquivos grandes e ao arrastar de vídeo**  
A resposta de arquivo do webman não implementa HTTP Range: downloads grandes vão inteiros, o vídeo não deixa arrastar a barra de progresso e um worker fica ocupado durante toda a transferência. Se a implantação for sob nginx, dá para ligar a opção `x_accel_redirect` deste plugin, e o arquivo passa a ser enviado direto pelo nginx: o worker é liberado na hora e o Range (retomada de download, arrastar o vídeo) passa a funcionar.  
No arquivo de configuração deste plugin, edite `'x_accel_redirect' => true,` e adicione no nginx um location para o prefixo interno, com o `alias` apontando para o caminho absoluto da raiz de upload do projeto (atenção à barra no final):  
```nginx
location /internal-aetherupload/ {
    internal;
    alias /caminho/absoluto/da/raiz/do/projeto/storage/app/aetherupload/;
}
```
Esse location precisa manter o `internal`, para impedir que alguém acesse os arquivos direto por fora da aplicação; ao mudar o `root_dir`, o `alias` precisa ser ajustado junto.  

* **Aumentar a velocidade de leitura e escrita dos arquivos temporários de chunk (só vale para o PHP)**  
Use o sistema de arquivos tmpfs do Linux para manter os arquivos temporários dos chunks na memória e ganhar velocidade de leitura e escrita: troca espaço por tempo, melhora a eficiência de I/O e **ocupa memória extra** (cerca de um chunk).  
Defina o valor de `upload_tmp_dir` no php.ini como `"/dev/shm"` e reinicie o serviço.  

* **Aumentar a velocidade de leitura e escrita dos arquivos temporários de chunk (vale para o diretório temporário do sistema)**  
Use o sistema de arquivos tmpfs do Linux para manter os arquivos temporários dos chunks na memória e ganhar velocidade de leitura e escrita: troca espaço por tempo, melhora a eficiência de I/O e **ocupa memória extra** (cerca de um chunk).  
Rode os comandos abaixo:    
`mkdir /dev/shm/tmp`  
`chmod 1777 /dev/shm/tmp`  
`mount --bind /dev/shm/tmp /tmp`  

# Compatibilidade
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
  <td>Instantâneo</td>
  <td>10+</td>
  <td>12+</td>
  <td>3.6+</td>
  <td>6+</td>
  <td>6+</td>
  </tr>
</table>

# Segurança
Antes do upload, o AetherUpload filtra a extensão do arquivo com uma lista de permissões mais uma lista de bloqueios, e depois do upload confere o Mime-Type do arquivo. A lista de permissões limita diretamente as extensões que podem ser salvas, e a lista de bloqueios barra por padrão as extensões executáveis mais comuns, impedindo o envio de arquivos maliciosos; por segurança, a lista de permissões não deve ficar vazia.  

Apesar de todo esse trabalho, upload de arquivo malicioso é algo que sempre escapa; recomenda-se configurar corretamente as permissões do diretório de upload, garantindo que os programas envolvidos não tenham permissão de execução sobre os arquivos de recurso.
