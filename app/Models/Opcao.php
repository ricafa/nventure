<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * OPCAO (§4.3.3, RN-004a..e): o MtM da estrutura é a soma do MtM de cada perna.
 * O `lado` de cada perna governa o cálculo; o `lado` da posição mãe é informativo (RN-004e).
 * Estruturas multi-perna (straddle, spread, butterfly) são apenas várias `Perna` — sem `if`.
 *
 * @property-read Collection<int, Perna> $pernas
 */
class Opcao extends Posicao
{
    /** @return HasMany<Perna, $this> */
    public function pernas(): HasMany
    {
        return $this->hasMany(Perna::class, 'posicao_id');
    }

    public function calcularMtm(float $precoMercado): float
    {
        return (float) $this->pernas->sum(fn (Perna $p) => $p->calcularMtm($precoMercado));
    }
}
