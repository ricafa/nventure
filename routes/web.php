<?php

use App\Livewire\Precos\LancamentoPrecos;
use App\Livewire\Produtos\ListaProdutos;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return auth()->check()
        ? redirect()->route('dashboard')
        : redirect()->route('login');
});

Route::view('/dashboard', 'dashboard')
    ->middleware('auth')
    ->name('dashboard');

// Parte 4 — telas Livewire sob sessão web (autorização por perfil é Fase 10).
Route::middleware('auth')->group(function () {
    Route::get('/produtos', ListaProdutos::class)->name('produtos.index');
    Route::get('/precos', LancamentoPrecos::class)->name('precos.index');
});
