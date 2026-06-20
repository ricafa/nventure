<?php

use App\Models\PrecoReferencia;
use App\Models\Produto;
use App\Models\Usuario;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Sanctum::actingAs(Usuario::factory()->create());
});

it('lança um preço (201)', function () {
    $produto = Produto::factory()->create();

    $this->postJson('/api/v1/precos', [
        'produto_id' => $produto->id,
        'data_preco' => '2026-05-23',
        'preco_fechamento' => 1450.50,
        'cambio_brl' => 5.12,
    ])
        ->assertCreated()
        ->assertJsonPath('data.produto_id', $produto->id)
        ->assertJsonPath('data.preco_fechamento', 1450.5);

    $this->assertDatabaseHas('preco_referencia', [
        'produto_id' => $produto->id,
        'data_preco' => '2026-05-23',
    ]);
});

it('rejeita preço duplicado produto+data com 409 (RN-007)', function () {
    $produto = Produto::factory()->create();
    PrecoReferencia::factory()->create([
        'produto_id' => $produto->id,
        'data_preco' => '2026-05-23',
    ]);

    $this->postJson('/api/v1/precos', [
        'produto_id' => $produto->id,
        'data_preco' => '2026-05-23',
        'preco_fechamento' => 1450.50,
        'cambio_brl' => 5.12,
    ])
        ->assertStatus(409)
        ->assertJsonPath('erro', 'ERRO_CONFLITO');
});

it('rejeita preço não positivo (422) — RN-008', function () {
    $produto = Produto::factory()->create();

    $this->postJson('/api/v1/precos', [
        'produto_id' => $produto->id,
        'data_preco' => '2026-05-23',
        'preco_fechamento' => 0,
        'cambio_brl' => 5.12,
    ])->assertStatus(422);
});

it('lista preços filtrando por produto', function () {
    $a = Produto::factory()->create();
    $b = Produto::factory()->create();
    PrecoReferencia::factory()->count(2)->create(['produto_id' => $a->id]);
    PrecoReferencia::factory()->create(['produto_id' => $b->id]);

    $this->getJson("/api/v1/precos?produto_id={$a->id}")
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

it('remove preço sem MtM (204)', function () {
    $preco = PrecoReferencia::factory()->create();

    $this->deleteJson("/api/v1/precos/{$preco->id}")
        ->assertNoContent();

    $this->assertDatabaseMissing('preco_referencia', ['id' => $preco->id]);
});

it('bloqueia remoção de preço já referenciado por MtM com 409 (RN-010a)', function () {
    $preco = PrecoReferencia::factory()->create();

    // Posição mínima + linha de MtM apontando para o preço (FK preco_ref_id).
    $posicaoId = DB::table('posicao')->insertGetId([
        'produto_id' => $preco->produto_id,
        'instrumento' => 'FUTURO',
        'mercado' => 'BOLSA',
        'lado' => 'COMPRADO',
        'quantidade' => 100,
        'data_entrada' => '2026-05-20',
        'data_vencimento' => '2026-09-15',
        'status' => 'ABERTA',
        'criado_por' => 'teste',
    ]);
    DB::table('mtm_diario')->insert([
        'posicao_id' => $posicaoId,
        'preco_ref_id' => $preco->id,
        'data_calculo' => $preco->data_preco->toDateString(),
        'preco_mercado' => 1450.50,
        'mtm_valor' => 100.00,
        'variacao_dia' => 10.00,
        'pl_acumulado' => 100.00,
    ]);

    $this->deleteJson("/api/v1/precos/{$preco->id}")
        ->assertStatus(409)
        ->assertJsonPath('erro', 'ERRO_CONFLITO');

    $this->assertDatabaseHas('preco_referencia', ['id' => $preco->id]);
});
