<?php

use Illuminate\Support\Facades\Route;
use LiveNetworks\LnStarter\Http\Controllers\AuthController;

/*
|--------------------------------------------------------------------------
| LN-Starter Auth v2 Routes
|--------------------------------------------------------------------------
|
| Static code/confirmation routes must remain before the wildcard link route.
| All state-changing routes run inside the package's web middleware group and
| therefore retain normal CSRF protection.
|
*/

Route::get('/login', [AuthController::class, 'login'])->name('login');

Route::post('/auth/magic-link', [AuthController::class, 'magicLink'])
    ->name('login.magic-link');

Route::get('/auth/magic/code', [AuthController::class, 'codeForm'])
    ->name('auth.magic.code.form');

Route::post('/auth/magic/code', [AuthController::class, 'consumeCode'])
    ->block(10, 10)
    ->name('auth.magic.code');

Route::get('/auth/magic/confirm/{context}', [AuthController::class, 'confirmLink'])
    ->where('context', '[A-Za-z0-9_-]{24}')
    ->name('auth.magic.link.confirm');

Route::post('/auth/magic/confirm/{context}', [AuthController::class, 'consumeLink'])
    ->where('context', '[A-Za-z0-9_-]{24}')
    ->block(10, 10)
    ->name('auth.magic.link.consume');

Route::get('/auth/magic/{token}', [AuthController::class, 'openLink'])
    ->where('token', '[A-Za-z0-9_-]{43,128}')
    ->name('auth.magic.link.open');

// One-release tombstones for published v1 polling views. These endpoints are
// read-only and never issue credentials. POST remains CSRF-protected.
Route::get('/magic/wait', [AuthController::class, 'legacyWait'])
    ->name('magic.wait');

Route::match(['GET', 'POST'], '/magic/status', [AuthController::class, 'legacyStatus'])
    ->name('magic.status');

Route::middleware('auth:web')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout'])
        ->name('logout');
});
