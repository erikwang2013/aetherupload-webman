<?php

/**
 * Test bootstrap: defines global webman helper stubs backed by TestState.
 * Called from phpunit.xml.dist (bootstrap attribute).
 */

require __DIR__ . '/../vendor/autoload.php';

use AetherUpload\Tests\Support\ResponseStub;
use AetherUpload\Tests\Support\TestState;

TestState::reset();

if ( ! function_exists('config') ) {
    function config($key = null, $default = null)
    {
        return $key === null ? TestState::$config : TestState::get($key, $default);
    }
}

if ( ! function_exists('trans') ) {
    function trans($key, ...$args)
    {
        return $key;
    }
}

if ( ! function_exists('locale') ) {
    function locale($locale = null)
    {
        if ( $locale !== null ) {
            TestState::$locale = (string)$locale;
        }
        return TestState::$locale;
    }
}

if ( ! function_exists('base_path') ) {
    function base_path()
    {
        return TestState::$basePath;
    }
}

if ( ! function_exists('request') ) {
    function request()
    {
        return TestState::$request;
    }
}

if ( ! function_exists('response') ) {
    function response($content = '', $status = 200, $headers = [])
    {
        return new ResponseStub((string)$content, $status, $headers);
    }
}

if ( ! function_exists('json') ) {
    function json($data, $status = 200, $headers = [])
    {
        return new ResponseStub(json_encode($data), $status, $headers);
    }
}

if ( ! function_exists('copy_dir') ) {
    function copy_dir($source, $dest)
    {
        if ( ! is_dir($dest) ) {
            @mkdir($dest, 0777, true);
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ( $iterator as $item ) {
            $target = $dest . DIRECTORY_SEPARATOR . $iterator->getSubPathName();
            if ( $item->isDir() ) {
                @mkdir($target, 0777, true);
            } else {
                copy($item->getPathname(), $target);
            }
        }
        return true;
    }
}

if ( ! function_exists('remove_dir') ) {
    function remove_dir($dir)
    {
        if ( ! is_dir($dir) ) {
            return true;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ( $iterator as $item ) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
        return true;
    }
}
