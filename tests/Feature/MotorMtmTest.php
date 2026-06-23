<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\MotorExecucao;
use App\Models\MtmDiario;
use App\Models\Posicao;
use App\Models\PrecoReferencia;
use App\Models\Produto;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->produtoCambiario = Produto::factory()->create([
        'moeda_cotacao' => 'USD',
    ]);

    $this->produtoB3 = Produto::factory()->create([
        'moeda_cotacao' => 'BRL',
    ]);

    $this->usuario = Usuario::factory()->create();
    Sanctum::actingAs($this->usuario);
});

function criarFuturo($produtoId, $vencimento, $preco = 100, $qtd = 10)
{
    $res = test()->postJson('/api/v1/posicoes/futuro', [
        'produto_id' => $produtoId,
        'mercado' => 'BOLSA',
        'lado' => 'COMPRADO',
        'quantidade' => $qtd,
        'data_entrada' => '2026-05-01',
        'data_vencimento' => $vencimento,
        'preco_entrada' => $preco,
        'codigo_contrato' => 'ZSU24',
    ]);
    $res->assertStatus(201);

    return $res->json('data.id');
}

function criarNdf($produtoId, $vencimento, $taxa = 5.00, $qtd = 1000)
{
    $res = test()->postJson('/api/v1/posicoes/ndf', [
        'produto_id' => $produtoId,
        'mercado' => 'BALCAO',
        'lado' => 'COMPRADO',
        'quantidade' => $qtd,
        'data_entrada' => '2026-05-01',
        'data_vencimento' => $vencimento,
        'taxa_contratada' => $taxa,
        'contraparte' => 'B',
        'fixing' => 'PTAX',
        'valor_nocional' => 5000,
        'moeda_nocional' => 'USD',
    ]);
    $res->assertStatus(201);

    return $res->json('data.id');
}

function criarOtc($produtoId, $nomeProduto, $vencimento)
{
    $res = test()->postJson('/api/v1/posicoes/otc', [
        'produto_id' => $produtoId,
        'mercado' => 'BALCAO',
        'lado' => 'COMPRADO',
        'quantidade' => 10,
        'data_entrada' => '2026-05-01',
        'data_vencimento' => $vencimento,
        'preco_entrada' => 100,
        'contraparte' => 'B',
        'indexador' => $nomeProduto,
    ]);
    $res->assertStatus(201);

    return $res->json('data.id');
}

it('calcula MtM de posicoes ABERTA polimorficamente e isola falhas por falta de preco', function () {
    $hoje = '2026-06-20';
    $vencimento = '2026-06-30';

    $idFuturo = criarFuturo($this->produtoB3->id, $vencimento);
    $idNdf = criarNdf($this->produtoCambiario->id, $vencimento);

    // Posição sem preço (vai falhar isoladamente)
    $prodSemPreco = Produto::factory()->create();
    $idSemPreco = criarOtc($prodSemPreco->id, $prodSemPreco->nome, $vencimento);

    // Preços
    PrecoReferencia::factory()->create([
        'produto_id' => $this->produtoB3->id,
        'data_preco' => $hoje,
        'preco_fechamento' => 105,
        'cambio_brl' => 1,
    ]);

    PrecoReferencia::factory()->create([
        'produto_id' => $this->produtoCambiario->id,
        'data_preco' => $hoje,
        'preco_fechamento' => 5.20,
        'cambio_brl' => 1, // D-607
    ]);

    $response = $this->postJson('/api/v1/motor/processar', [
        'data_calculo' => $hoje,
    ]);

    $response->assertOk();
    $json = $response->json();

    expect($json['posicoes_processadas'])->toBe(3)
        ->and($json['sucessos'])->toBe(2)
        ->and($json['falhas'])->toHaveCount(1)
        ->and($json['falhas'][0]['posicao_id'])->toBe($idSemPreco)
        ->and($json['falhas'][0]['motivo'])->toBe('Preço não cadastrado para a data');

    // Mtm Diario checks
    $mtmFuturo = MtmDiario::where('posicao_id', $idFuturo)->where('data_calculo', $hoje)->first();
    expect($mtmFuturo)->not->toBeNull()
        ->and((float) $mtmFuturo->mtm_valor)->toBe(50.0); // (105-100)*10

    $mtmNdf = MtmDiario::where('posicao_id', $idNdf)->where('data_calculo', $hoje)->first();
    expect($mtmNdf)->not->toBeNull()
        ->and((float) $mtmNdf->mtm_valor)->toBe(1000.0); // (5.20-5.00)*5000

    // Auditoria
    $execucao = MotorExecucao::find($json['execucao_id']);
    expect($execucao)->not->toBeNull()
        ->and($execucao->disparado_por)->toBe($this->usuario->login)
        ->and((int) $execucao->total_posicoes)->toBe(3);
});

