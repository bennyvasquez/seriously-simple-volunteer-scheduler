<?php

use App\Actions\Accounts\SignOutEverywhere;
use App\Http\Controllers\AccountController;
use App\Http\Controllers\Auth\InitialPasswordController;
use App\Http\Controllers\Auth\LoginLinkController;
use App\Http\Controllers\Auth\TwoFactorEmailCodeController;
use App\Http\Controllers\Calendar\AddController;
use App\Http\Controllers\Calendar\AreaController;
use App\Http\Controllers\Calendar\CalendarController;
use App\Http\Controllers\Calendar\ClosureController;
use App\Http\Controllers\Calendar\RegularController;
use App\Http\Controllers\Calendar\ShiftController;
use App\Http\Controllers\ChangeLogController;
use App\Http\Controllers\EmailChangeController;
use App\Http\Controllers\People\InviteController;
use App\Http\Controllers\People\PeopleController;
use App\Http\Controllers\People\PersonController;
use App\Http\Controllers\PersonSecurityController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\Volunteer\EmailSettingsController;
use App\Http\Controllers\Volunteer\HomeController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::get('/', [HomeController::class, 'home'])->middleware('auth')->name('home');

Route::middleware('guest')->group(function () {
    Route::post('/login-link', [LoginLinkController::class, 'request'])->middleware('throttle:login-link')->name('login-link.request');
    Route::get('/login-link/{token}', [LoginLinkController::class, 'show'])->name('login-link.show');
    Route::post('/login-link/{token}', [LoginLinkController::class, 'consume'])->middleware('throttle:login-link-use')->name('login-link.consume');
    Route::post('/two-factor-challenge/email', [TwoFactorEmailCodeController::class, 'send'])->middleware('throttle:two-factor-email')->name('two-factor.email.send');
    Route::post('/two-factor-challenge/email/verify', [TwoFactorEmailCodeController::class, 'verify'])->middleware('throttle:two-factor')->name('two-factor.email.verify');
});

