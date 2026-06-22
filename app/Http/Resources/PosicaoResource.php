<?php

namespace App\Http\Resources;

use App\Services\Dados\PosicaoDetalhe;
use App\Services\Dados\PosicaoResumo;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Serialização de posição em JSON (§5.1). Envolve um read model (`PosicaoResumo` na
 * listagem, `PosicaoDetalhe` no detalhe) e delega a forma a `paraArray()` — a HTTP
 * nunca toca o Eloquent (D-505). O wrap em `data` é o default do `JsonResource`.
 *
 * @property PosicaoResumo|PosicaoDetalhe $resource
 */
class PosicaoResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return $this->resource->paraArray();
    }
}
