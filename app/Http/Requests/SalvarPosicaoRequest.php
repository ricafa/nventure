<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Base dos cadastros de posição: validação **estrutural** dos campos da mãe `posicao`
 * (§3.2.3). As subclasses acrescentam as regras da tabela-filha (D-508). Regras que
 * exigem lookup no banco (RN-006) ficam no Service. Nenhum Request aceita `criado_por`
 * (anti-spoofing de auditoria, §2.3/D-507).
 */
abstract class SalvarPosicaoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // autorização por perfil é da Fase 10 (D-402)
    }

    /**
     * @return array<string, mixed>
     */
    abstract public function rules(): array;

    /**
     * Regras comuns à mãe `posicao`. RN-001 (quantidade > 0), RN-002 (vencimento >
     * entrada) e RN-003 (BALCAO exige contraparte) vivem aqui.
     *
     * @param  bool  $comQuantidade  OPCAO fixa a mãe em 1 no Service e não a exige (RN-004e).
     * @return array<string, mixed>
     */
    protected function regrasMae(bool $comQuantidade = true): array
    {
        return array_merge([
            'produto_id' => ['required', 'integer', 'exists:produto,id'],
            'mercado' => ['required', 'in:BOLSA,BALCAO'],
            'lado' => ['required', 'in:COMPRADO,VENDIDO'],
            'data_entrada' => ['required', 'date_format:Y-m-d'],
            'data_vencimento' => ['required', 'date_format:Y-m-d', 'after:data_entrada'], // RN-002
            'contraparte' => ['nullable', 'string', 'max:100', 'required_if:mercado,BALCAO'], // RN-003
            'observacoes' => ['nullable', 'string'],
        ], $comQuantidade ? [
            'quantidade' => ['required', 'numeric', 'gt:0'], // RN-001
        ] : []);
    }
}
