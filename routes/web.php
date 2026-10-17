<?php

use App\Actions\Accounts\SignOutEverywhere;
use App\Http\Controllers\AccountController;
use App\Http\Controllers\Auth\InitialPasswordController;
use App\Http\Controllers\Auth\LoginLinkController;
use App\Http\Controllers\Auth\TwoFactorEmailCodeController;
use App\Http\Controllers\EmailChangeController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::get('/', fn (Request $request) => view('home', ['user' => $request->user()]))->middleware('auth')->name('home');

Route::middleware('guest')->group(function () {
    Route::post('/login-link', [LoginLinkController::class, 'request'])->middleware('throttle:login-link')->name('login-link.request');
    Route::get('/login-link/{token}', [LoginLinkController::class, 'show'])->name('login-link.show');
    Route::post('/login-link/{token}', [LoginLinkController::class, 'consume'])->middleware('throttle:login-link-use')->name('login-link.consume');
    Route::post('/two-factor-challenge/email', [TwoFactorEmailCodeController::class, 'send'])->middleware('throttle:two-factor-email')->name('two-factor.email.send');
    Route::post('/two-factor-challenge/email/verify', [TwoFactorEmailCodeController::class, 'verify'])->middleware('throttle:two-factor')->name('two-factor.email.verify');
});

Route::middleware('auth')->group(function () {
    Route::get('/account', [AccountController::class, 'show'])->name('account.show');
    Route::get('/account/security', [AccountController::class, 'security'])->name('account.security');
    Route::post('/account/password', [InitialPasswordController::class, 'store'])->name('account.password.set');
    Route::get('/account/email', [EmailChangeController::class, 'edit'])->name('account.email.edit');
    Route::post('/account/email', [EmailChangeController::class, 'update'])->middleware('throttle:email-change')->name('account.email.update');
    Route::post('/account/email/identity', [EmailChangeController::class, 'sendIdentityLink'])->middleware('throttle:email-change-identity')->name('account.email.identity.send');
    Route::post('/account/sign-out-everywhere', function (Request $request, SignOutEverywhere $signOutEverywhere) {
        $signOutEverywhere->handle($request->user());
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->with('status', 'You\'re signed out on every device.');
    })->name('account.sign-out-everywhere');
});

// Emailed links for changing an address. They work whether or not this device is logged in.
Route::get('/account/email/identity/{token}', [EmailChangeController::class, 'showIdentity'])->name('account.email.identity');
Route::post('/account/email/identity/{token}', [EmailChangeController::class, 'confirmIdentity'])->middleware('throttle:login-link-use');
Route::get('/account/email/confirm/{token}', [EmailChangeController::class, 'showConfirm'])->name('account.email.confirm');
Route::post('/account/email/confirm/{token}', [EmailChangeController::class, 'confirm'])->middleware('throttle:login-link-use');
