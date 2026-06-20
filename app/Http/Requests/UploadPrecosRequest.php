<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Upload do CSV de preços. `mimes`/`max` são a primeira barreira (defesa em
 * profundidade); o limite de **linhas** e a sanitização anti-fórmula ficam no
 * importador (D-407).
 */
class UploadPrecosRequest extends FormRequest
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
            'arquivo' => ['required', 'file', 'mimes:csv,txt', 'max:2048'],   // KB
        ];
    }
}
