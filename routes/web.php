<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\EnvLogViewerController;

Route::get('/', function () {
    return view('welcome');
});

$consolePath = ltrim(env('CONSOLE_PATH', '/arsip-dalam'), '/');

Route::prefix($consolePath)->middleware(['env.basic'])->group(function () {
    Route::get('/', [EnvLogViewerController::class, 'index']);
    Route::get('/download', [EnvLogViewerController::class, 'download']);
});