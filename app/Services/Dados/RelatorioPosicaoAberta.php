<?php

declare(strict_types=1);

namespace App\Services\Dados;

/**
 * Relatório de posição aberta (RN-016, D-710): a coleção de {@see LinhaPosicaoAberta}
 * mais os totais consolidados (MtM e Δ do dia). Os totais são somas em PHP das linhas
 * já montadas — nenhum recálculo de MtM.
 */
final class RelatorioPosicaoAberta
{
    /** @param list<LinhaPosicaoAberta> $linhas */
    public function __construct(
        public string $data,
        public array $linhas,
    ) {}

    public function totalMtm(): float
    {
        return round(array_sum(array_map(fn (LinhaPosicaoAberta $l) => $l->mtm, $this->linhas)), 2);
    }

    public function totalVariacao(): float
    {
        return round(array_sum(array_map(fn (LinhaPosicaoAberta $l) => $l->variacaoDia, $this->linhas)), 2);
    }

    /**
     * @return array<string, mixed>
     */
    public function paraArray(): array
    {
        return [
            'data' => $this->data,
            'total_mtm' => $this->totalMtm(),
            'total_variacao' => $this->totalVariacao(),
            'posicoes' => array_map(fn (LinhaPosicaoAberta $l) => $l->paraArray(), $this->linhas),
        ];
    }

    /**
     * Linhas planas para o exportador CSV (uma linha por posição).
     *
     * @return list<array<string, scalar|null>>
     */
    public function paraLinhasCsv(): array
    {
        return array_map(fn (LinhaPosicaoAberta $l) => $l->paraArray(), $this->linhas);
    }
}
