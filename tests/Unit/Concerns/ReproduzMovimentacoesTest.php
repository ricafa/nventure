<?php

use App\Models\Concerns\ReproduzMovimentacoes;

/*
| Trait puro do replay (RN-021..024, §4.2): recebe a lista de tuplas e o $sinal, devolve
| [quantidadeAtual, precoMedio, plRealizado]. Sem Eloquent, sem banco (D-202/D-304). Cobre
| os ramos finos que o teste via Futuro não isola — em especial o DESEMPATE por id no mesmo
| dia (ponto 4 do parecer).
*/

$r = new class
{
    use ReproduzMovimentacoes;
};

it('PM ponderado após aumento; redução mantém PM e gera realizado', function () use ($r) {
    [$qtd, $pm, $real] = $r::reproduzir([
        ['id' => 1, 'tipo' => 'ABERTURA', 'data' => '2026-01-10', 'quantidade' => 100, 'preco' => 1400.0],
        ['id' => 2, 'tipo' => 'AUMENTO',  'data' => '2026-02-10', 'quantidade' => 50,  'preco' => 1430.0],
        ['id' => 3, 'tipo' => 'REDUCAO',  'data' => '2026-03-10', 'quantidade' => 50,  'preco' => 1440.0],
    ], 1);

    expect($pm)->toBe(1410.0)        // (100×1400 + 50×1430)/150
        ->and($qtd)->toBe(100.0)
        ->and($real)->toBe(1500.0);  // (1440−1410)×50×1
});

it('redução total zera a quantidade (encerramento é do Service — Fase 5)', function () use ($r) {
    [$qtd,, $real] = $r::reproduzir([
        ['id' => 1, 'tipo' => 'ABERTURA', 'data' => '2026-01-10', 'quantidade' => 100, 'preco' => 1400.0],
        ['id' => 2, 'tipo' => 'REDUCAO',  'data' => '2026-02-10', 'quantidade' => 100, 'preco' => 1410.0],
    ], 1);

    expect($qtd)->toBe(0.0)->and($real)->toBe(1000.0);   // (1410−1400)×100×1
});

it('posição vendida inverte o sinal do realizado', function () use ($r) {
    [$qtd,, $real] = $r::reproduzir([
        ['id' => 1, 'tipo' => 'ABERTURA', 'data' => '2026-01-10', 'quantidade' => 120, 'preco' => 320.0],
        ['id' => 2, 'tipo' => 'REDUCAO',  'data' => '2026-02-10', 'quantidade' => 30,  'preco' => 305.0],
    ], -1);

    expect($qtd)->toBe(90.0)->and($real)->toBe(450.0);   // (305−320)×30×(−1)
});

it('DESEMPATE no mesmo dia: id ordena AUMENTO antes de REDUCAO (resultado determinístico)', function () use ($r) {
    // ABERTURA 100@1000; no MESMO dia 2026-02-10: AUMENTO 100@1200 (id2) e REDUCAO 100@1100 (id3).
    // Ordem por id → AUMENTO primeiro: pm=(100×1000+100×1200)/200=1100; REDUCAO 100@1100 realiza 0.
    // Se a REDUCAO viesse antes (sem o id), realizaria (1100−1000)×100=10000 → resultado divergente.
    // A ordem de inserção é embaralhada de propósito (REDUCAO antes da ABERTURA) para forçar o usort.
    [$qtd, $pm, $real] = $r::reproduzir([
        ['id' => 3, 'tipo' => 'REDUCAO',  'data' => '2026-02-10', 'quantidade' => 100, 'preco' => 1100.0],
        ['id' => 1, 'tipo' => 'ABERTURA', 'data' => '2026-01-10', 'quantidade' => 100, 'preco' => 1000.0],
        ['id' => 2, 'tipo' => 'AUMENTO',  'data' => '2026-02-10', 'quantidade' => 100, 'preco' => 1200.0],
    ], 1);

    expect($pm)->toBe(1100.0)->and($qtd)->toBe(100.0)->and($real)->toBe(0.0);
});
