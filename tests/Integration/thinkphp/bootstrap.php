<?php

/**
 * ThinkPHP 端到端测试的 bootstrap：装真骨架、装本插件、建上传目录，然后由测试用例在进程内发请求。
 *
 * 用法（仓库根目录）：vendor/bin/phpunit -c tests/Integration/thinkphp/phpunit.xml
 * 排错时用 AETHERA_E2E_APP=/some/dir 换一个骨架目录（已有目录会复用，不会重装）。
 */

require __DIR__ . '/../../../vendor/autoload.php';
// 目录名是小写 thinkphp，与命名空间大小写不一致，PSR-4 自动加载找不到，这里显式引入
require_once __DIR__ . '/App.php';

use AetherUpload\Tests\Integration\ThinkPhp\App;

// 装骨架（内部会 require 骨架的自动加载器，think\* 从这之后才可用）
App::ensure();

// E2ERequest 继承 think\Request，必须在骨架的自动加载器就位之后再引入
require_once __DIR__ . '/E2ERequest.php';
