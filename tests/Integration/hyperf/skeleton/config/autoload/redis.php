<?php

declare(strict_types=1);

// 由 App.php 按环境变量改写；redis 不可用时 instant_completion 保持 false，本文件不会被使用
// db 3 是 hyperf 这一家在六家并行跑时的分库（webman 7 · laravel 4 · thinkphp 5 · hyperf 3 · slim 6 · symfony 8 · yii 9）
return [
    'default' => [
        'host' => '127.0.0.1',
        'auth' => null,
        'port' => 6379,
        'db' => 3,
        'pool' => [
            'min_connections' => 1,
            'max_connections' => 10,
            'connect_timeout' => 5.0,
            'wait_timeout' => 3.0,
            'heartbeat' => -1,
            'max_idle_time' => 60.0,
        ],
    ],
];
