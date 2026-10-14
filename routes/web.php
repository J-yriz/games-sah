<?php

use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

Route::view('/', 'welcome')->name('home');

// Unauthenticated liveness probe for load balancers and monitoring.
// Reports only reachability, never credentials or internal details.
// Excluded from session middleware so it answers even when the
// database-backed session store is down.
Route::get('/health', function () {
    try {
        DB::select('select 1');
    } catch (Throwable) {
        return response()->json(['status' => 'error'], 503);
    }

    return response()->json(['status' => 'ok']);
})->withoutMiddleware([
    EncryptCookies::class,
    AddQueuedCookiesToResponse::class,
    StartSession::class,
    ShareErrorsFromSession::class,
    PreventRequestForgery::class,
])->name('health');

Route::middleware(['auth'])->group(function () {
    Route::view('dashboard', 'dashboard')->name('dashboard');
    Route::livewire('wallet', 'pages::wallet')->name('wallet');
    Route::livewire('rooms', 'pages::rooms')->name('rooms.index');
    Route::livewire('rooms/{room}', 'pages::rooms.show')->name('rooms.show');
    Route::livewire('rooms/{room}/hands', 'pages::rooms.hands')->name('rooms.hands');
    Route::livewire('rooms/{room}/hands/{hand}', 'pages::rooms.hand-detail')->name('rooms.hands.show');
});

require __DIR__.'/settings.php';
