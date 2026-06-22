<?php

namespace App\Http\Requests;

/**
 * Cadastro de FUTURO (§5.2.3). Campos da mãe + `posicao_futuro`. A ABERTURA
 * automática (RN-020) é responsabilidade do Service, não da validação.
 */
class SalvarFuturoRequest extends SalvarPosicaoRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->regrasMae() + [
            'preco_entrada' => ['required', 'numeric', 'gt:0'],
            'codigo_contrato' => ['required', 'string', 'max:20'],
        ];
    }
}
