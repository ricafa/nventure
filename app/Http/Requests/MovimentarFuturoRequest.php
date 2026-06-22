<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validação estrutural de uma movimentação de FUTURO (§5.2.3, §7.1a). Só AUMENTO e
 * REDUCAO chegam por esta rota — a ABERTURA é automática no cadastro (RN-020). A
 * RN-022 (redução ≤ saldo) e a RN-025 (data ≥ entrada) são checadas no Service, sob
 * lock. O Request **não** aceita `criado_por` (D-507).
 */
class MovimentarFuturoRequest extends FormRequest
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
            'tipo' => ['required', 'in:AUMENTO,REDUCAO'],
            'data_movimentacao' => ['required', 'date_format:Y-m-d'],
            'quantidade' => ['required', 'numeric', 'gt:0'],
            'preco' => ['required', 'numeric', 'gt:0'],
        ];
    }
}
