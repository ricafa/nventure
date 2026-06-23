<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Valida os 3 relatórios de data (posição-aberta/pl-diário/exposição-líquida, D-703).
 * `data` é opcional (default = hoje, §6.2); `formato` opcional (default json). AuthZ por
 * perfil é Fase 10 (D-709) — `authorize()` libera; a autenticação é do `auth:sanctum`.
 */
class RelatorioRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'data' => ['nullable', 'date'],
            'formato' => ['nullable', 'in:json,csv,pdf'],
        ];
    }

    /** Não pode chamar-se `data()`: colidiria com `Illuminate\Http\Request::data()`. */
    public function dataRef(): string
    {
        $data = $this->query('data');

        return is_string($data) && $data !== '' ? $data : today()->toDateString();
    }

    public function formato(): string
    {
        $formato = $this->query('formato');

        return is_string($formato) && $formato !== '' ? $formato : 'json';
    }
}
