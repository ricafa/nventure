<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\PrecoReferencia;
use App\Models\Produto;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/*
 * Cenário de aceite (Fase 7, DoD #8). Dois pregões processados pelo motor:
 *   FUTURO produtoB3 (BRL): COMPRADO qtd 10, preco_entrada 100
 *     d1 2026-06-18 preco 105 → mtm 50  · var 50  · pl_acum 50
 *     d2 2026-06-19 preco 108 → mtm 80  · var 30  · pl_acum 80
 *   NDF produtoCambiario (USD): COMPRADO nocional 5000, taxa 5.00, cambio 1
 *     d1 preco 5.20 → mtm 1000 · var 1000 · pl_acum 1000
 *     d2 preco 5.10 → mtm 500  · var -500 · pl_acum 500
 * Snapshot (último <= data) em 2026-06-20 = d2: FUTURO 80, NDF 500.
 */

beforeEach(function () {
    $this->usuario = Usuario::factory()->create();
    Sanctum::actingAs($this->usuario);

    $this->produtoB3 = Produto::factory()->create(['moeda_cotacao' => 'BRL', 'nome' => 'Milho B3']);
    $this->produtoCambiario = Produto::factory()->create(['moeda_cotacao' => 'USD', 'nome' => 'Dolar NDF']);
});

function novoFuturo(int $produtoId, int $qtd = 10, float $preco = 100, string $venc = '2026-12-31', string $lado = 'COMPRADO'): int
{
    $res = test()->postJson('/api/v1/posicoes/futuro', [
        'produto_id' => $produtoId,
        'mercado' => 'BOLSA',
        'lado' => $lado,
        'quantidade' => $qtd,
        'data_entrada' => '2026-05-01',
        'data_vencimento' => $venc,
        'preco_entrada' => $preco,
        'codigo_contrato' => 'ZSU24',
    ]);
    $res->assertStatus(201);

    return (int) $res->json('data.id');
}

function novoNdf(int $produtoId, float $nocional = 5000, float $taxa = 5.00, string $lado = 'COMPRADO'): int
{
    $res = test()->postJson('/api/v1/posicoes/ndf', [
        'produto_id' => $produtoId,
        'mercado' => 'BALCAO',
        'lado' => $lado,
        'quantidade' => 1000,           // campo base; a exposição usa o nocional (D-705)
        'data_entrada' => '2026-05-01',
        'data_vencimento' => '2026-12-31',
        'taxa_contratada' => $taxa,
        'contraparte' => 'BancoX',
        'fixing' => 'PTAX',
        'valor_nocional' => $nocional,
        'moeda_nocional' => 'USD',
    ]);
    $res->assertStatus(201);

    return (int) $res->json('data.id');
}

function preco(int $produtoId, string $data, float $fechamento, float $cambio = 1): void
{
    PrecoReferencia::factory()->create([
        'produto_id' => $produtoId,
        'data_preco' => $data,
        'preco_fechamento' => $fechamento,
        'cambio_brl' => $cambio,
    ]);
}

function rodarMotor(string $data): void
{
    test()->postJson('/api/v1/motor/processar', ['data_calculo' => $data])->assertOk();
}

/** Semeia os dois pregões do cenário de aceite e devolve os ids [futuro, ndf]. */
function semearCenario(): array
{
    $idFuturo = novoFuturo(test()->produtoB3->id);
    $idNdf = novoNdf(test()->produtoCambiario->id);

    preco(test()->produtoB3->id, '2026-06-18', 105);
    preco(test()->produtoCambiario->id, '2026-06-18', 5.20);
    rodarMotor('2026-06-18');

    preco(test()->produtoB3->id, '2026-06-19', 108);
    preco(test()->produtoCambiario->id, '2026-06-19', 5.10);
    rodarMotor('2026-06-19');

    return [$idFuturo, $idNdf];
}

it('RN-016: lista posicoes ABERTA com o ultimo MtM <= data e PM do FUTURO', function () {
    [$idFuturo, $idNdf] = semearCenario();

    // data após d2 → snapshot pega d2 (gap tolerance, D-702).
    $json = $this->getJson('/api/v1/relatorios/posicao-aberta?data=2026-06-20')->assertOk()->json();

    expect($json['data'])->toBe('2026-06-20')
        ->and((float) $json['total_mtm'])->toBe(580.0)
        ->and((float) $json['total_variacao'])->toBe(-470.0);

    $futuro = collect($json['posicoes'])->firstWhere('posicao_id', $idFuturo);
    expect((float) $futuro['mtm'])->toBe(80.0)
        ->and((float) $futuro['variacao_dia'])->toBe(30.0)
        ->and((float) $futuro['preco_medio'])->toBe(100.0)     // RN-016: PM só do FUTURO, via Model
        ->and((float) $futuro['preco_mercado'])->toBe(108.0)
        ->and($futuro['tem_mtm'])->toBeTrue();

    $ndf = collect($json['posicoes'])->firstWhere('posicao_id', $idNdf);
    expect((float) $ndf['mtm'])->toBe(500.0)
        ->and($ndf['preco_medio'])->toBeNull();         // NDF não tem PM
});

