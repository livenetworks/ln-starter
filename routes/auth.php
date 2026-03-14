<?php

use Illuminate\Support\Facades\Route;
use LiveNetworks\LnStarter\Http\Controllers\AuthController;

/*
|--------------------------------------------------------------------------
| LN-Starter Auth Routes (Passwordless / Magic Link)
|--------------------------------------------------------------------------
|
| These routes are loaded by the package when config('ln-starter.auth.enabled')
| is true. They run inside the 'web' middleware group.
|
*/

Route::get('/login', function () {
    return view('ln-starter::auth.login');
})->name('login');

Route::post('/auth/magic-link', [AuthController::class, 'magicLink'])
    ->name('login.magic-link');

Route::get('/magic/wait', [AuthController::class, 'magicWait'])
    ->name('magic.wait');

Route::get('/magic/status', [AuthController::class, 'magicStatus'])
    ->name('magic.status');

Route::get('/magic/verify/{token}', [AuthController::class, 'magicVerify'])
    ->name('magic.verify');

Route::middleware(['auth:sanctum', 'disable-csrf'])->group(function () {
    Route::post('/logout', [AuthController::class, 'logout'])
        ->name('logout');
});
