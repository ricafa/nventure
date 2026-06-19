<?php

use App\Models\Futuro;
use App\Models\Movimentacao;
use App\Models\Ndf;
use App\Models\Opcao;
use App\Models\Otc;
use App\Models\Perna;
use App\Models\Posicao;
use App\Models\PosicaoNdf;
use App\Models\PosicaoOtc;
use Tests\TestCase;

/*
| Fórmulas do fat model "congeladas" (§8.1 — golden values idênticos via toBe). Com
| preventLazyLoading LIGADO (D-206), instanciar por make()/setRelation e chamar o cálculo
| NÃO toca o banco: acesso a relação não carregada estouraria. Nenhum teste aqui usa
| RefreshDatabase/conexão — é a garantia executável de pureza (D-201/D-301).
*/
uses(TestCase::class);

// ─────────────────────────────────────────────────────────────────────────────
// 4.3 — matriz por instrumento (§8.1, item 1): comprado/vendido × a favor/contra
// ─────────────────────────────────────────────────────────────────────────────

dataset('futuro_quadrantes', [
    // lado,            preco_merc, esperado          (pm 1400, qtd 100)
    'comprado a favor' => ['COMPRADO', 1450.0, 5000.0],   // (1450−1400)×100×(+1)
    'comprado contra' => ['COMPRADO', 1350.0, -5000.0],
    'vendido a favor' => ['VENDIDO', 1350.0, 5000.0],      // (1350−1400)×100×(−1)
    'vendido contra' => ['VENDIDO', 1450.0, -5000.0],
]);

it('Futuro: calcularMtm nos 4 quadrantes', function (string $lado, float $merc, float $esp) {
    $f = Futuro::make(['lado' => $lado]);
    $f->setRelation('movimentacoes', collect([
        Movimentacao::make(['id' => 1, 'tipo' => 'ABERTURA', 'data_movimentacao' => '2026-01-10', 'quantidade' => 100, 'preco' => 1400.0]),
    ]));

    expect($f->calcularMtm($merc))->toBe($esp);
})->with('futuro_quadrantes');

dataset('ndf_quadrantes', [
    'comprado a favor' => ['COMPRADO', 5.50, 50000.0],   // (5.50−5.00)×100000×(+1)
    'comprado contra' => ['COMPRADO', 4.50, -50000.0],
    'vendido a favor' => ['VENDIDO', 4.50, 50000.0],
    'vendido contra' => ['VENDIDO', 5.50, -50000.0],
]);

it('Ndf: calcularMtm nos 4 quadrantes', function (string $lado, float $merc, float $esp) {
    $n = Ndf::make(['lado' => $lado]);
    $n->setRelation('ndf', PosicaoNdf::make(['taxa_contratada' => 5.00, 'valor_nocional' => 100000]));

    expect($n->calcularMtm($merc))->toBe($esp);
})->with('ndf_quadrantes');

dataset('otc_quadrantes', [
    'comprado a favor' => ['COMPRADO', 1450.0, 0.0, 5000.0],
    'comprado contra' => ['COMPRADO', 1350.0, 0.0, -5000.0],
    'vendido a favor' => ['VENDIDO', 1350.0, 0.0, 5000.0],
    'vendido contra' => ['VENDIDO', 1450.0, 0.0, -5000.0],
]);

it('Otc: calcularMtm nos 4 quadrantes', function (string $lado, float $merc, float $premio, float $esp) {
    $o = Otc::make(['lado' => $lado, 'quantidade' => 100]);
    $o->setRelation('otc', PosicaoOtc::make(['preco_entrada' => 1400.0, 'premio_otc' => $premio]));

    expect($o->calcularMtm($merc))->toBe($esp);
})->with('otc_quadrantes');

it('Otc: o prêmio entra no preço efetivo', function () {
    $o = Otc::make(['lado' => 'COMPRADO', 'quantidade' => 100]);
    $o->setRelation('otc', PosicaoOtc::make(['preco_entrada' => 1400.0, 'premio_otc' => 10.0]));

    expect($o->calcularMtm(1450.0))->toBe(6000.0);   // (1450+10−1400)×100×1
});

// ─────────────────────────────────────────────────────────────────────────────
// 4.3 — OPCAO de perna única: CALL/PUT × comprada/vendida × ITM/OTM (Perna::calcularMtm)
// ─────────────────────────────────────────────────────────────────────────────

function opcaoUmaPerna(string $tipo, float $strike, float $premio, string $ladoPerna): Opcao
{
    $op = Opcao::make(['lado' => 'COMPRADO']);   // lado da mãe é informativo (RN-004e)
    $op->setRelation('pernas', collect([
        Perna::make(['tipo_opcao' => $tipo, 'strike' => $strike, 'premio_pago' => $premio, 'quantidade' => 100, 'lado' => $ladoPerna]),
    ]));

    return $op;
}

