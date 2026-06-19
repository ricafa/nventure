<?php

use App\Models\Futuro;
use App\Models\Ndf;
use App\Models\Opcao;
use App\Models\Otc;
use App\Models\Posicao;
use Illuminate\Support\Facades\DB;

/*
| DoD #1 da Fase 2 (§7.1): com uma linha de cada `instrumento`, Posicao::query()->get()
| devolve a subclasse correta — o polimorfismo de newFromBuilder (§4.5). Roda contra o
| Postgres de teste (mesmo banco da Fase 1).
*/

function criarProdutoBase(string $nome = 'Soja CBOT'): int
{
    return (int) DB::table('produto')->insertGetId([
        'nome' => $nome,
        'unidade' => 'bushel',
        'bolsa_ref' => 'CBOT',
        'moeda_cotacao' => 'USD',
    ]);
}

function criarPosicaoBase(int $produtoId, string $instrumento): int
{
    return (int) DB::table('posicao')->insertGetId([
        'produto_id' => $produtoId,
        'instrumento' => $instrumento,
        'mercado' => $instrumento === 'FUTURO' || $instrumento === 'OPCAO' ? 'BOLSA' : 'BALCAO',
        'lado' => 'COMPRADO',
        'quantidade' => 100,
        'data_entrada' => '2026-05-23',
        'data_vencimento' => '2026-09-15',
        'contraparte' => $instrumento === 'FUTURO' || $instrumento === 'OPCAO' ? null : 'Banco XYZ',
        'status' => 'ABERTA',
        'criado_por' => 'tester',
    ]);
}

it('hidrata cada instrumento na subclasse correta', function () {
    $produtoId = criarProdutoBase();

    criarPosicaoBase($produtoId, 'FUTURO');
    criarPosicaoBase($produtoId, 'NDF');
    criarPosicaoBase($produtoId, 'OPCAO');
    criarPosicaoBase($produtoId, 'OTC');

    $posicoes = Posicao::query()->orderBy('id')->get();

    expect($posicoes[0])->toBeInstanceOf(Futuro::class)
        ->and($posicoes[1])->toBeInstanceOf(Ndf::class)
        ->and($posicoes[2])->toBeInstanceOf(Opcao::class)
        ->and($posicoes[3])->toBeInstanceOf(Otc::class);
});

it('expõe os métodos polimórficos de cada subclasse', function () {
    $produtoId = criarProdutoBase('Milho B3');
    criarPosicaoBase($produtoId, 'NDF');

    $posicao = Posicao::query()->firstOrFail();

    expect($posicao->sinal())->toBe(1)
        ->and($posicao->plRealizado())->toBe(0.0);
});
