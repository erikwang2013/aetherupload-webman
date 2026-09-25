#!/usr/bin/env php
<?php

declare(strict_types=1);

use Hyperf\Contract\ApplicationInterface;
use Hyperf\Di\ClassLoader;
use Hyperf\Engine\DefaultOption;
use Psr\Container\ContainerInterface;

ini_set('display_errors', 'on');
ini_set('display_startup_errors', 'on');
error_reporting(E_ALL);

! defined('BASE_PATH') && define('BASE_PATH', dirname(__DIR__, 1));

require BASE_PATH . '/vendor/autoload.php';

! defined('SWOOLE_HOOK_FLAGS') && define('SWOOLE_HOOK_FLAGS', DefaultOption::hookFlags());

(function () {
    ClassLoader::init();

    /** @var ContainerInterface $container */
    $container = require BASE_PATH . '/config/container.php';

    $application = $container->get(ApplicationInterface::class);
    $application->run();
})();
