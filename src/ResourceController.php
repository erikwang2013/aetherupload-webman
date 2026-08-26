<?php

namespace AetherUpload;

use \Webman\Http\Request;

class ResourceController
{

    const INLINE_BLOCKED_EXTENSIONS = ['svg', 'svgz', 'html', 'htm', 'xml', 'xhtml', 'xht', 'xsl', 'js', 'mjs'];

    public function display(Request $request, $uri)
    {

        try {

            $params = SavedPathResolver::decode($uri);

            ConfigMapper::applyGroupConfig($params->group);

            $resource = new Resource($params->group, ConfigMapper::get('group_dir'), $params->groupSubDir, $params->resourceName);

            if ( $resource->exists() === false ) {
                throw new \Exception;
            }

        } catch ( \Exception $e ) {

            return response('display fail', 404);
        }

        $response = response()->file($resource->realPath);

        if ( in_array(strtolower(pathinfo($resource->name, PATHINFO_EXTENSION)), self::INLINE_BLOCKED_EXTENSIONS, true) ) {
            $response = response()->download($resource->realPath, $resource->name);
        }

        return $response->withHeader('X-Content-Type-Options', 'nosniff');
    }

    public function download(Request $request, $uri, $newName = null)
    {

        try {

            $params = SavedPathResolver::decode($uri);

            ConfigMapper::applyGroupConfig($params->group);

            $resource = new Resource($params->group, ConfigMapper::get('group_dir'), $params->groupSubDir, $params->resourceName);

            if ( $resource->exists() === false ) {
                throw new \Exception;
            }

            // sanitize the client-controlled filename to prevent CRLF header injection
            $newName = str_replace(["\r", "\n", '/', '\\'], '_', (string)$newName);
            $newResource = Util::getFileName($newName, pathinfo($resource->name, PATHINFO_EXTENSION));

        } catch ( \Exception $e ) {

            return response('download fail', 404);
        }

        return response()->download($resource->realPath, $newResource)->withHeader('X-Content-Type-Options', 'nosniff');
    }


}