it('Opcao 1 perna: CALL comprada ITM', fn () => expect(opcaoUmaPerna('CALL', 1450, 30, 'COMPRADO')->calcularMtm(1500.0))->toBe(2000.0));  // (50−30)×100×1
it('Opcao 1 perna: CALL comprada OTM', fn () => expect(opcaoUmaPerna('CALL', 1450, 30, 'COMPRADO')->calcularMtm(1400.0))->toBe(-3000.0)); // (0−30)×100×1
it('Opcao 1 perna: PUT comprada ITM', fn () => expect(opcaoUmaPerna('PUT', 1450, 28, 'COMPRADO')->calcularMtm(1400.0))->toBe(2200.0));    // (50−28)×100×1
it('Opcao 1 perna: PUT comprada OTM', fn () => expect(opcaoUmaPerna('PUT', 1450, 28, 'COMPRADO')->calcularMtm(1500.0))->toBe(-2800.0));   // (0−28)×100×1
it('Opcao 1 perna: CALL vendida ITM', fn () => expect(opcaoUmaPerna('CALL', 1450, 30, 'VENDIDO')->calcularMtm(1500.0))->toBe(-2000.0));   // (50−30)×100×(−1)
it('Opcao 1 perna: PUT vendida OTM', fn () => expect(opcaoUmaPerna('PUT', 1450, 28, 'VENDIDO')->calcularMtm(1500.0))->toBe(2800.0));      // (0−28)×100×(−1)

// ─────────────────────────────────────────────────────────────────────────────
// 4.4 — estruturas multi-perna como Σ Perna (sem if por estrutura; aritmética no comentário)
// ─────────────────────────────────────────────────────────────────────────────

/** Monta uma Opcao a partir de pernas [tipo, strike, premio, qtd, lado]. */
function opcao(array $pernas): Opcao
{
    $op = Opcao::make(['lado' => 'COMPRADO']);
    $op->setRelation('pernas', collect(array_map(fn ($p) => Perna::make([
        'tipo_opcao' => $p[0], 'strike' => $p[1], 'premio_pago' => $p[2], 'quantidade' => $p[3], 'lado' => $p[4],
    ]), $pernas)));

    return $op;
}

it('straddle com mercado acima do strike (§8.1)', function () {
    // CALL 1450 c/30 comprada + PUT 1450 c/28 comprada @1500
    // (50−30)×100 + (0−28)×100 = 2000 − 2800 = −800
    expect(opcao([
        ['CALL', 1450, 30, 100, 'COMPRADO'],
        ['PUT', 1450, 28, 100, 'COMPRADO'],
    ])->calcularMtm(1500.0))->toBe(-800.0);
});

it('bull call spread entre os strikes (§8.1)', function () {
    // CALL 1400 c/60 comprada + CALL 1450 c/30 vendida @1500
    // (100−60)×100×1 + (50−30)×100×(−1) = 4000 − 2000 = 2000
    expect(opcao([
        ['CALL', 1400, 60, 100, 'COMPRADO'],
        ['CALL', 1450, 30, 100, 'VENDIDO'],
    ])->calcularMtm(1500.0))->toBe(2000.0);
});

it('strangle (CALL OTM + PUT OTM, ambas compradas) @1550', function () {
    // CALL 1500 c/20: (50−20)×100×1 = 3000 ; PUT 1400 c/18: (0−18)×100×1 = −1800 → 1200
    expect(opcao([
        ['CALL', 1500, 20, 100, 'COMPRADO'],
        ['PUT', 1400, 18, 100, 'COMPRADO'],
    ])->calcularMtm(1550.0))->toBe(1200.0);
});

it('collar (PUT comprada + CALL vendida) @1450', function () {
    // PUT 1400 c/18 comprada: (0−18)×100×1 = −1800 ; CALL 1500 c/20 vendida: (0−20)×100×(−1) = 2000 → 200
    expect(opcao([
        ['PUT', 1400, 18, 100, 'COMPRADO'],
        ['CALL', 1500, 20, 100, 'VENDIDO'],
    ])->calcularMtm(1450.0))->toBe(200.0);
});

it('bear put spread (PUT alta comprada + PUT baixa vendida) @1420', function () {
    // PUT 1450 c/40 comprada: (30−40)×100×1 = −1000 ; PUT 1400 c/20 vendida: (0−20)×100×(−1) = 2000 → 1000
    expect(opcao([
        ['PUT', 1450, 40, 100, 'COMPRADO'],
        ['PUT', 1400, 20, 100, 'VENDIDO'],
    ])->calcularMtm(1420.0))->toBe(1000.0);
});

