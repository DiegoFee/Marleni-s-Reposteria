<?php

use App\Livewire\Actions\Logout;
use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

Route::middleware('guest')->group(function () {
    Volt::route('login', 'auth.login')
        ->name('login');
});

Route::middleware(['auth', 'auth.session'])->group(function () {
    Volt::route('confirm-password', 'auth.confirm-password')
        ->name('password.confirm');
});

Route::post('logout', Logout::class)
    ->middleware(['auth', 'auth.session'])
    ->name('logout');
