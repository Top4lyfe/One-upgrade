<?php

use Illuminate\Support\Facades\Route;

// Home page: shows your index.html
Route::get('/', function () {
    return response()->file(resource_path('site/index.html'));
});

// Download handler: serves the fixed file named in config/minne.php,
// exactly like the old download.php read it from settings.php
$download = function () {
    $name = basename((string) config('minne.download_filename'));
    $path = resource_path('site/files/' . $name);

    abort_unless($name !== '' && is_file($path), 404);

    return response()->download($path);
};

Route::get('/download', $download);
Route::get('/download.php', $download);
