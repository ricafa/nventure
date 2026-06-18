<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return auth()->check()
        ? redirect()->route('dashboard')
        : redirect()->route('login');
});

Route::view('/dashboard', 'dashboard')
    ->middleware('auth')
    ->name('dashboard');
