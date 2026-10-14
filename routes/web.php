<?php

use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::view('dashboard', 'dashboard')->name('dashboard');
    Route::livewire('wallet', 'pages::wallet')->name('wallet');
    Route::livewire('rooms', 'pages::rooms')->name('rooms.index');
    Route::livewire('rooms/{room}', 'pages::rooms.show')->name('rooms.show');
});

require __DIR__.'/settings.php';
