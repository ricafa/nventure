<?php

declare(strict_types=1);

namespace App\Services\Dados;

use App\Models\MtmDiario;

/**
 * Um ponto da série temporal de MtM de uma posição (`historico-mtm`, D-710). Construído
 * direto do Model `MtmDiario`; converte os decimais (string) para float na borda.
 */
final class PontoHistoricoMtm
{
    public function __construct(
        public string $dataCalculo,
        public float $precoMercado,
        public float $mtmValor,
        public float $variacaoDia,
        public float $plAcumulado,
    ) {}

    public static function deModel(MtmDiario $m): self
    {
        return new self(
            dataCalculo: $m->data_calculo->format('Y-m-d'),
            precoMercado: (float) $m->preco_mercado,
            mtmValor: (float) $m->mtm_valor,
            variacaoDia: (float) $m->variacao_dia,
            plAcumulado: (float) $m->pl_acumulado,
        );
    }

    /**
     * @return array<string, scalar|null>
     */
    public function paraArray(): array
    {
        return [
            'data_calculo' => $this->dataCalculo,
            'preco_mercado' => $this->precoMercado,
            'mtm_valor' => $this->mtmValor,
            'variacao_dia' => $this->variacaoDia,
            'pl_acumulado' => $this->plAcumulado,
        ];
    }
}
