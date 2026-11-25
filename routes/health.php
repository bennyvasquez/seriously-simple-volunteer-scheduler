<?php

use Illuminate\Foundation\Events\DiagnosingHealth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;

/*
| The health check for monitors: a plain page of our own with no scripts, no session and no middleware group. It stays up during maintenance mode (bootstrap/app.php). Laravel's DiagnosingHealth event still fires, so anything listening to it can fail the check.
*/
Route::get('/up', function () {
    try {
        Event::dispatch(new DiagnosingHealth);
    } catch (Throwable $exception) {
        report($exception);

        return response('Not OK', 500, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }

    return response('OK', 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
})->name('health');
