<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Valida o histórico de MtM (D-703, A-2). `posicao_id` é **de fato obrigatório** — um
 * Request dedicado garante `422` quando o parâmetro falta (um `sometimes` no request
 * compartilhado deixaria o caminho cair em `find(0)` → 404, semanticamente errado). Um
 * `posicao_id` válido mas inexistente vira `404` no Service (`find() ?? throw`).
 */
class HistoricoMtmRequest extends FormRequest
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
            'posicao_id' => ['required', 'integer'],
            'formato' => ['nullable', 'in:json,csv,pdf'],
        ];
    }

    public function formato(): string
    {
        $formato = $this->query('formato');

        return is_string($formato) && $formato !== '' ? $formato : 'json';
    }
}
