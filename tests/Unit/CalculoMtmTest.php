<?php

use App\Models\Futuro;
use App\Models\Movimentacao;
use App\Models\Ndf;
use App\Models\Opcao;
use App\Models\Otc;
use App\Models\Perna;
use App\Models\PosicaoNdf;
use App\Models\PosicaoOtc;
use Tests\TestCase;

/*
| DoD #2 da Fase 2 (§7.2): com preventLazyLoading LIGADO (AppServiceProvider::boot),
| instanciar os Models via make()/setRelation e chamar o cálculo NÃO toca o banco —
| acesso a relação não carregada estouraria. Cada subclasse confere um caso do §8.1
| (a validação ampla das fórmulas fica na Fase 3).
|
| Precisa do app Laravel bootado (resolver/casts do Eloquent), mas SEM RefreshDatabase:
| nenhum teste de cálculo abre conexão — é a garantia de pureza (D-201).
*/
uses(TestCase::class);

it('Futuro: preço médio ponderado, quantidade e MtM após aumento (§8.1)', function () {
    $futuro = Futuro::make(['lado' => 'COMPRADO']);
    $futuro->setRelation('movimentacoes', collect([
        Movimentacao::make(['id' => 1, 'tipo' => 'ABERTURA', 'data_movimentacao' => '2026-01-10', 'quantidade' => 100, 'preco' => 1400.00]),
        Movimentacao::make(['id' => 2, 'tipo' => 'AUMENTO', 'data_movimentacao' => '2026-02-10', 'quantidade' => 50, 'preco' => 1430.00]),
    ]));

    expect($futuro->precoMedio())->toBe(1410.00)
        ->and($futuro->quantidadeAtual())->toBe(150.0)
        ->and($futuro->plRealizado())->toBe(0.0)
        ->and($futuro->calcularMtm(1450.00))->toBe(6000.00);
});

it('Futuro vendido: redução mantém o PM e inverte o sinal do realizado (§8.1)', function () {
    $futuro = Futuro::make(['lado' => 'VENDIDO']);
    $futuro->setRelation('movimentacoes', collect([
        Movimentacao::make(['id' => 1, 'tipo' => 'ABERTURA', 'data_movimentacao' => '2026-01-10', 'quantidade' => 120, 'preco' => 320.00]),
        Movimentacao::make(['id' => 2, 'tipo' => 'REDUCAO', 'data_movimentacao' => '2026-02-10', 'quantidade' => 30, 'preco' => 305.00]),
    ]));

    expect($futuro->quantidadeAtual())->toBe(90.0)
        ->and($futuro->plRealizado())->toBe(450.00);   // (305−320)×30×(−1)
});

it('Ndf: (taxa mercado − contratada) × nocional × sinal', function () {
    $ndf = Ndf::make(['lado' => 'COMPRADO']);
    $ndf->setRelation('ndf', PosicaoNdf::make(['taxa_contratada' => 5.00, 'valor_nocional' => 100000]));

    expect($ndf->calcularMtm(5.50))->toBe(50000.00);
});

it('Opcao: straddle com mercado acima do strike soma as pernas (§8.1)', function () {
    $opcao = Opcao::make(['lado' => 'COMPRADO']);
    $opcao->setRelation('pernas', collect([
        Perna::make(['tipo_opcao' => 'CALL', 'strike' => 1450.00, 'premio_pago' => 30.00, 'quantidade' => 100, 'lado' => 'COMPRADO']),
        Perna::make(['tipo_opcao' => 'PUT', 'strike' => 1450.00, 'premio_pago' => 28.00, 'quantidade' => 100, 'lado' => 'COMPRADO']),
    ]));

    expect($opcao->calcularMtm(1500.00))->toBe(-800.00);
});

it('Otc: (preço efetivo − entrada) × quantidade × sinal', function () {
    $otc = Otc::make(['lado' => 'COMPRADO', 'quantidade' => 100]);
    $otc->setRelation('otc', PosicaoOtc::make(['preco_entrada' => 1400.00, 'premio_otc' => 0]));

    expect($otc->calcularMtm(1450.00))->toBe(5000.00);
});

it('sinal: COMPRADO=1, VENDIDO=-1 e fail-fast em valor inválido', function () {
    expect(Futuro::make(['lado' => 'COMPRADO'])->sinal())->toBe(1)
        ->and(Ndf::make(['lado' => 'VENDIDO'])->sinal())->toBe(-1)
        ->and(Perna::make(['lado' => 'VENDIDO'])->sinal())->toBe(-1);

    expect(fn () => Futuro::make(['lado' => 'XPTO'])->sinal())->toThrow(DomainException::class);
});
