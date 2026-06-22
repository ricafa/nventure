<?php

use App\Models\Produto;
use App\Models\Usuario;
use App\Services\ServicoMovimentacoes;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->usuario = Usuario::factory()->create();
    Sanctum::actingAs($this->usuario);
    $this->produto = Produto::factory()->create();
});

/** Cria um FUTURO COMPRADO 10@100, venc. futura, e devolve o id. */
function futuroBase(int $produtoId): int
{
    return test()->postJson('/api/v1/posicoes/futuro', [
        'produto_id' => $produtoId,
        'mercado' => 'BOLSA',
        'lado' => 'COMPRADO',
        'quantidade' => 10,
        'data_entrada' => '2026-05-01',
        'data_vencimento' => '2026-12-01',
        'preco_entrada' => 100,
        'codigo_contrato' => 'ZSU24',
    ])->json('data.id');
}

it('AUMENTO recalcula preço médio ponderado e devolve estado flat (§5.2.3, RN-021)', function () {
    $id = futuroBase($this->produto->id);

    $this->postJson("/api/v1/posicoes/{$id}/movimentacoes", [
        'tipo' => 'AUMENTO',
        'data_movimentacao' => '2026-06-01',
        'quantidade' => 10,
        'preco' => 120,
    ])
        ->assertOk()
        ->assertJsonPath('posicao_id', $id)
        ->assertJsonPath('quantidade_atual', 20)
        ->assertJsonPath('preco_medio', 110)   // (10*100 + 10*120)/20
        ->assertJsonPath('pl_realizado', 0)
        ->assertJsonPath('status', 'ABERTA');

    // RN-024: quantidade consolidada na mãe.
    $this->assertDatabaseHas('posicao', ['id' => $id, 'quantidade' => 20]);
});

it('REDUCAO parcial gera P&L realizado e mantém o preço médio (RN-021/023)', function () {
    $id = futuroBase($this->produto->id);
    $this->postJson("/api/v1/posicoes/{$id}/movimentacoes", ['tipo' => 'AUMENTO', 'data_movimentacao' => '2026-06-01', 'quantidade' => 10, 'preco' => 120]);

    // pm=110, qtd=20. Reduz 5@130 → realizado=(130-110)*5=100, pm inalterado.
    $this->postJson("/api/v1/posicoes/{$id}/movimentacoes", [
        'tipo' => 'REDUCAO',
        'data_movimentacao' => '2026-06-02',
        'quantidade' => 5,
        'preco' => 130,
    ])
        ->assertOk()
        ->assertJsonPath('quantidade_atual', 15)
        ->assertJsonPath('preco_medio', 110)
        ->assertJsonPath('pl_realizado', 100)
        ->assertJsonPath('status', 'ABERTA');
});

it('redução total encerra a posição automaticamente (RN-022)', function () {
    $id = futuroBase($this->produto->id);

    $this->postJson("/api/v1/posicoes/{$id}/movimentacoes", [
        'tipo' => 'REDUCAO',
        'data_movimentacao' => '2026-06-01',
        'quantidade' => 10,
        'preco' => 130,
    ])
        ->assertOk()
        ->assertJsonPath('quantidade_atual', 0)
        ->assertJsonPath('status', 'ENCERRADA');

    $this->assertDatabaseHas('posicao', ['id' => $id, 'status' => 'ENCERRADA', 'quantidade' => 0]);
});

it('rejeita redução excedente com 422 antes do INSERT (RN-022)', function () {
    $id = futuroBase($this->produto->id);

    $this->postJson("/api/v1/posicoes/{$id}/movimentacoes", [
        'tipo' => 'REDUCAO',
        'data_movimentacao' => '2026-06-01',
        'quantidade' => 11, // saldo é 10
        'preco' => 130,
    ])->assertStatus(422)->assertJsonPath('erro', 'ERRO_VALIDACAO');

    // Nenhuma redução foi persistida; saldo intacto.
    $this->assertDatabaseMissing('posicao_movimentacao', ['posicao_id' => $id, 'tipo' => 'REDUCAO']);
    $this->assertDatabaseHas('posicao', ['id' => $id, 'quantidade' => 10]);
});

