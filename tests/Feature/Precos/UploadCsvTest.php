<?php

use App\Models\Produto;
use App\Models\Usuario;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Sanctum::actingAs(Usuario::factory()->create());
});

it('importa CSV com linhas mistas (200 + relatório RN-010)', function () {
    $produto = Produto::factory()->create();

    $conteudo = "produto_id,data_preco,preco_fechamento,cambio_brl\n"
        ."{$produto->id},2026-05-23,1450.50,5.12\n"   // ok
        ."{$produto->id},2026-05-23,1460.00,5.13\n"   // duplicata (RN-007) → rejeitada
        ."999,2026-05-24,10.00,5.00\n"                // produto inexistente → rejeitada
        ."{$produto->id},2026-05-25,0,5.00\n";        // preço <= 0 (RN-008) → rejeitada

    $arquivo = UploadedFile::fake()->createWithContent('precos.csv', $conteudo);

    $this->post('/api/v1/precos/upload', ['arquivo' => $arquivo])
        ->assertOk()
        ->assertJsonPath('total', 4)
        ->assertJsonPath('aceitas', 1)
        ->assertJsonCount(3, 'rejeitadas');

    $this->assertDatabaseCount('preco_referencia', 1);
});

it('cabeçalho inválido vira lote 0 aceitas (200)', function () {
    $conteudo = "foo,bar,baz,qux\n1,2026-05-23,1450.50,5.12\n";
    $arquivo = UploadedFile::fake()->createWithContent('precos.csv', $conteudo);

    $this->post('/api/v1/precos/upload', ['arquivo' => $arquivo])
        ->assertOk()
        ->assertJsonPath('aceitas', 0)
        ->assertJsonPath('rejeitadas.0.motivo', fn ($m) => str_contains((string) $m, 'Cabeçalho'));
});
