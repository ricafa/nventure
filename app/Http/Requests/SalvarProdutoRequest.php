<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validação **estrutural** de produto (D-403). A unicidade do nome fica no
 * Service (D-403) para sair no envelope §5.1.
 *
 * `apiResource` mapeia POST→store e PUT/PATCH→update; uma só classe ramifica as
 * regras por método (M-5): POST → `required`; update → `sometimes` (PATCH-merge,
 * campos ausentes preservados).
 */
class SalvarProdutoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;   // autorização por perfil é da Fase 10 (D-402)
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $obrigatorio = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'nome' => [$obrigatorio, 'string', 'max:60'],
            'unidade' => [$obrigatorio, 'string', 'max:20'],
            'bolsa_ref' => [$obrigatorio, 'string', 'max:20'],
            'moeda_cotacao' => [$obrigatorio, 'string', 'size:3'],
            'ativo' => ['sometimes', 'boolean'],
        ];
    }
}
