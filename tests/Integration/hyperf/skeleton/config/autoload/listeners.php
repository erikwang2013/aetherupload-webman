<?php

declare(strict_types=1);

// 事件探针：抛异常的排在前面，用来验证「监听器异常不冒泡、也不掐掉后面的监听器」。
// 注册顺序经 SplPriorityQueue 后可能调整，因此两条断言都不依赖先后。
return [
    App\Probe\ThrowingListener::class,
    App\Probe\RecordingListener::class,
];
