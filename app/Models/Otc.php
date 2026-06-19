<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * OTC (§4.3.4): preço efetivo = preço do indexador + prêmio OTC; o MtM é a diferença
 * entre o preço efetivo e o preço de entrada.
 *
 * @property-read PosicaoOtc $otc
 */
class Otc extends Posicao
{
    /** @return HasOne<PosicaoOtc, $this> */
    public function otc(): HasOne
    {
        return $this->hasOne(PosicaoOtc::class, 'posicao_id');
    }

    public function calcularMtm(float $precoMercado): float
    {
        $efetivo = $precoMercado + self::paraFloat($this->otc->premio_otc);

        return ($efetivo - self::paraFloat($this->otc->preco_entrada))
             * self::paraFloat($this->quantidade)
             * $this->sinal();
    }
}