it('RN-016: posicao ABERTA sem nenhum MtM <= data aparece com tem_mtm=false', function () {
    $idFuturo = novoFuturo($this->produtoB3->id);
    preco($this->produtoB3->id, '2026-06-19', 108);
    rodarMotor('2026-06-19');

    // data ANTES do 1º processamento → sem MtM.
    $json = $this->getJson('/api/v1/relatorios/posicao-aberta?data=2026-06-10')->assertOk()->json();

    $futuro = collect($json['posicoes'])->firstWhere('posicao_id', $idFuturo);
    expect($futuro['tem_mtm'])->toBeFalse()
        ->and((float) $futuro['mtm'])->toBe(0.0)
        ->and($futuro['preco_mercado'])->toBeNull();
});

it('RN-017 e RN-018: P&L diario (data exata) e acumulado (snapshot das ABERTA)', function () {
    semearCenario();

    $json = $this->getJson('/api/v1/relatorios/pl-diario?data=2026-06-19')->assertOk()->json();

    expect((float) $json['pl_diario'])->toBe(-470.0)        // RN-017: Σ variacao_dia em 2026-06-19 (30 + -500)
        ->and((float) $json['pl_acumulado'])->toBe(580.0)   // RN-018: Σ pl_acumulado snapshot (80 + 500)
        ->and($json['serie'])->toHaveCount(2);

    // Série acumulada usa SUM(pl_acumulado) (D-704): último ponto = 580.
    $ultimo = end($json['serie']);
    expect($ultimo['data'])->toBe('2026-06-19')
        ->and((float) $ultimo['pl_acumulado'])->toBe(580.0)
        ->and((float) $ultimo['pl_dia'])->toBe(-470.0);
});

it('RN-019: exposicao por produto usa nocional no NDF (polimorfico) e base no FUTURO', function () {
    [, $idNdf] = semearCenario();

    $json = $this->getJson('/api/v1/relatorios/exposicao-liquida?data=2026-06-20')->assertOk()->json();

    $b3 = collect($json['produtos'])->firstWhere('produto_id', $this->produtoB3->id);
    expect((float) $b3['comprado'])->toBe(10.0)             // FUTURO: quantidade base
        ->and((float) $b3['liquido'])->toBe(10.0)
        ->and((float) $b3['mtm'])->toBe(80.0)
        ->and($b3['mix']['FUTURO'])->toBe(1)
        ->and($b3['unidade_mista'])->toBeFalse();

    $cam = collect($json['produtos'])->firstWhere('produto_id', $this->produtoCambiario->id);
    expect((float) $cam['comprado'])->toBe(5000.0)          // NDF: nocional (não o campo base 1000) — D-705
        ->and((float) $cam['liquido'])->toBe(5000.0)
        ->and((float) $cam['mtm'])->toBe(500.0)
        ->and($cam['mix']['NDF'])->toBe(1);
});

it('RN-019: liquido soma comprado e vendido com sinal por lado', function () {
    novoFuturo($this->produtoB3->id, qtd: 10, lado: 'COMPRADO');
    novoFuturo($this->produtoB3->id, qtd: 4, lado: 'VENDIDO');
    preco($this->produtoB3->id, '2026-06-19', 108);
    rodarMotor('2026-06-19');

    $json = $this->getJson('/api/v1/relatorios/exposicao-liquida?data=2026-06-19')->assertOk()->json();
    $b3 = collect($json['produtos'])->firstWhere('produto_id', $this->produtoB3->id);

    expect((float) $b3['comprado'])->toBe(10.0)
        ->and((float) $b3['vendido'])->toBe(4.0)
        ->and((float) $b3['liquido'])->toBe(6.0)
        ->and($b3['mix']['FUTURO'])->toBe(2);
});