Route::middleware('auth')->group(function () {
    Route::get('/my-shifts', [HomeController::class, 'myShifts'])->name('my-shifts');
    Route::get('/account/emails', [EmailSettingsController::class, 'edit'])->name('account.emails');
    Route::post('/account/emails', [EmailSettingsController::class, 'update']);
    Route::get('/account', [AccountController::class, 'show'])->name('account.show');
    Route::get('/account/security', [AccountController::class, 'security'])->name('account.security');
    Route::post('/account/password', [InitialPasswordController::class, 'store'])->name('account.password.set');
    Route::get('/account/email', [EmailChangeController::class, 'edit'])->name('account.email.edit');
    Route::post('/account/email', [EmailChangeController::class, 'update'])->middleware('throttle:email-change')->name('account.email.update');
    Route::post('/account/email/identity', [EmailChangeController::class, 'sendIdentityLink'])->middleware('throttle:email-change-identity')->name('account.email.identity.send');
    Route::middleware('can:viewAny,App\Models\Area')->group(function () {
        Route::get('/calendar', [CalendarController::class, 'week'])->name('calendar.week');
        Route::get('/calendar/day', [CalendarController::class, 'day'])->name('calendar.day');
        Route::get('/calendar/month', [CalendarController::class, 'month'])->name('calendar.month');
        Route::get('/calendar/add', [AddController::class, 'panel'])->name('calendar.add');
        Route::post('/calendar/add', [AddController::class, 'store'])->name('calendar.add.store');
        Route::get('/calendar/slots/create', [AddController::class, 'createSlot'])->name('calendar.slots.create');
        Route::post('/calendar/slots', [AddController::class, 'storeSlot'])->name('calendar.slots.store');
        Route::get('/calendar/shifts/{shift}', [ShiftController::class, 'show'])->name('calendar.shift');
        Route::post('/calendar/shifts/{shift}/assign', [ShiftController::class, 'assign'])->name('calendar.shift.assign');
        Route::get('/calendar/shifts/{shift}/remove-person', [ShiftController::class, 'removePerson'])->name('calendar.shift.remove-person');
        Route::post('/calendar/shifts/{shift}/remove-person', [ShiftController::class, 'removePersonStore'])->name('calendar.shift.remove-person.store');
        Route::post('/calendar/shifts/{shift}/cancel', [ShiftController::class, 'cancel'])->name('calendar.shift.cancel');
        Route::post('/calendar/shifts/{shift}/restore', [ShiftController::class, 'restore'])->name('calendar.shift.restore');
        Route::post('/calendar/shifts/{shift}', [ShiftController::class, 'update'])->name('calendar.shift.update');
        Route::get('/calendar/slots/{slot}/regulars/create', [RegularController::class, 'create'])->name('calendar.slots.regulars.create');
        Route::post('/calendar/slots/{slot}/regulars', [RegularController::class, 'store'])->name('calendar.slots.regulars.store');
        Route::get('/calendar/slots/{slot}/regulars/{regular}/end', [RegularController::class, 'end'])->name('calendar.slots.regulars.end');
        Route::post('/calendar/slots/{slot}/regulars/{regular}/end', [RegularController::class, 'endStore'])->name('calendar.slots.regulars.end.store');
        Route::get('/calendar/slots/{slot}/edit', [AddController::class, 'editSlot'])->name('calendar.slots.edit');
        Route::post('/calendar/slots/{slot}', [AddController::class, 'updateSlot'])->name('calendar.slots.update');
        Route::get('/calendar/closures/create', [ClosureController::class, 'create'])->name('calendar.closures.create');
        Route::post('/calendar/closures', [ClosureController::class, 'store'])->name('calendar.closures.store');
        Route::get('/calendar/closures/{closure}/edit', [ClosureController::class, 'edit'])->name('calendar.closures.edit');
        Route::post('/calendar/closures/{closure}', [ClosureController::class, 'update'])->name('calendar.closures.update');
        Route::post('/calendar/closures/{closure}/remove', [ClosureController::class, 'remove'])->name('calendar.closures.remove');
        Route::get('/calendar/areas/create', [AreaController::class, 'create'])->name('calendar.areas.create');
        Route::post('/calendar/areas', [AreaController::class, 'store'])->name('calendar.areas.store');
        Route::get('/calendar/areas/{area}/edit', [AreaController::class, 'edit'])->name('calendar.areas.edit');
        Route::post('/calendar/areas/{area}', [AreaController::class, 'update'])->name('calendar.areas.update');
    });
    Route::get('/change-log', [ChangeLogController::class, 'index'])->middleware('can:view-change-log')->name('change-log.index');
    Route::get('/settings', [SettingsController::class, 'edit'])->name('settings');
    Route::put('/settings', [SettingsController::class, 'update']);
    Route::get('/people', [PeopleController::class, 'index'])->middleware('can:viewAny,App\Models\User')->name('people.index');
    Route::middleware('can:invite,App\Models\User')->group(function () {
        Route::get('/people/invite', [InviteController::class, 'create'])->name('people.invite');
        Route::post('/people/invite', [InviteController::class, 'store'])->name('people.invite.store');
        Route::get('/people/invite-everyone', [InviteController::class, 'everyone'])->name('people.invite-everyone');
        Route::post('/people/invite-everyone', [InviteController::class, 'sendToEveryone'])->name('people.invite-everyone.store');
    });
    Route::middleware('can:viewAny,App\Models\User')->whereNumber('user')->group(function () {
        Route::get('/people/{user}', [PersonController::class, 'show'])->name('people.show');
        Route::post('/people/{user}/roles', [PersonController::class, 'addRole'])->name('people.roles.store');
        Route::post('/people/{user}/roles/remove', [PersonController::class, 'removeRole'])->name('people.roles.destroy');
        Route::post('/people/{user}/invite', [PersonController::class, 'invite'])->name('people.invite.send');
        Route::post('/people/{user}/turn-off', [PersonController::class, 'turnOff'])->name('people.turn-off');
        Route::get('/people/{user}/remove', [PersonController::class, 'confirmRemove'])->name('people.remove');
        Route::post('/people/{user}/remove', [PersonController::class, 'remove'])->name('people.remove.store');
    });
    Route::get('/people/{user}/security', [PersonSecurityController::class, 'show'])->name('people.security');
    Route::post('/people/{user}/two-factor-reset', [PersonSecurityController::class, 'reset'])->name('people.two-factor.reset');
    // The reset screens are for logged-out people, so "Forgot your password?" on the confirm screen logs out first.
    Route::post('/account/forgot-password', function (Request $request) {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('password.request');
    })->name('account.forgot-password');
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
