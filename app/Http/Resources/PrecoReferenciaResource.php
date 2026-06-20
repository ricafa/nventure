<?php

namespace App\Http\Resources;

use App\Models\PrecoReferencia;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Serialização de preço de referência (§3.2.2, §5.1). O cast `decimal:6` devolve
 * string; aqui converte-se para **número** (sem aspas — §5.1). A perda de precisão
 * de `(float)` para valores muito grandes é trade-off aceito do MVP (BX-5).
 *
 * @mixin PrecoReferencia
 */
class PrecoReferenciaResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'produto_id' => $this->produto_id,
            'data_preco' => $this->data_preco?->toDateString(),
            'preco_fechamento' => (float) $this->preco_fechamento,
            'cambio_brl' => (float) $this->cambio_brl,
            'criado_em' => $this->criado_em?->toIso8601String(),
        ];
    }
}
