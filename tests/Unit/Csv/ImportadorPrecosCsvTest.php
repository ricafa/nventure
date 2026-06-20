<?php

use App\Support\Csv\ImportadorPrecosCsv;

/**
 * Segurança/parsing do importador CSV (D-407/D-411) — **sem banco**.
 */

/** Escreve um CSV temporário e devolve o caminho. */
function csvTemporario(string $conteudo): string
{
    $caminho = tempnam(sys_get_temp_dir(), 'csv_');
    file_put_contents($caminho, $conteudo);

    return $caminho;
}

/** @return list<array<string, mixed>> */
function lerCsv(string $conteudo): array
{
    $caminho = csvTemporario($conteudo);
    try {
        return iterator_to_array((new ImportadorPrecosCsv($caminho))->ler(), false);
    } finally {
        @unlink($caminho);
    }
}

$cabecalho = "produto_id,data_preco,preco_fechamento,cambio_brl\n";

it('lê uma linha válida tipada', function () use ($cabecalho) {
    $linhas = lerCsv($cabecalho."1,2026-05-23,1450.50,5.12\n");

    expect($linhas)->toHaveCount(1)
        ->and($linhas[0]['_erro'])->toBeNull()
        ->and($linhas[0]['produto_id'])->toBe(1)
        ->and($linhas[0]['data_preco'])->toBe('2026-05-23')
        ->and($linhas[0]['preco_fechamento'])->toBe(1450.5)
        ->and($linhas[0]['cambio_brl'])->toBe(5.12);
});

it('rejeita cabeçalho inválido e aborta', function () {
    $linhas = lerCsv("foo,bar,baz,qux\n1,2026-05-23,1450.50,5.12\n");

    expect($linhas)->toHaveCount(1)
        ->and($linhas[0]['_erro'])->toContain('Cabeçalho inválido');
});

it('aceita cabeçalho com BOM UTF-8 (Excel)', function () use ($cabecalho) {
    $linhas = lerCsv("\xEF\xBB\xBF".$cabecalho."1,2026-05-23,1450.50,5.12\n");

    expect($linhas)->toHaveCount(1)
        ->and($linhas[0]['_erro'])->toBeNull()
        ->and($linhas[0]['produto_id'])->toBe(1);
});

it('rejeita célula com prefixo de fórmula (CWE-1236)', function () use ($cabecalho) {
    $linhas = lerCsv($cabecalho."=SOMA(A1),2026-05-23,1450.50,5.12\n");

    expect($linhas[0]['_erro'])->toContain('CWE-1236');
});

it('rejeita fórmula mesmo com espaço à esquerda (checagem após trim)', function () use ($cabecalho) {
    $linhas = lerCsv($cabecalho." =SOMA(A1),2026-05-23,1450.50,5.12\n");

    expect($linhas[0]['_erro'])->toContain('CWE-1236');
});

it('rejeita produto_id não numérico', function () use ($cabecalho) {
    $linhas = lerCsv($cabecalho."abc,2026-05-23,1450.50,5.12\n");

    expect($linhas[0]['_erro'])->toContain('produto_id');
});

it('rejeita data fora do formato ISO', function () use ($cabecalho) {
    $linhas = lerCsv($cabecalho."1,23/05/2026,1450.50,5.12\n");

    expect($linhas[0]['_erro'])->toContain('data_preco');
});

it('rejeita preço/câmbio não numéricos', function () use ($cabecalho) {
    $linhas = lerCsv($cabecalho."1,2026-05-23,xx,5.12\n");

    expect($linhas[0]['_erro'])->toContain('não numéricos');
});

it('rejeita linha com número de colunas diferente de 4', function () use ($cabecalho) {
    $linhas = lerCsv($cabecalho."1,2026-05-23,1450.50\n");

    expect($linhas[0]['_erro'])->toContain('Número de colunas');
});

it('aceita CSV do Excel pt-BR (delimitador ; e decimal ,)', function () {
    $linhas = lerCsv("produto_id;data_preco;preco_fechamento;cambio_brl\n1;2026-05-23;1450,50;5,12\n");

    expect($linhas)->toHaveCount(1)
        ->and($linhas[0]['_erro'])->toBeNull()
        ->and($linhas[0]['preco_fechamento'])->toBe(1450.5)
        ->and($linhas[0]['cambio_brl'])->toBe(5.12);
});

it('arredonda a escala decimal excedente a 6 casas (coerção, não rejeição)', function () use ($cabecalho) {
    $linhas = lerCsv($cabecalho."1,2026-05-23,1450.1234567,5.12\n");

    expect($linhas[0]['_erro'])->toBeNull()
        ->and($linhas[0]['preco_fechamento'])->toBe(1450.123457);
});

it('rejeita o lote quando excede o limite de linhas', function () use ($cabecalho) {
    $corpo = str_repeat("1,2026-05-23,1450.50,5.12\n", 5001);
    $linhas = lerCsv($cabecalho.$corpo);

    $ultima = end($linhas);
    expect($ultima['_erro'])->toContain('Limite de 5000 linhas');
});

it('rejeita arquivo acima do tamanho máximo (2 MB)', function () use ($cabecalho) {
    $corpo = str_repeat("1,2026-05-23,1450.50,5.12\n", 90000); // > 2 MB
    $linhas = lerCsv($cabecalho.$corpo);

    expect($linhas)->toHaveCount(1)
        ->and($linhas[0]['_erro'])->toContain('tamanho máximo');
});
