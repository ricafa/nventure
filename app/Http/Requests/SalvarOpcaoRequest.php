<?php

namespace App\Http\Requests;

/**
 * Cadastro de OPCAO (§5.2.3, §3.2.6/3.2.6a). A mãe é fixada em `quantidade = 1` no
 * Service (RN-004e), por isso a quantidade da mãe não é exigida aqui. As pernas
 * carregam quantidade e lado próprios (RN-004c); estrutura tem ≥ 1 perna (RN-004a) e
 * sem máximo (RN-004b: butterfly/condor).
 */
class SalvarOpcaoRequest extends SalvarPosicaoRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->regrasMae(comQuantidade: false) + [
            'nome_estrutura' => ['nullable', 'string', 'max:60'],
            'pernas' => ['required', 'array', 'min:1'], // RN-004a/b
            'pernas.*.tipo_opcao' => ['required', 'in:CALL,PUT'],
            'pernas.*.estilo' => ['required', 'in:EUROPEIA,AMERICANA'],
            'pernas.*.strike' => ['required', 'numeric', 'gt:0'], // RN-004
            'pernas.*.premio_pago' => ['required', 'numeric', 'gte:0'], // RN-004
            'pernas.*.quantidade' => ['required', 'numeric', 'gt:0'], // RN-004c
            'pernas.*.lado' => ['required', 'in:COMPRADO,VENDIDO'], // RN-004c
        ];
    }
}
