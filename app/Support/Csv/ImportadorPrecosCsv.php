<?php

namespace App\Support\Csv;

/**
 * Importador de preços a partir de CSV (D-407, D-411). Implementa `FontePrecos`.
 *
 * Responsável só por **forma e segurança** — não aplica RN-007/008/009 (isso é do
 * `ServicoPrecos`, D-403). Faz:
 *  - streaming via `SplFileObject` (sem carregar tudo em memória);
 *  - limites de tamanho (2 MB) e de linhas (5.000);
 *  - cabeçalho exato, com BOM UTF-8 removido (A-3);
 *  - delimitador `,` (canônico §5.2.2) ou `;` (Excel pt-BR) + decimal `,`→`.` (D-411);
 *  - tipagem estrita (int / date ISO / numérico) e arredondamento à escala da
 *    coluna (6 casas) — coerção, não rejeição (M-1);
 *  - anti-formula-injection (CWE-1236) aplicado **após `trim`** (A-4).
 *
 * Linha inválida vira rejeição no relatório (RN-010), nunca exceção que aborta o lote.
 */
class ImportadorPrecosCsv implements FontePrecos
{
    /** @var list<string> */
    private const CABECALHO = ['produto_id', 'data_preco', 'preco_fechamento', 'cambio_brl'];

    private const MAX_LINHAS = 5000;

    private const MAX_BYTES = 2_097_152;   // 2 MB

    /** @var list<string> */
    private const PERIGOSOS = ['=', '+', '-', '@', "\t", "\r"]; // CWE-1236

    public function __construct(private readonly string $caminho) {}

    public function ler(): iterable
    {
        if ((int) filesize($this->caminho) > self::MAX_BYTES) {
            yield ['_linha' => 0, '_erro' => 'Arquivo excede o tamanho máximo (2 MB).'];

            return;
        }

        $arquivo = new \SplFileObject($this->caminho, 'r');

        // Detecta o delimitador pela 1ª linha: aceita ',' (RFC 4180 / §5.2.2) e ';'
        // (Excel pt-BR). Com ';', o decimal vira ',' (normalizado adiante). (D-411)
        $delimitador = $this->detectarDelimitador($arquivo);

        $arquivo->setFlags(\SplFileObject::READ_CSV | \SplFileObject::SKIP_EMPTY | \SplFileObject::DROP_NEW_LINE);
        $arquivo->setCsvControl($delimitador);

        $numero = 0;
        foreach ($arquivo as $colunas) {
            if (! is_array($colunas) || $colunas === [null]) {
                continue;
            }
            $numero++;

            if ($numero === 1) {                                   // cabeçalho exato
                $colunas[0] = $this->removerBom((string) ($colunas[0] ?? ''));   // Excel grava BOM (A-3)
                if (array_map(fn ($v) => trim((string) $v), $colunas) !== self::CABECALHO) {
                    yield ['_linha' => 1, '_erro' => 'Cabeçalho inválido. Esperado: '.implode(',', self::CABECALHO)];

                    return;
                }

                continue;
            }
            if ($numero - 1 > self::MAX_LINHAS) {
                yield ['_linha' => $numero, '_erro' => 'Limite de '.self::MAX_LINHAS.' linhas excedido.'];

                return;
            }

            yield $this->parseLinha($numero, $colunas, $delimitador);
        }
    }

    /** Sniff do delimitador (`,`/`;`) na 1ª linha, sem consumir o ponteiro de leitura. */
    private function detectarDelimitador(\SplFileObject $arquivo): string
    {
        $primeira = (string) $arquivo->fgets();
        $arquivo->rewind();

        return substr_count($primeira, ';') > substr_count($primeira, ',') ? ';' : ',';
    }

    /** Remove o BOM UTF-8 (EF BB BF) que o Excel grava na 1ª célula. */
    private function removerBom(string $valor): string
    {
        return str_starts_with($valor, "\xEF\xBB\xBF") ? substr($valor, 3) : $valor;
    }

    /**
     * @param  array<array-key, mixed>  $c
     * @return array<string, mixed>
     */
    private function parseLinha(int $numero, array $c, string $delimitador): array
    {
        $base = ['_linha' => $numero, '_erro' => null];

        if (count($c) !== 4) {
            return ['_linha' => $numero, '_erro' => 'Número de colunas diferente de 4.'];
        }

        [$produtoId, $data, $preco, $cambio] = array_map(fn ($v) => trim((string) $v), array_values($c));

        foreach ([$produtoId, $data, $preco, $cambio] as $celula) {   // anti-formula-injection após trim (D-407/A-4)
            if ($celula !== '' && in_array($celula[0], self::PERIGOSOS, true)) {
                return ['_linha' => $numero, '_erro' => 'Célula com prefixo potencialmente perigoso (CWE-1236).'];
            }
        }

        // Excel pt-BR (delimitador ';') usa vírgula decimal: 1450,50 → 1450.50 (D-411)
        if ($delimitador === ';') {
            $preco = str_replace(',', '.', $preco);
            $cambio = str_replace(',', '.', $cambio);
        }

        if (! ctype_digit($produtoId)) {
            return ['_linha' => $numero, '_erro' => 'produto_id inválido.'];
        }
        if (\DateTimeImmutable::createFromFormat('!Y-m-d', $data) === false) {
            return ['_linha' => $numero, '_erro' => 'data_preco fora do formato YYYY-MM-DD.'];
        }
        if (! is_numeric($preco) || ! is_numeric($cambio)) {
            return ['_linha' => $numero, '_erro' => 'preco_fechamento/cambio_brl não numéricos.'];
        }

        return $base + [
            'produto_id' => (int) $produtoId,
            'data_preco' => $data,
            'preco_fechamento' => round((float) $preco, 6),   // escala 6 (M-1): coage à escala da coluna
            'cambio_brl' => round((float) $cambio, 6),
        ];
    }
}
