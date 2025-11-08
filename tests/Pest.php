<?php

use Hibla\EventLoop\Loop;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
*/

uses()->beforeEach(function () {
})->in('Unit', 'Feature');

uses()->afterEach(function () {
    Loop::stop();
    Loop::reset();
})->in('Unit', 'Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
*/

expect()->extend('toBeResource', function () {
    return $this->toBeResource();
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
*/

function createTempFile(string $content = ''): string
{
    $file = tempnam(sys_get_temp_dir(), 'stream_test_');
    if ($content) {
        file_put_contents($file, $content);
    }
    return $file;
}

function cleanupTempFile(string $file): void
{
    if (file_exists($file)) {
        @unlink($file);
    }
}