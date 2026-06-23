<?php

use App\Http\Controllers\Api\V1\MotorController;
use App\Http\Controllers\Api\V1\PosicaoController;
use App\Http\Controllers\Api\V1\PrecoController;
use App\Http\Controllers\Api\V1\ProdutoController;
use App\Http\Controllers\Api\V1\RelatorioController;
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

    // §5.2.3 Posições — rotas estáticas (cadastro por tipo) antes de {id}.
    Route::post('posicoes/futuro', [PosicaoController::class, 'storeFuturo']);
    Route::post('posicoes/ndf', [PosicaoController::class, 'storeNdf']);
    Route::post('posicoes/opcao', [PosicaoController::class, 'storeOpcao']);
    Route::post('posicoes/otc', [PosicaoController::class, 'storeOtc']);
    Route::get('posicoes', [PosicaoController::class, 'index']);              // paginada (§9.1)
    Route::get('posicoes/{id}', [PosicaoController::class, 'show'])->whereNumber('id');
    Route::post('posicoes/{id}/encerrar', [PosicaoController::class, 'encerrar'])->whereNumber('id');
    Route::delete('posicoes/{id}', [PosicaoController::class, 'destroy'])->whereNumber('id'); // só sem MtM (D-502)
    Route::get('posicoes/{id}/movimentacoes', [PosicaoController::class, 'movimentacoes'])->whereNumber('id');
    Route::post('posicoes/{id}/movimentacoes', [PosicaoController::class, 'movimentar'])->whereNumber('id');

    // §5.2.4 Motor MtM (AuthZ por perfil é Fase 10, D-612)
    Route::post('motor/processar', [MotorController::class, 'processar']);
    Route::get('motor/execucoes', [MotorController::class, 'index']);
    Route::get('motor/execucoes/{id}', [MotorController::class, 'show'])->whereNumber('id');

    // §5.2.5 Relatórios (AuthZ por perfil é Fase 10, D-709). Cada um aceita ?formato=json|csv|pdf.
    Route::get('relatorios/posicao-aberta', [RelatorioController::class, 'posicaoAberta']);
    Route::get('relatorios/pl-diario', [RelatorioController::class, 'plDiario']);
    Route::get('relatorios/exposicao-liquida', [RelatorioController::class, 'exposicaoLiquida']);
    Route::get('relatorios/historico-mtm', [RelatorioController::class, 'historicoMtm']);
});
