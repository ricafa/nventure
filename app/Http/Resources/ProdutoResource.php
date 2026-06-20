<?php

namespace App\Http\Resources;

use App\Models\Produto;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Serialização de produto (§3.2.1, §5.1). Decimais não se aplicam aqui;
 * datas em ISO e `ativo` booleano.
 *
 * @mixin Produto
 */
class ProdutoResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'nome' => $this->nome,
            'unidade' => $this->unidade,
            'bolsa_ref' => $this->bolsa_ref,
            'moeda_cotacao' => $this->moeda_cotacao,
            'ativo' => $this->ativo,
            'criado_em' => $this->criado_em?->toIso8601String(),
        ];
    }
}
