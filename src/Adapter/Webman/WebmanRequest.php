<?php

namespace AetherUpload\Adapter\Webman;

use AetherUpload\Contract\RequestInterface;
use AetherUpload\Contract\UploadedFileInterface;
use AetherUpload\Kernel\UploadedFile;

class WebmanRequest implements RequestInterface
{
    /**
     * @param mixed $default
     * @return mixed
     */
    public function input(string $key, $default = null)
    {
        $request = request();

        return $request === null ? $default : $request->input($key, $default);
    }

    public function file(string $key): ?UploadedFileInterface
    {
        $request = request();

        if ( $request === null ) {
            return null;
        }

        $file = $request->file($key);

        return $file === null ? null : new UploadedFile($file);
    }

    public function all(): array
    {
        $request = request();

        return $request === null ? [] : (array)$request->all();
    }
}
