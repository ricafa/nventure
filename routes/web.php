<?php

use App\Livewire\Motor\ProcessarMotor;
use App\Livewire\Posicoes\FormNovaPosicao;
use App\Livewire\Posicoes\ListaPosicoes;
use App\Livewire\Precos\LancamentoPrecos;
use App\Livewire\Produtos\ListaProdutos;
use App\Livewire\Relatorios\Dashboard;
use App\Livewire\Relatorios\Exposicao;
use App\Livewire\Relatorios\PL;
use App\Livewire\Relatorios\PosicaoAberta;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return auth()->check()
        ? redirect()->route('dashboard')
        : redirect()->route('login');
});

// Parte 4 — telas Livewire sob sessão web (autorização por perfil é Fase 10).
Route::middleware('auth')->group(function () {
    // Parte 7 — Dashboard (§6.1#2) é a home autenticada (login → dashboard, §6.2/BX-4).
    Route::get('/dashboard', Dashboard::class)->name('dashboard');

    Route::get('/produtos', ListaProdutos::class)->name('produtos.index');
    Route::get('/precos', LancamentoPrecos::class)->name('precos.index');

    // Parte 5 — Posições (§5.2.3 telas). /nova antes de qualquer rota com parâmetro.
    Route::get('/posicoes/nova', FormNovaPosicao::class)->name('posicoes.nova');
    Route::get('/posicoes', ListaPosicoes::class)->name('posicoes.index');

    // Parte 6 — Motor MtM
    Route::get('/motor', ProcessarMotor::class)->name('motor.index');

    // Parte 7 — Relatórios (§5.2.5 / §6.1)
    Route::get('/relatorios/posicao-aberta', PosicaoAberta::class)->name('relatorios.posicao-aberta');
    Route::get('/relatorios/pl', PL::class)->name('relatorios.pl');
    Route::get('/relatorios/exposicao', Exposicao::class)->name('relatorios.exposicao');
});
