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
use App\Http\Controllers\EmailWordingController;
use App\Http\Controllers\People\ClearanceController;
use App\Http\Controllers\People\InviteController;
use App\Http\Controllers\People\PeopleController;
use App\Http\Controllers\People\PersonController;
use App\Http\Controllers\PersonSecurityController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\Volunteer\ChangeNoticeController;
use App\Http\Controllers\Volunteer\EmailSettingsController;
use App\Http\Controllers\Volunteer\HomeController;
use App\Http\Controllers\Volunteer\ReleaseController;
use App\Http\Controllers\Volunteer\ShadowController;
use App\Http\Controllers\Volunteer\TakeController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Http\Controllers\NewPasswordController;

Route::get('/', [HomeController::class, 'home'])->middleware('auth')->name('home');

Route::middleware('guest')->group(function () {
    Route::post('/login-link', [LoginLinkController::class, 'request'])->middleware('throttle:login-link')->name('login-link.request');
    Route::get('/login-link/{token}', [LoginLinkController::class, 'show'])->name('login-link.show');
    Route::post('/login-link/{token}', [LoginLinkController::class, 'consume'])->middleware('throttle:login-link-use')->name('login-link.consume');
    Route::post('/two-factor-challenge/email', [TwoFactorEmailCodeController::class, 'send'])->middleware('throttle:two-factor-email')->name('two-factor.email.send');
    Route::post('/two-factor-challenge/email/verify', [TwoFactorEmailCodeController::class, 'verify'])->middleware('throttle:two-factor')->name('two-factor.email.verify');
    // Fortify registers this route without a limit. Declaring it again here, with the same name and controller, replaces that one and adds the limit.
    Route::post('/reset-password', [NewPasswordController::class, 'store'])->middleware('throttle:password-reset')->name('password.update');
});

Route::middleware('auth')->group(function () {
    Route::get('/my-shifts', [HomeController::class, 'myShifts'])->name('my-shifts');
    Route::whereNumber('shift')->group(function () {
        Route::get('/shifts/{shift}/release', [ReleaseController::class, 'show'])->name('shifts.release');
        Route::post('/shifts/{shift}/release', [ReleaseController::class, 'store']);
        Route::get('/shifts/{shift}/take', [TakeController::class, 'show'])->name('shifts.take');
        Route::post('/shifts/{shift}/take', [TakeController::class, 'store']);
        Route::get('/shifts/{shift}/taken', [TakeController::class, 'taken'])->name('shifts.taken');
        Route::get('/shifts/{shift}/shadow', [ShadowController::class, 'show'])->name('shifts.shadow');
        Route::post('/shifts/{shift}/shadow', [ShadowController::class, 'store']);
    });
    Route::get('/changes/{batch}', [ChangeNoticeController::class, 'show'])->whereAlphaNumeric('batch')->name('changes.show');
    Route::post('/changes/{batch}', [ChangeNoticeController::class, 'confirm'])->whereAlphaNumeric('batch')->name('changes.confirm');
    Route::get('/shifts/released', [ReleaseController::class, 'released'])->name('shifts.released');
    Route::get('/account/emails', [EmailSettingsController::class, 'edit'])->name('account.emails');
    Route::post('/account/emails', [EmailSettingsController::class, 'update']);
    Route::get('/account', [AccountController::class, 'show'])->name('account.show');
    Route::get('/account/security', [AccountController::class, 'security'])->name('account.security');
    Route::post('/account/password', [InitialPasswordController::class, 'store'])->name('account.password.set');
    Route::get('/account/email', [EmailChangeController::class, 'edit'])->name('account.email.edit');
    Route::post('/account/email', [EmailChangeController::class, 'update'])->middleware('throttle:email-change')->name('account.email.update');
    Route::post('/account/email/identity', [EmailChangeController::class, 'sendIdentityLink'])->middleware('throttle:email-change-identity')->name('account.email.identity.send');
    // Everyone active can read the schedule; the routes below it that change it need a role.
    Route::middleware('can:view-schedule')->group(function () {
        Route::get('/calendar', [CalendarController::class, 'week'])->name('calendar.week');
        Route::get('/calendar/day', [CalendarController::class, 'day'])->name('calendar.day');
        Route::get('/calendar/month', [CalendarController::class, 'month'])->name('calendar.month');
        Route::get('/calendar/shifts/{shift}', [ShiftController::class, 'show'])->name('calendar.shift');
    });
    Route::middleware('can:viewAny,App\Models\Area')->group(function () {
        Route::get('/calendar/add', [AddController::class, 'panel'])->name('calendar.add');
        Route::post('/calendar/add', [AddController::class, 'store'])->name('calendar.add.store');
        Route::get('/calendar/slots/create', [AddController::class, 'createSlot'])->name('calendar.slots.create');
        Route::post('/calendar/slots', [AddController::class, 'storeSlot'])->name('calendar.slots.store');
        Route::post('/calendar/shifts/{shift}/assign', [ShiftController::class, 'assign'])->name('calendar.shift.assign');
        Route::get('/calendar/shifts/{shift}/remove-person', [ShiftController::class, 'removePerson'])->name('calendar.shift.remove-person');
        Route::post('/calendar/shifts/{shift}/remove-person', [ShiftController::class, 'removePersonStore'])->name('calendar.shift.remove-person.store');
        Route::post('/calendar/shifts/{shift}/no-show', [ShiftController::class, 'noShow'])->name('calendar.shift.no-show');
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
    // The email editor (wireframes 61 to 63). Changing what every volunteer receives is for active coordinators and admins only.
    Route::middleware('can:manage-emails')->where(['email' => '[a-z_]+', 'line' => '[a-z0-9_.]+'])->group(function () {
        Route::get('/emails', [EmailWordingController::class, 'index'])->name('emails.index');
        Route::get('/emails/{email}', [EmailWordingController::class, 'edit'])->name('emails.edit');
        Route::middleware('throttle:email-editor')->group(function () {
            Route::put('/emails/{email}', [EmailWordingController::class, 'update'])->name('emails.update');
            Route::post('/emails/{email}/preview', [EmailWordingController::class, 'preview'])->name('emails.preview');
            Route::delete('/emails/{email}/lines/{line}', [EmailWordingController::class, 'reset'])->name('emails.reset');
        });
    });
    Route::get('/settings', [SettingsController::class, 'edit'])->name('settings');
    Route::put('/settings', [SettingsController::class, 'update']);
    Route::put('/settings/shadowing', [SettingsController::class, 'updateShadowing'])->name('settings.shadowing');
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
        Route::post('/people/{user}/clear', [ClearanceController::class, 'store'])->name('people.clear');
        Route::get('/people/{user}/move-back', [ClearanceController::class, 'confirmMoveBack'])->name('people.move-back');
        Route::post('/people/{user}/move-back', [ClearanceController::class, 'moveBack'])->name('people.move-back.store');
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
