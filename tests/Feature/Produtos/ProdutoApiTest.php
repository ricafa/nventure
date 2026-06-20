<?php

use App\Models\Produto;
use App\Models\Usuario;
use Laravel\Sanctum\Sanctum;

function autenticaApi(): void
{
    Sanctum::actingAs(Usuario::factory()->create());
}

it('cria um produto (201)', function () {
    autenticaApi();

    $this->postJson('/api/v1/produtos', [
        'nome' => 'Soja CBOT',
        'unidade' => 'bushel',
        'bolsa_ref' => 'CBOT',
        'moeda_cotacao' => 'USD',
    ])
        ->assertCreated()
        ->assertJsonPath('data.nome', 'Soja CBOT')
        ->assertJsonPath('data.ativo', true);

    $this->assertDatabaseHas('produto', ['nome' => 'Soja CBOT', 'ativo' => true]);
});

it('rejeita nome duplicado com 409 no envelope §5.1', function () {
    autenticaApi();
    Produto::factory()->create(['nome' => 'Milho B3']);

    $this->postJson('/api/v1/produtos', [
        'nome' => 'Milho B3',
        'unidade' => 'sc 60kg',
        'bolsa_ref' => 'B3',
        'moeda_cotacao' => 'BRL',
    ])
        ->assertStatus(409)
        ->assertJsonPath('erro', 'ERRO_CONFLITO');
});

it('valida estrutura no store (422 nativo)', function () {
    autenticaApi();

    $this->postJson('/api/v1/produtos', ['nome' => ''])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['nome', 'unidade', 'bolsa_ref', 'moeda_cotacao']);
});

it('lista produtos', function () {
    autenticaApi();
    Produto::factory()->create(['nome' => 'Café ICE']);
    Produto::factory()->create(['nome' => 'Açúcar ICE']);

    $this->getJson('/api/v1/produtos')
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

it('filtra apenas ativos', function () {
    autenticaApi();
    Produto::factory()->create(['nome' => 'Ativo A']);
    Produto::factory()->inativo()->create(['nome' => 'Inativo B']);

    $this->getJson('/api/v1/produtos?apenas_ativos=1')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.nome', 'Ativo A');
});

it('retorna 404 para produto inexistente', function () {
    autenticaApi();

    $this->getJson('/api/v1/produtos/999')
        ->assertStatus(404)
        ->assertJsonPath('erro', 'ERRO_NAO_ENCONTRADO');
});

it('atualiza por PATCH-merge preservando campos ausentes', function () {
    autenticaApi();
    $produto = Produto::factory()->create(['nome' => 'Antigo', 'unidade' => 'ton']);

    $this->putJson("/api/v1/produtos/{$produto->id}", ['nome' => 'Novo'])
        ->assertOk()
        ->assertJsonPath('data.nome', 'Novo')
        ->assertJsonPath('data.unidade', 'ton');
});

it('inativa (soft delete) sem remover o registro', function () {
    autenticaApi();
    $produto = Produto::factory()->create(['ativo' => true]);

    $this->deleteJson("/api/v1/produtos/{$produto->id}")
        ->assertNoContent();

    $this->assertDatabaseHas('produto', ['id' => $produto->id, 'ativo' => false]);
});

it('exige autenticação (sem token → 401)', function () {
    $this->getJson('/api/v1/produtos')->assertUnauthorized();
});
