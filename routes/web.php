<?php

use App\Livewire\Motor\ProcessarMotor;
use App\Livewire\Posicoes\FormNovaPosicao;
use App\Livewire\Posicoes\ListaPosicoes;
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

    // Parte 5 — Posições (§5.2.3 telas). /nova antes de qualquer rota com parâmetro.
    Route::get('/posicoes/nova', FormNovaPosicao::class)->name('posicoes.nova');
    Route::get('/posicoes', ListaPosicoes::class)->name('posicoes.index');

    // Parte 6 — Motor MtM
    Route::get('/motor', ProcessarMotor::class)->name('motor.index');
});