it('nao duplica em reprocessamento e preserva autoria quando valor eh o mesmo', function () {
    $hoje = '2026-06-20';
    $id = criarFuturo($this->produtoB3->id, '2026-06-30');

    PrecoReferencia::factory()->create([
        'produto_id' => $this->produtoB3->id,
        'data_preco' => $hoje,
        'preco_fechamento' => 105,
        'cambio_brl' => 1,
    ]);

    $this->postJson('/api/v1/motor/processar', ['data_calculo' => $hoje]);
    $exec1 = MotorExecucao::first();
    $mtm1 = MtmDiario::where('posicao_id', $id)->first();

    $this->postJson('/api/v1/motor/processar', ['data_calculo' => $hoje]);
    $exec2 = MotorExecucao::orderByDesc('id')->first();

    $mtm2 = MtmDiario::where('posicao_id', $id)->first();

    expect(MtmDiario::where('posicao_id', $id)->count())->toBe(1)
        ->and($mtm2->execucao_id)->toBe($mtm1->execucao_id)
        ->and($exec1->id)->not->toBe($exec2->id);
});

it('altera autoria em reprocessamento se o preco mudou', function () {
    $hoje = '2026-06-20';
    $id = criarFuturo($this->produtoB3->id, '2026-06-30');

    $preco = PrecoReferencia::factory()->create([
        'produto_id' => $this->produtoB3->id,
        'data_preco' => $hoje,
        'preco_fechamento' => 105,
        'cambio_brl' => 1,
    ]);

    $this->postJson('/api/v1/motor/processar', ['data_calculo' => $hoje]);

    $preco->update(['preco_fechamento' => 110]);

    $this->postJson('/api/v1/motor/processar', ['data_calculo' => $hoje]);
    $exec2 = MotorExecucao::orderByDesc('id')->first();

    $mtm = MtmDiario::where('posicao_id', $id)->first();

    expect(MtmDiario::where('posicao_id', $id)->count())->toBe(1)
        ->and($mtm->execucao_id)->toBe($exec2->id)
        ->and((float) $mtm->mtm_valor)->toBe(100.0);
});

it('marca posicao como VENCIDA quando vencimento <= data e ignora no dia seguinte', function () {
    $hoje = '2026-06-20';
    $id = criarFuturo($this->produtoB3->id, '2026-06-20'); // Vence hoje!

    PrecoReferencia::factory()->create([
        'produto_id' => $this->produtoB3->id,
        'data_preco' => $hoje,
        'preco_fechamento' => 105,
        'cambio_brl' => 1,
    ]);
    PrecoReferencia::factory()->create([
        'produto_id' => $this->produtoB3->id,
        'data_preco' => '2026-06-21',
        'preco_fechamento' => 110,
        'cambio_brl' => 1,
    ]);

    $this->postJson('/api/v1/motor/processar', ['data_calculo' => $hoje])->assertOk();

    expect(Posicao::find($id)->status)->toBe('VENCIDA')
        ->and(MtmDiario::where('data_calculo', $hoje)->count())->toBe(1);

    $resAmanha = $this->postJson('/api/v1/motor/processar', ['data_calculo' => '2026-06-21']);

    expect($resAmanha->json('posicoes_processadas'))->toBe(0)
        ->and(MtmDiario::where('data_calculo', '2026-06-21')->count())->toBe(0);
});

it('roda command via console', function () {
    Artisan::call('motor:processar', ['--data' => '2026-06-20']);

    $output = Artisan::output();
    expect($output)->toContain('Motor #');

    $execucao = MotorExecucao::orderByDesc('id')->first();
    expect($execucao->disparado_por)->toBe('agendador');
});
