<?php

namespace App\Http\Requests;

/**
 * Cadastro de OTC (§5.2.3, §3.2.7). RN-006 (indexador corresponde a um produto
 * cadastrado) é checagem do Service — exige lookup no banco, não cabe no Request.
 * `premio_otc` pode ser negativo (§3.2.7), por isso sem `gte:0`.
 */
class SalvarOtcRequest extends SalvarPosicaoRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->regrasMae() + [
            'preco_entrada' => ['required', 'numeric', 'gt:0'],
            'indexador' => ['required', 'string', 'max:30'],
            'premio_otc' => ['nullable', 'numeric'],
        ];
    }
}
