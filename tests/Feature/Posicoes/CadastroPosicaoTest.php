<?php

use App\Models\Movimentacao;
use App\Models\Perna;
use App\Models\Produto;
use App\Models\Usuario;
use Laravel\Sanctum\Sanctum;

function autenticaPosicoes(): Usuario
{
    $usuario = Usuario::factory()->create();
    Sanctum::actingAs($usuario);

    return $usuario;
}

function payloadFuturo(int $produtoId, array $extra = []): array
{
    return array_merge([
        'produto_id' => $produtoId,
        'mercado' => 'BOLSA',
        'lado' => 'COMPRADO',
        'quantidade' => 100,
        'data_entrada' => '2026-05-23',
        'data_vencimento' => '2026-09-15',
        'preco_entrada' => 1420.00,
        'codigo_contrato' => 'ZSU24',
    ], $extra);
}

it('cria FUTURO (201), gera ABERTURA automática e preenche criado_por (RN-020, D-507)', function () {
    $usuario = autenticaPosicoes();
    $produto = Produto::factory()->create();

    $resposta = $this->postJson('/api/v1/posicoes/futuro', payloadFuturo($produto->id))
        ->assertCreated()
        ->assertJsonPath('data.instrumento', 'FUTURO')
        ->assertJsonPath('data.status', 'ABERTA')
        ->assertJsonPath('data.quantidade', 100);

    $posicaoId = $resposta->json('data.id');

    // RN-020: exatamente uma ABERTURA, data = data_entrada, com criado_por preenchido.
    $this->assertDatabaseHas('posicao_movimentacao', [
        'posicao_id' => $posicaoId,
        'tipo' => 'ABERTURA',
        'data_movimentacao' => '2026-05-23',
        'criado_por' => $usuario->login,
    ]);
    $this->assertDatabaseHas('posicao', ['id' => $posicaoId, 'criado_por' => $usuario->login]);
    expect(Movimentacao::where('posicao_id', $posicaoId)->count())->toBe(1);
});

it('cria NDF (201) sem movimentações (RN-005)', function () {
    autenticaPosicoes();
    $produto = Produto::factory()->create();

    $resposta = $this->postJson('/api/v1/posicoes/ndf', [
        'produto_id' => $produto->id,
        'mercado' => 'BALCAO',
        'lado' => 'VENDIDO',
        'quantidade' => 10,
        'data_entrada' => '2026-05-23',
        'data_vencimento' => '2026-09-15',
        'contraparte' => 'Banco X',
        'taxa_contratada' => 5.20,
        'valor_nocional' => 1000000,
        'moeda_nocional' => 'USD',
    ])->assertCreated()->assertJsonPath('data.instrumento', 'NDF');

    $this->assertDatabaseHas('posicao_ndf', ['posicao_id' => $resposta->json('data.id')]);
    expect(Movimentacao::where('posicao_id', $resposta->json('data.id'))->count())->toBe(0);
});

it('cria OPCAO multi-perna (201) com mãe quantidade=1 (RN-004a/e)', function () {
    autenticaPosicoes();
    $produto = Produto::factory()->create();

    $resposta = $this->postJson('/api/v1/posicoes/opcao', [
        'produto_id' => $produto->id,
        'mercado' => 'BOLSA',
        'lado' => 'COMPRADO',
        'quantidade' => 99, // deve ser ignorado: a mãe é fixada em 1
        'data_entrada' => '2026-05-23',
        'data_vencimento' => '2026-09-15',
        'nome_estrutura' => 'Straddle',
        'pernas' => [
            ['tipo_opcao' => 'CALL', 'estilo' => 'EUROPEIA', 'strike' => 1450, 'premio_pago' => 30, 'quantidade' => 100, 'lado' => 'COMPRADO'],
            ['tipo_opcao' => 'PUT', 'estilo' => 'EUROPEIA', 'strike' => 1450, 'premio_pago' => 28, 'quantidade' => 100, 'lado' => 'COMPRADO'],
        ],
    ])->assertCreated()->assertJsonPath('data.instrumento', 'OPCAO');

    $posicaoId = $resposta->json('data.id');
    $this->assertDatabaseHas('posicao', ['id' => $posicaoId, 'quantidade' => 1]); // RN-004e
    expect(Perna::where('posicao_id', $posicaoId)->count())->toBe(2);
});

