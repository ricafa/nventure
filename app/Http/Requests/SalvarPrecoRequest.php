<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validação **estrutural** de lançamento de preço (D-403). A existência do
 * produto, a unicidade (RN-007) e a positividade autoritativa (RN-008/009) ficam
 * no `ServicoPrecos`; o `gt:0` aqui é espelho para UX (defesa em profundidade).
 */
class SalvarPrecoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'produto_id' => ['required', 'integer'],
            'data_preco' => ['required', 'date_format:Y-m-d'],
            'preco_fechamento' => ['required', 'numeric', 'gt:0'],
            'cambio_brl' => ['required', 'numeric', 'gt:0'],
        ];
    }
}
