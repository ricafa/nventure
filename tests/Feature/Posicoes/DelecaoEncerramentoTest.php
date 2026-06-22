<?php

use App\Exceptions\ErroValidacao;
use App\Models\MtmDiario;
use App\Models\Posicao;
use App\Models\PrecoReferencia;
use App\Models\Produto;
use App\Models\Usuario;
use App\Services\ServicoMovimentacoes;
use App\Services\ServicoPosicoes;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Sanctum::actingAs(Usuario::factory()->create());
    $this->produto = Produto::factory()->create();
});

function novoFuturo(int $produtoId): int
{
    return test()->postJson('/api/v1/posicoes/futuro', [
        'produto_id' => $produtoId,
        'mercado' => 'BOLSA', 'lado' => 'COMPRADO', 'quantidade' => 10,
        'data_entrada' => '2026-05-01', 'data_vencimento' => '2026-12-01',
        'preco_entrada' => 100, 'codigo_contrato' => 'ZSU24',
    ])->json('data.id');
}

it('DELETE remove posição "virgem" sem MtM (204, D-502)', function () {
    $id = novoFuturo($this->produto->id);

    $this->deleteJson("/api/v1/posicoes/{$id}")->assertNoContent();

    $this->assertDatabaseMissing('posicao', ['id' => $id]);
    // Cascata apaga movimentações/filha.
    $this->assertDatabaseMissing('posicao_movimentacao', ['posicao_id' => $id]);
});

it('DELETE em posição com MtM devolve 409 (D-502)', function () {
    $id = novoFuturo($this->produto->id);
    $preco = PrecoReferencia::factory()->create(['produto_id' => $this->produto->id]);

    MtmDiario::query()->create([
        'posicao_id' => $id,
        'preco_ref_id' => $preco->id,
        'data_calculo' => '2026-05-23',
        'preco_mercado' => 130,
        'mtm_valor' => 300,
        'variacao_dia' => 0,
        'pl_acumulado' => 300,
    ]);

    $this->deleteJson("/api/v1/posicoes/{$id}")
        ->assertStatus(409)
        ->assertJsonPath('erro', 'ERRO_CONFLITO');

    $this->assertDatabaseHas('posicao', ['id' => $id]);
});

it('encerrar faz transição idempotente ABERTA → ENCERRADA (D-507)', function () {
    $id = novoFuturo($this->produto->id);

    $this->postJson("/api/v1/posicoes/{$id}/encerrar")
        ->assertOk()
        ->assertJsonPath('data.status', 'ENCERRADA');

    // Idempotente: encerrar de novo não falha e mantém ENCERRADA.
    $this->postJson("/api/v1/posicoes/{$id}/encerrar")
        ->assertOk()
        ->assertJsonPath('data.status', 'ENCERRADA');
});

it('encerrar NÃO é bloqueado por MtM (ao contrário do DELETE, D-507)', function () {
    $id = novoFuturo($this->produto->id);
    $preco = PrecoReferencia::factory()->create(['produto_id' => $this->produto->id]);
    MtmDiario::query()->create([
        'posicao_id' => $id, 'preco_ref_id' => $preco->id, 'data_calculo' => '2026-05-23',
        'preco_mercado' => 130, 'mtm_valor' => 300, 'variacao_dia' => 0, 'pl_acumulado' => 300,
    ]);

    $this->postJson("/api/v1/posicoes/{$id}/encerrar")
        ->assertOk()
        ->assertJsonPath('data.status', 'ENCERRADA');
});

it('DELETE de posição inexistente devolve 404 no envelope §5.1', function () {
    $this->deleteJson('/api/v1/posicoes/999999')
        ->assertStatus(404)
        ->assertJsonPath('erro', 'ERRO_NAO_ENCONTRADO');
});

it('encerrar posição inexistente devolve 404 no envelope §5.1', function () {
    $this->postJson('/api/v1/posicoes/999999/encerrar')
        ->assertStatus(404)
        ->assertJsonPath('erro', 'ERRO_NAO_ENCONTRADO');
});

it('reduções sucessivas respeitam o saldo consolidado sob lock (RN-022, D-501)', function () {
    // Prova que posicao.quantidade consolidada é a fonte única: a 2ª redução que
    // excede o saldo remanescente é barrada (o lockForUpdate serializa o acesso).
    $id = novoFuturo($this->produto->id);
    $servico = app(ServicoPosicoes::class);
    $mov = app(ServicoMovimentacoes::class);

    $mov->movimentarFuturo($id, ['tipo' => 'REDUCAO', 'data_movimentacao' => '2026-06-01', 'quantidade' => 6, 'preco' => 130]);

    expect(Posicao::query()->find($id)->quantidade)->toEqual('4.0000');

    // Saldo agora é 4; reduzir 5 deve falhar (RN-022).
    expect(fn () => $mov->movimentarFuturo($id, ['tipo' => 'REDUCAO', 'data_movimentacao' => '2026-06-02', 'quantidade' => 5, 'preco' => 130]))
        ->toThrow(ErroValidacao::class);

    expect(Posicao::query()->find($id)->quantidade)->toEqual('4.0000');
});