it('D-705a: unidade_mista=true quando NDF soma com FUTURO/OTC no mesmo produto', function () {
    // FUTURO e NDF no MESMO produto → soma contratos com nocional (mismatch de unidade).
    novoFuturo($this->produtoB3->id);
    novoNdf($this->produtoB3->id);
    preco($this->produtoB3->id, '2026-06-19', 108, 1);
    rodarMotor('2026-06-19');

    $json = $this->getJson('/api/v1/relatorios/exposicao-liquida?data=2026-06-19')->assertOk()->json();
    $b3 = collect($json['produtos'])->firstWhere('produto_id', $this->produtoB3->id);

    expect($b3['unidade_mista'])->toBeTrue()
        ->and($b3['mix']['FUTURO'])->toBe(1)
        ->and($b3['mix']['NDF'])->toBe(1);
});

it('historico-mtm devolve a serie ordenada por data', function () {
    [$idFuturo] = semearCenario();

    $json = $this->getJson("/api/v1/relatorios/historico-mtm?posicao_id={$idFuturo}")->assertOk()->json();

    expect($json['posicao_id'])->toBe($idFuturo)
        ->and($json['pontos'])->toHaveCount(2)
        ->and($json['pontos'][0]['data_calculo'])->toBe('2026-06-18')
        ->and((float) $json['pontos'][0]['mtm_valor'])->toBe(50.0)
        ->and($json['pontos'][1]['data_calculo'])->toBe('2026-06-19')
        ->and((float) $json['pontos'][1]['mtm_valor'])->toBe(80.0);
});

it('historico-mtm sem posicao_id retorna 422 (entrada invalida, nao 404)', function () {
    $this->getJson('/api/v1/relatorios/historico-mtm')
        ->assertStatus(422)
        ->assertJsonValidationErrors('posicao_id');
});

it('historico-mtm de posicao inexistente retorna 404 no envelope §5.1', function () {
    $this->getJson('/api/v1/relatorios/historico-mtm?posicao_id=999999')
        ->assertStatus(404)
        ->assertJson(['erro' => 'ERRO_NAO_ENCONTRADO']);
});

it('formato=csv devolve download endurecido com cabecalho e sanitizacao CWE-1236', function () {
    $produtoPerigoso = Produto::factory()->create(['nome' => '=Soja Maliciosa', 'moeda_cotacao' => 'BRL']);
    novoFuturo($produtoPerigoso->id);
    preco($produtoPerigoso->id, '2026-06-19', 108);
    rodarMotor('2026-06-19');

    $response = $this->get('/api/v1/relatorios/posicao-aberta?data=2026-06-19&formato=csv');
    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toContain('text/csv');

    $conteudo = $response->streamedContent();
    expect($conteudo)->toContain('posicao_id')            // cabeçalho
        ->and($conteudo)->toContain("'=Soja Maliciosa");  // aspa neutraliza a fórmula (CWE-1236)
});

it('formato=pdf retorna 501 FORMATO_INDISPONIVEL (no contrato, ainda nao suportado)', function () {
    $this->getJson('/api/v1/relatorios/posicao-aberta?formato=pdf')
        ->assertStatus(501)
        ->assertJson(['erro' => 'FORMATO_INDISPONIVEL']);
});

it('data ausente usa hoje como default', function () {
    $hoje = now()->toDateString();
    $idFuturo = novoFuturo($this->produtoB3->id);
    preco($this->produtoB3->id, $hoje, 108);
    rodarMotor($hoje);

    $json = $this->getJson('/api/v1/relatorios/posicao-aberta')->assertOk()->json();

    expect($json['data'])->toBe($hoje)
        ->and(collect($json['posicoes'])->firstWhere('posicao_id', $idFuturo)['tem_mtm'])->toBeTrue();
});

it('formato invalido retorna 422', function () {
    $this->getJson('/api/v1/relatorios/posicao-aberta?formato=xml')
        ->assertStatus(422)
        ->assertJsonValidationErrors('formato');
});

it('exige autenticacao (401 sem token)', function () {
    $this->app['auth']->forgetGuards();

    $this->getJson('/api/v1/relatorios/posicao-aberta')->assertUnauthorized();
    $this->getJson('/api/v1/relatorios/historico-mtm?posicao_id=1')->assertUnauthorized();
});

it('telas Livewire renderizam com dados reais sob auth (D-708)', function () {
    semearCenario();
    $this->actingAs($this->usuario);

    foreach (['/dashboard', '/relatorios/posicao-aberta', '/relatorios/pl', '/relatorios/exposicao'] as $rota) {
        $this->get($rota)->assertOk();
    }
});

it('telas de relatorio exigem sessao web (auth)', function () {
    $this->app['auth']->forgetGuards();

    $this->get('/relatorios/posicao-aberta')->assertRedirect('/login');
});
