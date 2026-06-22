<?php

namespace App\Http\Requests;

/**
 * Cadastro de NDF (§5.2.3, §3.2.5). RN-005: `valor_nocional > 0`.
 */
class SalvarNdfRequest extends SalvarPosicaoRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->regrasMae() + [
            'taxa_contratada' => ['required', 'numeric', 'gt:0'],
            'valor_nocional' => ['required', 'numeric', 'gt:0'], // RN-005
            'moeda_nocional' => ['required', 'string', 'size:3'],
        ];
    }
}
