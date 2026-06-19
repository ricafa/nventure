<?php

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| Esquema §3 — exercitado contra PostgreSQL (postgres_test). CHECKs, índice único
| parcial e JSONB não existem no SQLite, por isso este teste roda na conexão real.
| Como ainda não há Models (Fase 2), as violações usam INSERT cru e devem lançar
| QueryException. Cada INSERT inválido roda dentro de DB::transaction() (savepoint)
| para não envenenar a transação do RefreshDatabase no Postgres.
*/

/** Executa um INSERT que deve falhar isolando-o num savepoint. */
function inserirInvalido(callable $insert): void
{
    DB::transaction($insert);
}

function novoProduto(string $nome = 'Soja CBOT'): int
{
    return (int) DB::table('produto')->insertGetId([
        'nome' => $nome,
        'unidade' => 'bushel',
        'bolsa_ref' => 'CBOT',
        'moeda_cotacao' => 'USD',
    ]);
}

function novaPosicaoFuturo(int $produtoId): int
{
    return (int) DB::table('posicao')->insertGetId([
        'produto_id' => $produtoId,
        'instrumento' => 'FUTURO',
        'mercado' => 'BOLSA',
        'lado' => 'COMPRADO',
        'quantidade' => 100,
        'data_entrada' => '2026-05-23',
        'data_vencimento' => '2026-09-15',
        'status' => 'ABERTA',
        'criado_por' => 'tester',
    ]);
}

it('cria todo o esquema §3 no Postgres (migrate:fresh)', function () {
    $tabelas = [
        'produto', 'preco_referencia', 'posicao', 'posicao_futuro',
        'posicao_movimentacao', 'posicao_ndf', 'posicao_opcao',
        'posicao_opcao_perna', 'posicao_otc', 'mtm_diario',
        'motor_execucao', 'usuario',
    ];

    foreach ($tabelas as $tabela) {
        expect(Schema::hasTable($tabela))->toBeTrue("Tabela ausente: {$tabela}");
    }

    expect(DB::getDriverName())->toBe('pgsql');
});

it('uq_mov_abertura barra mais de uma ABERTURA mas permite vários AUMENTOs', function () {
    $posicaoId = novaPosicaoFuturo(novoProduto());

    DB::table('posicao_movimentacao')->insert([
        'posicao_id' => $posicaoId, 'tipo' => 'ABERTURA',
        'data_movimentacao' => '2026-05-23', 'quantidade' => 100, 'preco' => 1400, 'criado_por' => 'tester',
    ]);

    expect(fn () => inserirInvalido(fn () => DB::table('posicao_movimentacao')->insert([
        'posicao_id' => $posicaoId, 'tipo' => 'ABERTURA',
        'data_movimentacao' => '2026-05-24', 'quantidade' => 50, 'preco' => 1410, 'criado_por' => 'tester',
    ])))->toThrow(QueryException::class);

    // AUMENTO não é coberto pelo índice parcial — múltiplos são permitidos.
    DB::table('posicao_movimentacao')->insert([
        ['posicao_id' => $posicaoId, 'tipo' => 'AUMENTO', 'data_movimentacao' => '2026-06-01', 'quantidade' => 50, 'preco' => 1430, 'criado_por' => 'tester'],
        ['posicao_id' => $posicaoId, 'tipo' => 'AUMENTO', 'data_movimentacao' => '2026-06-02', 'quantidade' => 25, 'preco' => 1440, 'criado_por' => 'tester'],
    ]);

    expect(DB::table('posicao_movimentacao')->where('posicao_id', $posicaoId)->count())->toBe(3);
});

it('aplica os CHECKs de domínio (ENUMs) a nível de banco', function () {
    $produtoId = novoProduto();

    // instrumento inválido
    expect(fn () => inserirInvalido(fn () => DB::table('posicao')->insert([
        'produto_id' => $produtoId, 'instrumento' => 'SWAP', 'mercado' => 'BOLSA', 'lado' => 'COMPRADO',
        'quantidade' => 1, 'data_entrada' => '2026-05-23', 'data_vencimento' => '2026-09-15', 'criado_por' => 'tester',
    ])))->toThrow(QueryException::class);

    // status inválido
    expect(fn () => inserirInvalido(fn () => DB::table('posicao')->insert([
        'produto_id' => $produtoId, 'instrumento' => 'FUTURO', 'mercado' => 'BOLSA', 'lado' => 'COMPRADO',
        'quantidade' => 1, 'data_entrada' => '2026-05-23', 'data_vencimento' => '2026-09-15', 'status' => 'XPTO', 'criado_por' => 'tester',
    ])))->toThrow(QueryException::class);

    // perfil de usuário inválido (comprova a consolidação da §3.2.10)
    expect(fn () => inserirInvalido(fn () => DB::table('usuario')->insert([
        'login' => 'jdoe', 'nome' => 'John Doe', 'senha_hash' => 'x', 'perfil' => 'ROOT',
    ])))->toThrow(QueryException::class);
});

it('aplica os CHECKs de valor a nível de banco', function () {
    $posicaoId = novaPosicaoFuturo(novoProduto());

    // movimentação com preço <= 0
    expect(fn () => inserirInvalido(fn () => DB::table('posicao_movimentacao')->insert([
        'posicao_id' => $posicaoId, 'tipo' => 'AUMENTO', 'data_movimentacao' => '2026-06-01',
        'quantidade' => 10, 'preco' => 0, 'criado_por' => 'tester',
    ])))->toThrow(QueryException::class);
});

it('barra duplicatas nas chaves UNIQUE', function () {
    novoProduto('Milho B3');
    expect(fn () => inserirInvalido(fn () => novoProduto('Milho B3')))
        ->toThrow(QueryException::class);

    $produtoId = DB::table('produto')->where('nome', 'Milho B3')->value('id');
    DB::table('preco_referencia')->insert([
        'produto_id' => $produtoId, 'data_preco' => '2026-05-23', 'preco_fechamento' => 72.30, 'cambio_brl' => 5.12,
    ]);

    // mesma (produto_id, data_preco) — viola UNIQUE
    expect(fn () => inserirInvalido(fn () => DB::table('preco_referencia')->insert([
        'produto_id' => $produtoId, 'data_preco' => '2026-05-23', 'preco_fechamento' => 73.00, 'cambio_brl' => 5.10,
    ])))->toThrow(QueryException::class);
});
