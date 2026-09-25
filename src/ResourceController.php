<?php

namespace AetherUpload;

class ResourceController
{

    const INLINE_BLOCKED_EXTENSIONS = ['svg', 'svgz', 'html', 'htm', 'xml', 'xhtml', 'xht', 'xsl', 'js', 'mjs'];

    // 开启x_accel_redirect时使用的nginx内部前缀，需与配置文件中给出的location一致
    const ACCEL_PREFIX = '/internal-aetherupload/';

    /**
     * 展示资源。
     *
     * 参数只保留路由变量：请求一律经 Runtime::request() 取，
     * 各框架的路由适配器自己负责把宿主请求/参数映射到这里。
     */
    public function display($uri)
    {

        try {

            $params = SavedPathResolver::decode($uri);

            ConfigMapper::applyGroupConfig($params->group);

            $resource = new Resource($params->group, ConfigMapper::get('group_dir'), $params->groupSubDir, $params->resourceName);

            if ( $resource->exists() === false ) {
                throw new \Exception;
            }

        } catch ( \Exception $e ) {

            return Runtime::response()->text('display fail', 404);
        }

        $inlineBlocked = in_array(strtolower(pathinfo($resource->name, PATHINFO_EXTENSION)), self::INLINE_BLOCKED_EXTENSIONS, true);

        // 开启后交由nginx直接发送文件；重定向头为相对root_dir的路径，与配置中alias指向的目录对齐；只由服务端已校验的数据拼出，不含客户端原始输入
        if ( ConfigMapper::get('x_accel_redirect') === true ) {
            $response = Runtime::response()->text('', 200)->withHeader('X-Accel-Redirect', self::ACCEL_PREFIX . $resource->groupDir . '/' . $resource->groupSubDir . '/' . $resource->name);

            if ( $inlineBlocked ) {
                $response = $response->withHeader('Content-Disposition', 'attachment; filename="' . $resource->name . '"');
            }

            return $response->withHeader('X-Content-Type-Options', 'nosniff');
        }

        $response = Runtime::response()->file($resource->realPath);

        if ( $inlineBlocked ) {
            $response = Runtime::response()->download($resource->realPath, $resource->name);
        }

        return $response->withHeader('X-Content-Type-Options', 'nosniff');
    }

    public function download($uri, $newName = null)
    {

        try {

            $params = SavedPathResolver::decode($uri);

            ConfigMapper::applyGroupConfig($params->group);

            $resource = new Resource($params->group, ConfigMapper::get('group_dir'), $params->groupSubDir, $params->resourceName);

            if ( $resource->exists() === false ) {
                throw new \Exception;
            }

            // sanitize the client-controlled filename to prevent header injection and Content-Disposition breakage (quotes, slashes, C0 control characters and DEL)
            $newName = preg_replace('/[\x00-\x1f\x7f"\\\\\/]/', '_', (string)$newName);
            $newResource = Util::getFileName($newName, pathinfo($resource->name, PATHINFO_EXTENSION));

        } catch ( \Exception $e ) {

            return Runtime::response()->text('download fail', 404);
        }

        // 开启后交由nginx直接发送文件，Content-Disposition需由程序设置；路径为相对root_dir的路径，只由服务端已校验的数据拼出
        if ( ConfigMapper::get('x_accel_redirect') === true ) {
            return Runtime::response()->text('', 200)
                ->withHeader('X-Accel-Redirect', self::ACCEL_PREFIX . $resource->groupDir . '/' . $resource->groupSubDir . '/' . $resource->name)
                ->withHeader('Content-Disposition', 'attachment; filename="' . $newResource . '"')
                ->withHeader('X-Content-Type-Options', 'nosniff');
        }

        return Runtime::response()->download($resource->realPath, $newResource)->withHeader('X-Content-Type-Options', 'nosniff');
    }


}
