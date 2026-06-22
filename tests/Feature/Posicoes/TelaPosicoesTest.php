<?php

use App\Livewire\Posicoes\DetalhePosicao;
use App\Livewire\Posicoes\FormNovaPosicao;
use App\Livewire\Posicoes\ListaPosicoes;
use App\Models\Posicao;
use App\Models\Produto;
use App\Models\Usuario;
use App\Services\ServicoPosicoes;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(Usuario::factory()->create());
    $this->produto = Produto::factory()->create();
});

it('cadastra um FUTURO pela tela e redireciona para a listagem', function () {
    Livewire::test(FormNovaPosicao::class)
        ->set('tipo', 'FUTURO')
        ->set('produtoId', $this->produto->id)
        ->set('mercado', 'BOLSA')
        ->set('lado', 'COMPRADO')
        ->set('quantidade', '10')
        ->set('dataEntrada', '2026-05-01')
        ->set('dataVencimento', '2026-12-01')
        ->set('precoEntrada', '100')
        ->set('codigoContrato', 'ZSU24')
        ->call('salvar')
        ->assertHasNoErrors()
        ->assertRedirect(route('posicoes.index'));

    $this->assertDatabaseHas('posicao', ['produto_id' => $this->produto->id, 'instrumento' => 'FUTURO']);
});

it('lista posições e abre o detalhe por evento', function () {
    $id = app(ServicoPosicoes::class)->criarFuturo([
        'produto_id' => $this->produto->id, 'mercado' => 'BOLSA', 'lado' => 'COMPRADO',
        'quantidade' => 10, 'data_entrada' => '2026-05-01', 'data_vencimento' => '2026-12-01',
        'preco_entrada' => 100, 'codigo_contrato' => 'ZSU24',
    ])->id;

    Livewire::test(ListaPosicoes::class)
        ->assertSee($this->produto->nome); // a listagem mostra o nome do produto

    Livewire::test(DetalhePosicao::class)
        ->call('abrir', $id)
        ->assertSet('aberto', true)
        ->assertSee('FUTURO');
});

it('movimenta um FUTURO pela tela de detalhe', function () {
    $id = app(ServicoPosicoes::class)->criarFuturo([
        'produto_id' => $this->produto->id, 'mercado' => 'BOLSA', 'lado' => 'COMPRADO',
        'quantidade' => 10, 'data_entrada' => '2026-05-01', 'data_vencimento' => '2026-12-01',
        'preco_entrada' => 100, 'codigo_contrato' => 'ZSU24',
    ])->id;

    Livewire::test(DetalhePosicao::class)
        ->call('abrir', $id)
        ->set('tipo', 'AUMENTO')
        ->set('dataMovimentacao', '2026-06-01')
        ->set('quantidade', '10')
        ->set('preco', '120')
        ->call('movimentar')
        ->assertHasNoErrors();

    expect(Posicao::query()->find($id)->quantidade)->toEqual('20.0000');
});

it('mostra erro amigável ao reduzir além do saldo na tela', function () {
    $id = app(ServicoPosicoes::class)->criarFuturo([
        'produto_id' => $this->produto->id, 'mercado' => 'BOLSA', 'lado' => 'COMPRADO',
        'quantidade' => 10, 'data_entrada' => '2026-05-01', 'data_vencimento' => '2026-12-01',
        'preco_entrada' => 100, 'codigo_contrato' => 'ZSU24',
    ])->id;

    Livewire::test(DetalhePosicao::class)
        ->call('abrir', $id)
        ->set('tipo', 'REDUCAO')
        ->set('dataMovimentacao', '2026-06-01')
        ->set('quantidade', '99')
        ->set('preco', '120')
        ->call('movimentar')
        ->assertHasErrors('quantidade');
});
