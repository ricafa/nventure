<?php

use App\Livewire\Produtos\FormProduto;
use App\Livewire\Produtos\ListaProdutos;
use App\Models\Produto;
use App\Models\Usuario;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(Usuario::factory()->create());
});

it('renderiza a listagem de produtos', function () {
    Produto::factory()->create(['nome' => 'Soja CBOT']);

    Livewire::test(ListaProdutos::class)
        ->assertOk()
        ->assertSee('Soja CBOT');
});

it('inativa um produto pela ação da lista', function () {
    $produto = Produto::factory()->create(['ativo' => true]);

    Livewire::test(ListaProdutos::class)->call('inativar', $produto->id);

    expect($produto->fresh()->ativo)->toBeFalse();
});

it('cria um produto pelo formulário', function () {
    Livewire::test(FormProduto::class)
        ->call('novo')
        ->set('nome', 'Café ICE')
        ->set('unidade', 'sc 60kg')
        ->set('bolsa_ref', 'ICE')
        ->set('moeda_cotacao', 'USD')
        ->call('salvar')
        ->assertHasNoErrors()
        ->assertDispatched('produtos-alterados');

    $this->assertDatabaseHas('produto', ['nome' => 'Café ICE']);
});

it('mostra erro amigável de nome duplicado no formulário', function () {
    Produto::factory()->create(['nome' => 'Milho B3']);

    Livewire::test(FormProduto::class)
        ->call('novo')
        ->set('nome', 'Milho B3')
        ->set('unidade', 'sc 60kg')
        ->set('bolsa_ref', 'B3')
        ->set('moeda_cotacao', 'BRL')
        ->call('salvar')
        ->assertHasErrors('nome');

    $this->assertDatabaseCount('produto', 1);
});
