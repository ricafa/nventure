<?php

use App\Livewire\Precos\LancamentoPrecos;
use App\Models\PrecoReferencia;
use App\Models\Produto;
use App\Models\Usuario;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(Usuario::factory()->create());
});

it('lança um preço manualmente', function () {
    $produto = Produto::factory()->create();

    Livewire::test(LancamentoPrecos::class)
        ->set('produto_id', $produto->id)
        ->set('data_preco', '2026-05-23')
        ->set('preco_fechamento', '1450.50')
        ->set('cambio_brl', '5.12')
        ->call('lancar')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('preco_referencia', [
        'produto_id' => $produto->id,
        'data_preco' => '2026-05-23',
    ]);
});

it('importa CSV pela tela e expõe o relatório', function () {
    $produto = Produto::factory()->create();
    $conteudo = "produto_id,data_preco,preco_fechamento,cambio_brl\n"
        ."{$produto->id},2026-05-23,1450.50,5.12\n"
        ."abc,2026-05-23,1,1\n";

    $arquivo = UploadedFile::fake()->createWithContent('precos.csv', $conteudo);

    Livewire::test(LancamentoPrecos::class)
        ->set('arquivo', $arquivo)
        ->call('importar')
        ->assertSet('resultadoImportacao', fn ($r) => $r['aceitas'] === 1 && count($r['rejeitadas']) === 1);
});

it('remove um preço sem MtM', function () {
    $preco = PrecoReferencia::factory()->create();

    Livewire::test(LancamentoPrecos::class)
        ->call('remover', $preco->id)
        ->assertHasNoErrors();

    $this->assertDatabaseMissing('preco_referencia', ['id' => $preco->id]);
});
