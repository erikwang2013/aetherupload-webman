<?php

/**
 * 框架无关的插件配置（Laravel / ThinkPHP / Hyperf / Yii / Slim / Symfony 适配器读取它）。
 *
 * 键名是内核的「逻辑键」——与 config/app.php（webman 版）完全一致，
 * 区别只在于没有 webman 的 plugin.erikwang2013.aetherupload-webman.app. 前缀。
 * 两个文件经由 tests/ConfigParityTest.php 强制保持键集合一致，改一边必须改另一边。
 *
 * 各框架的落点：
 *   Laravel  config/aetherupload.php（mergeConfigFrom，宿主可 vendor:publish 覆盖）
 *   Symfony  Configuration 树 → 参数 aetherupload.*
 *   Hyperf   config/autoload/aetherupload.php
 *   ThinkPHP aetherupload.（Config facade）
 *   Yii      params['aetherupload']
 *   Slim     构造适配器时显式传入
 */

return [

    'enable' => true,

    // 秒传：需要 Redis 与浏览器支持 FileReader/File.slice()，缺一不可。默认关闭。
    'instant_completion' => false,

    // 秒传记录在 Redis 中的存活秒数（默认 7 天）
    'resource_redis_expire' => 604800,

    // 上传根目录名（相对项目根）
    'root_dir' => 'storage/app/aetherupload',

    // 分块大小（字节）。建议 1MB～4MB，且需小于 web 服务器与 php.ini 的上传限值
    'chunk_size' => 1000000,

    // 子目录生成规则：year / month / date / const
    'resource_subdir_rule' => 'month',

    // 后缀名黑名单：命中的一律拒绝
    'forbidden_extensions' => ['php', 'part', 'html', 'shtml', 'htm', 'shtm', 'xhtml', 'xml', 'js', 'jsp', 'asp', 'java', 'py', 'sh', 'bat', 'exe', 'dll', 'cgi', 'htaccess', 'reg', 'aspx', 'vbs'],

    // 额外 MIME 映射（MimeType.php 里没有的类型在此补充，格式 'jpg' => 'image/jpeg'）
    'extra_mime_types' => [],

    // 路由中间件（数组；各框架的挂载方式见对应适配器）
    'middleware_preprocess' => [],
    'middleware_uploading'  => [],
    'middleware_display'    => [],
    'middleware_download'   => [],

    // 四条路由路径（改这里需要前端同步调用 setXxxRoute()）
    'route_preprocess' => '/aetherupload/preprocess',
    'route_uploading'  => '/aetherupload/uploading',
    'route_display'    => '/aetherupload/display',
    'route_download'   => '/aetherupload/download',

    // 宽松模式：上传前跳过计算 hash，缩短总耗时；开启后无法秒传与完整性校验
    'lax_mode' => false,

    // 交由 nginx 直发文件（X-Accel-Redirect），补齐 Range 与大文件断点下载
    'x_accel_redirect' => false,

    // 资源分组
    'groups' => [

        'file' => [
            'group_dir'                    => 'file',
            'resource_maxsize'             => 104857600,
            'resource_extensions'          => ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg', 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'zip', 'rar', '7z', 'mp4', 'mp3', 'wav'],
            'event_before_upload_complete' => false,
            'event_upload_complete'        => false,
        ],

    ],
];
