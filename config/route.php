<?php

// 绑定宿主适配器：本文件是 webman 里唯一保证「每个 HTTP worker 启动时执行一次」的入口
// （被 support\bootstrap.php 里的 Route::load() require），下面的 ConfigMapper::get() 依赖它。
// Install 会把它复制到 config/plugin/erikwang2013/aetherupload-webman/route.php。
\AetherUpload\Runtime::bind(new \AetherUpload\Adapter\Webman\WebmanAdapter());

use Webman\Route;


if ( config('app.debug') ) {

    Route::get('/aetherupload', [\AetherUpload\UploadController::class, 'getExamplePage']);

    Route::post('/aetherupload', [\AetherUpload\UploadController::class, 'postExamplePage']);

    Route::get('/aetherupload/example_source', [\AetherUpload\UploadController::class, 'examplePageSource']);

}


Route::post(\AetherUpload\ConfigMapper::get('route_preprocess'), [\AetherUpload\UploadController::class, 'preprocess'])->middleware(\AetherUpload\ConfigMapper::get('middleware_preprocess'));

Route::post(\AetherUpload\ConfigMapper::get('route_uploading'), [\AetherUpload\UploadController::class, 'saveChunk'])->middleware(\AetherUpload\ConfigMapper::get('middleware_uploading'));

Route::get(\AetherUpload\ConfigMapper::get('route_display').'/{uri}', [\AetherUpload\ResourceController::class, 'display'])->middleware(\AetherUpload\ConfigMapper::get('middleware_display'));

Route::get(\AetherUpload\ConfigMapper::get('route_download').'/{uri}/{newName}',  [\AetherUpload\ResourceController::class, 'download'])->middleware(\AetherUpload\ConfigMapper::get('middleware_download'));

//Route::add(['OPTIONS'],\AetherUpload\ConfigMapper::get('route_uploading'), [\AetherUpload\UploadController::class, 'options']);
//Route::options(\AetherUpload\ConfigMapper::get('route_preprocess'),  [\AetherUpload\UploadController::class, 'options']);



