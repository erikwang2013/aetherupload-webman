<?php

namespace AetherUpload;

class Responser
{

    public static function returnResult($result)
    {
        return Runtime::response()->json($result);
    }

    public static function reportError($result, $message)
    {
        $result['error'] = $message;

        return Runtime::response()->json($result);
    }
}