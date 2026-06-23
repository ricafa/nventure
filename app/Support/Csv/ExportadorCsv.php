<?php

declare(strict_types=1);

namespace App\Support\Csv;

use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Exportador de relatórios em CSV (D-707). Reaproveita o endurecimento
 * anti-formula-injection (CWE-1236) do importador da Fase 4: prefixa aspa nas células
 * que começam com `= + - @ \t \r`, neutralizando fórmulas quando o arquivo é aberto no
 * Excel/Sheets. Entrega como download via `StreamedResponse` com BOM UTF-8 (Excel pt-BR).
 */
class ExportadorCsv
{
    /** @var list<string> */
    private const PERIGOSOS = ['=', '+', '-', '@', "\t", "\r"];   // CWE-1236 (mesma lista do importador)

    /**
     * @param  list<array<string, scalar|null>>  $linhas  a 1ª linha define o cabeçalho
     */
    public function resposta(array $linhas, string $nomeArquivo): StreamedResponse
    {
        return response()->streamDownload(function () use ($linhas) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");                          // BOM p/ Excel pt-BR
            if ($linhas !== []) {
                fputcsv($out, array_keys($linhas[0]));
                foreach ($linhas as $linha) {
                    fputcsv($out, array_map($this->sanitizar(...), array_values($linha)));
                }
            }
            fclose($out);
        }, $nomeArquivo, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function sanitizar(string|int|float|bool|null $v): string
    {
        $s = is_bool($v) ? ($v ? '1' : '0') : (string) $v;

        return $s !== '' && in_array($s[0], self::PERIGOSOS, true) ? "'".$s : $s;   // prefixa aspa
    }
}
