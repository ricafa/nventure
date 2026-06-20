<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Filtros de listagem de preços (§5.2.2). */
class ListarPrecosRequest extends FormRequest
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
            'produto_id' => ['sometimes', 'integer'],
            'data_inicio' => ['sometimes', 'date_format:Y-m-d'],
            'data_fim' => ['sometimes', 'date_format:Y-m-d'],
        ];
    }
}
