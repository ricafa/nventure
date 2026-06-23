<?php

declare(strict_types=1);

namespace App\Services\Dados;

use App\Models\MtmDiario;
use Illuminate\Support\Collection;

/**
 * P&L diário (RN-017, data exata) e acumulado (RN-018, último MtM <= data das ABERTA)
 * mais a série temporal para o gráfico (D-704, D-710).
 *
 * A série é conveniência de UI: agrega por `data_calculo` na janela. A coluna
 * "acumulado" da série usa `SUM(pl_acumulado)` (coerente com RN-018, D-704) — não
 * `SUM(mtm_valor)`. Ainda assim, o último ponto da série agrega **todas** as posições
 * daquele dia, enquanto o KPI `pl_acumulado` é o snapshot só das ABERTA; populações
 * diferentes podem deixar uma pequena diferença residual. O número **canônico** é
 * sempre o escalar da data; a série é ilustrativa.
 */
final class ResumoPL
{
    /** @param list<array{data: string, pl_dia: float, pl_acumulado: float}> $serie */
    public function __construct(
        public string $data,
        public float $plDiario,
        public float $plAcumulado,
        public array $serie,
    ) {}

    /**
     * @param  Collection<int, MtmDiario>  $serie  linhas com data_calculo/pl_dia/pl_acum
     */
    public static function montar(string $data, float $plDiario, float $plAcumulado, Collection $serie): self
    {
        $pontos = $serie->map(fn ($linha) => [
            'data' => $linha->data_calculo->format('Y-m-d'),
            'pl_dia' => round((float) $linha->pl_dia, 2),
            'pl_acumulado' => round((float) $linha->pl_acum, 2),
        ])->values()->all();

        return new self($data, round($plDiario, 2), round($plAcumulado, 2), $pontos);
    }

    /**
     * @return array<string, mixed>
     */
    public function paraArray(): array
    {
        return [
            'data' => $this->data,
            'pl_diario' => $this->plDiario,
            'pl_acumulado' => $this->plAcumulado,
            'serie' => $this->serie,
        ];
    }

    /**
     * Para CSV: a série temporal (uma linha por pregão).
     *
     * @return list<array<string, scalar|null>>
     */
    public function paraLinhasCsv(): array
    {
        return $this->serie;
    }
}