it('rejeita data de movimentação anterior à entrada (422, RN-025)', function () {
    $id = futuroBase($this->produto->id);

    $this->postJson("/api/v1/posicoes/{$id}/movimentacoes", [
        'tipo' => 'AUMENTO',
        'data_movimentacao' => '2026-04-30', // antes de 2026-05-01
        'quantidade' => 5,
        'preco' => 120,
    ])->assertStatus(422)->assertJsonPath('erro', 'ERRO_VALIDACAO');
});

it('rejeita movimentação em posição não-FUTURO (409)', function () {
    $produto = Produto::factory()->create(['nome' => 'CEPEA_SOJA']);
    $id = $this->postJson('/api/v1/posicoes/otc', [
        'produto_id' => $produto->id,
        'mercado' => 'BALCAO', 'lado' => 'COMPRADO', 'quantidade' => 5,
        'data_entrada' => '2026-05-01', 'data_vencimento' => '2026-12-01',
        'contraparte' => 'Y', 'preco_entrada' => 130, 'indexador' => 'CEPEA_SOJA',
    ])->json('data.id');

    $this->postJson("/api/v1/posicoes/{$id}/movimentacoes", [
        'tipo' => 'AUMENTO', 'data_movimentacao' => '2026-06-01', 'quantidade' => 5, 'preco' => 120,
    ])->assertStatus(409)->assertJsonPath('erro', 'ERRO_CONFLITO');
});

it('rejeita movimentação em posição já ENCERRADA (409)', function () {
    $id = futuroBase($this->produto->id);
    $this->postJson("/api/v1/posicoes/{$id}/movimentacoes", ['tipo' => 'REDUCAO', 'data_movimentacao' => '2026-06-01', 'quantidade' => 10, 'preco' => 130])->assertOk();

    $this->postJson("/api/v1/posicoes/{$id}/movimentacoes", ['tipo' => 'AUMENTO', 'data_movimentacao' => '2026-06-02', 'quantidade' => 5, 'preco' => 120])
        ->assertStatus(409);
});

it('duas movimentações em sequência refletem na quantidade (A-3, recarga pós-INSERT)', function () {
    $id = futuroBase($this->produto->id);

    // Duas chamadas ao Service na mesma sequência lógica; a 2ª enxerga a 1ª (replay recarregado).
    $servico = app(ServicoMovimentacoes::class);
    $servico->movimentarFuturo($id, ['tipo' => 'AUMENTO', 'data_movimentacao' => '2026-06-01', 'quantidade' => 10, 'preco' => 120]);
    $estado = $servico->movimentarFuturo($id, ['tipo' => 'AUMENTO', 'data_movimentacao' => '2026-06-02', 'quantidade' => 5, 'preco' => 130]);

    expect($estado->quantidadeAtual)->toBe(25.0);
    $this->assertDatabaseHas('posicao', ['id' => $id, 'quantidade' => 25]);
});

it('lista movimentações de um FUTURO', function () {
    $id = futuroBase($this->produto->id);
    $this->postJson("/api/v1/posicoes/{$id}/movimentacoes", ['tipo' => 'AUMENTO', 'data_movimentacao' => '2026-06-01', 'quantidade' => 10, 'preco' => 120]);

    $this->getJson("/api/v1/posicoes/{$id}/movimentacoes")
        ->assertOk()
        ->assertJsonCount(2, 'data'); // ABERTURA + AUMENTO
});

it('valida estrutura da movimentação (tipo/quantidade/preço) com 422 nativo', function () {
    $id = futuroBase($this->produto->id);

    $this->postJson("/api/v1/posicoes/{$id}/movimentacoes", [
        'tipo' => 'ABERTURA', // não permitido nesta rota
        'data_movimentacao' => '2026-06-01',
        'quantidade' => 0,
        'preco' => -1,
    ])->assertStatus(422)->assertJsonValidationErrors(['tipo', 'quantidade', 'preco']);
});
