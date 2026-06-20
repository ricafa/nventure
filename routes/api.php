<?php

use App\Http\Controllers\Api\V1\PrecoController;
use App\Http\Controllers\Api\V1\ProdutoController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Rotas da API v1 (Parte 4 — Produtos & Preços)
|--------------------------------------------------------------------------
| Tudo sob `auth:sanctum` (autenticação). A autorização por perfil
| (OPERADOR/GESTOR/ADMIN, §9.2) é da Fase 10 — aqui não se restringe por perfil
| (D-402). Em teste, autentica-se com `Sanctum::actingAs(...)`.
*/

Route::middleware('auth:sanctum')->group(function () {
    // §5.2.1 Produtos — index/show/store/update/destroy (destroy inativa, D-405)
    Route::apiResource('produtos', ProdutoController::class);

    // §5.2.2 Preços
    Route::get('precos', [PrecoController::class, 'index']);
    Route::post('precos', [PrecoController::class, 'store']);
    Route::post('precos/upload', [PrecoController::class, 'upload']);
    Route::delete('precos/{preco}', [PrecoController::class, 'destroy']);
});
