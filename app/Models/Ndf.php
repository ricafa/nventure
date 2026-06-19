<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * NDF (§4.3.2, RN-015): diferença entre a taxa de mercado e a contratada × nocional.
 * A conversão BRL (× `cambio_brl`) é do motor (Fase 6); para NDF cambial, a convenção
 * `cambio_brl = 1` do produto-moeda mantém a multiplicação neutra. O Model não trata câmbio.
 *
 * @property-read PosicaoNdf $ndf
 */
class Ndf extends Posicao
{
    /** @return HasOne<PosicaoNdf, $this> */
    public function ndf(): HasOne
    {
        return $this->hasOne(PosicaoNdf::class, 'posicao_id');
    }

    public function calcularMtm(float $precoMercado): float
    {
        return ($precoMercado - self::paraFloat($this->ndf->taxa_contratada))
             * self::paraFloat($this->ndf->valor_nocional)
             * $this->sinal();
    }
}
