<?php

use App\Http\Controllers\Auth\InitialPasswordController;
use App\Http\Controllers\Auth\LoginLinkController;
use App\Http\Controllers\Auth\TwoFactorEmailCodeController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::middleware('guest')->group(function () {
    Route::post('/login-link', [LoginLinkController::class, 'request'])->middleware('throttle:login-link')->name('login-link.request');
    Route::get('/login-link/{token}', [LoginLinkController::class, 'show'])->name('login-link.show');
    Route::post('/login-link/{token}', [LoginLinkController::class, 'consume'])->middleware('throttle:login-link-use')->name('login-link.consume');
    Route::post('/two-factor-challenge/email', [TwoFactorEmailCodeController::class, 'send'])->middleware('throttle:two-factor-email')->name('two-factor.email.send');
    Route::post('/two-factor-challenge/email/verify', [TwoFactorEmailCodeController::class, 'verify'])->middleware('throttle:two-factor')->name('two-factor.email.verify');
});

Route::middleware('auth')->group(function () {
    Route::get('/account/security', fn () => view('auth.pending', ['screen' => 'Secure your account']))->name('account.security');
    Route::post('/account/password', [InitialPasswordController::class, 'store'])->name('account.password.set');
});