it('butterfly (long call: +1 1400, −2 1450, +1 1500) @1450', function () {
    // CALL 1400 c/60 comprada 100: (50−60)×100×1 = −1000
    // CALL 1450 c/30 vendida 200: (0−30)×200×(−1) = 6000
    // CALL 1500 c/15 comprada 100: (0−15)×100×1 = −1500 → 3500
    expect(opcao([
        ['CALL', 1400, 60, 100, 'COMPRADO'],
        ['CALL', 1450, 30, 200, 'VENDIDO'],
        ['CALL', 1500, 15, 100, 'COMPRADO'],
    ])->calcularMtm(1450.0))->toBe(3500.0);
});

// ─────────────────────────────────────────────────────────────────────────────
// 4.5 — Futuro com movimentações (replay, §8.1 item 7): integração trait↔Model
// ─────────────────────────────────────────────────────────────────────────────

it('preço médio após aumento (§8.1)', function () {
    $f = Futuro::make(['lado' => 'COMPRADO']);
    $f->setRelation('movimentacoes', collect([
        Movimentacao::make(['id' => 1, 'tipo' => 'ABERTURA', 'data_movimentacao' => '2026-01-10', 'quantidade' => 100, 'preco' => 1400.0]),
        Movimentacao::make(['id' => 2, 'tipo' => 'AUMENTO', 'data_movimentacao' => '2026-02-10', 'quantidade' => 50, 'preco' => 1430.0]),
    ]));

    expect($f->precoMedio())->toBe(1410.0)
        ->and($f->quantidadeAtual())->toBe(150.0)
        ->and($f->plRealizado())->toBe(0.0)
        ->and($f->calcularMtm(1450.0))->toBe(6000.0);   // (1450−1410)×150
});

it('redução mantém o PM e gera realizado (§8.1)', function () {
    $f = Futuro::make(['lado' => 'COMPRADO']);
    $f->setRelation('movimentacoes', collect([
        Movimentacao::make(['id' => 1, 'tipo' => 'ABERTURA', 'data_movimentacao' => '2026-01-10', 'quantidade' => 100, 'preco' => 1400.0]),
        Movimentacao::make(['id' => 2, 'tipo' => 'AUMENTO', 'data_movimentacao' => '2026-02-10', 'quantidade' => 50, 'preco' => 1430.0]),
        Movimentacao::make(['id' => 3, 'tipo' => 'REDUCAO', 'data_movimentacao' => '2026-03-10', 'quantidade' => 50, 'preco' => 1440.0]),
    ]));

    expect($f->precoMedio())->toBe(1410.0)
        ->and($f->quantidadeAtual())->toBe(100.0)
        ->and($f->plRealizado())->toBe(1500.0);   // (1440−1410)×50×1
});

it('redução em posição vendida inverte o sinal do realizado (§8.1)', function () {
    $f = Futuro::make(['lado' => 'VENDIDO']);
    $f->setRelation('movimentacoes', collect([
        Movimentacao::make(['id' => 1, 'tipo' => 'ABERTURA', 'data_movimentacao' => '2026-01-10', 'quantidade' => 120, 'preco' => 320.0]),
        Movimentacao::make(['id' => 2, 'tipo' => 'REDUCAO', 'data_movimentacao' => '2026-02-10', 'quantidade' => 30, 'preco' => 305.0]),
    ]));

    expect($f->quantidadeAtual())->toBe(90.0)
        ->and($f->plRealizado())->toBe(450.0);   // (305−320)×30×(−1)
});

// ─────────────────────────────────────────────────────────────────────────────
// 4.6 — sinal() base e Perna (§8.1 item 2) + fail-fast + base sem instrumento
// ─────────────────────────────────────────────────────────────────────────────

it('sinal: COMPRADO=+1, VENDIDO=−1 na base e na perna', function () {
    expect(Futuro::make(['lado' => 'COMPRADO'])->sinal())->toBe(1)
        ->and(Ndf::make(['lado' => 'VENDIDO'])->sinal())->toBe(-1)
        ->and(Perna::make(['lado' => 'COMPRADO'])->sinal())->toBe(1)
        ->and(Perna::make(['lado' => 'VENDIDO'])->sinal())->toBe(-1);
});

it('sinal: fail-fast (DomainException) em lado inválido — base e perna', function () {
    expect(fn () => Futuro::make(['lado' => 'XPTO'])->sinal())->toThrow(DomainException::class);
    expect(fn () => Perna::make(['lado' => ''])->sinal())->toThrow(DomainException::class);
});

it('Posicao base: calcularMtm sem instrumento lança LogicException (D-204)', function () {
    expect(fn () => (new Posicao(['lado' => 'COMPRADO']))->calcularMtm(100.0))
        ->toThrow(LogicException::class);
});