it('rejeita OPCAO sem pernas (422, RN-004a)', function () {
    autenticaPosicoes();
    $produto = Produto::factory()->create();

    $this->postJson('/api/v1/posicoes/opcao', [
        'produto_id' => $produto->id,
        'mercado' => 'BOLSA',
        'lado' => 'COMPRADO',
        'data_entrada' => '2026-05-23',
        'data_vencimento' => '2026-09-15',
        'pernas' => [],
    ])->assertStatus(422)->assertJsonValidationErrors(['pernas']);
});

it('cria OTC (201) quando indexador corresponde a produto (RN-006)', function () {
    autenticaPosicoes();
    $produto = Produto::factory()->create(['nome' => 'CEPEA_SOJA']);

    $this->postJson('/api/v1/posicoes/otc', [
        'produto_id' => $produto->id,
        'mercado' => 'BALCAO',
        'lado' => 'COMPRADO',
        'quantidade' => 50,
        'data_entrada' => '2026-05-23',
        'data_vencimento' => '2026-09-15',
        'contraparte' => 'Trading Y',
        'preco_entrada' => 130.00,
        'indexador' => 'CEPEA_SOJA',
        'premio_otc' => -2.5,
    ])->assertCreated()->assertJsonPath('data.instrumento', 'OTC');
});

it('rejeita OTC com indexador inexistente (422, RN-006 no Service)', function () {
    autenticaPosicoes();
    $produto = Produto::factory()->create(['nome' => 'CEPEA_SOJA']);

    $this->postJson('/api/v1/posicoes/otc', [
        'produto_id' => $produto->id,
        'mercado' => 'BALCAO',
        'lado' => 'COMPRADO',
        'quantidade' => 50,
        'data_entrada' => '2026-05-23',
        'data_vencimento' => '2026-09-15',
        'contraparte' => 'Trading Y',
        'preco_entrada' => 130.00,
        'indexador' => 'NAO_EXISTE',
    ])->assertStatus(422)->assertJsonPath('erro', 'ERRO_VALIDACAO');
});

it('valida RN-001 (quantidade>0), RN-002 (venc>entrada) e RN-003 (BALCAO exige contraparte)', function () {
    autenticaPosicoes();
    $produto = Produto::factory()->create();

    // RN-001 + RN-002
    $this->postJson('/api/v1/posicoes/futuro', payloadFuturo($produto->id, [
        'quantidade' => 0,
        'data_vencimento' => '2026-05-01', // antes da entrada
    ]))->assertStatus(422)->assertJsonValidationErrors(['quantidade', 'data_vencimento']);

    // RN-003: BALCAO sem contraparte
    $this->postJson('/api/v1/posicoes/futuro', payloadFuturo($produto->id, ['mercado' => 'BALCAO']))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['contraparte']);
});

it('lista posições paginadas com filtros de status e produto (§5.2.3, §9.1)', function () {
    autenticaPosicoes();
    $produtoA = Produto::factory()->create();
    $produtoB = Produto::factory()->create();

    $this->postJson('/api/v1/posicoes/futuro', payloadFuturo($produtoA->id));
    $this->postJson('/api/v1/posicoes/futuro', payloadFuturo($produtoB->id));

    $this->getJson('/api/v1/posicoes?status=ABERTA')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonStructure(['data', 'links', 'meta']);

    $this->getJson("/api/v1/posicoes?produto_id={$produtoA->id}")
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

it('detalha posição com dados do tipo (200)', function () {
    autenticaPosicoes();
    $produto = Produto::factory()->create();
    $id = $this->postJson('/api/v1/posicoes/futuro', payloadFuturo($produto->id))->json('data.id');

    $this->getJson("/api/v1/posicoes/{$id}")
        ->assertOk()
        ->assertJsonPath('data.instrumento', 'FUTURO')
        ->assertJsonPath('data.detalhe.codigo_contrato', 'ZSU24')
        ->assertJsonCount(1, 'data.movimentacoes');
});

it('detalhe de posição inexistente devolve 404 no envelope §5.1', function () {
    autenticaPosicoes();

    $this->getJson('/api/v1/posicoes/999999')
        ->assertStatus(404)
        ->assertJsonPath('erro', 'ERRO_NAO_ENCONTRADO');
});

it('exige autenticação (401 sem token)', function () {
    $this->getJson('/api/v1/posicoes')->assertUnauthorized();
});
