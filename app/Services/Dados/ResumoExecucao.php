<?php

declare(strict_types=1);

namespace App\Services\Dados;

use App\Models\MotorExecucao;

final class ResumoExecucao
{
    /**
     * @param  list<array{posicao_id: int, motivo: string}>  $falhas
     */
    public function __construct(
        public readonly int $execucaoId,
        public readonly string $dataCalculo,
        public readonly int $posicoesProcessadas,
        public readonly int $sucessos,
        public readonly array $falhas,
    ) {}

    public static function deExecucao(MotorExecucao $e): self
    {
        /** @var list<array{posicao_id: int, motivo: string}> $falhas */
        $falhas = is_array($e->falhas) ? $e->falhas : [];

        return new self(
            execucaoId: $e->id,
            dataCalculo: $e->data_calculo->format('Y-m-d'),
            posicoesProcessadas: (int) $e->total_posicoes,
            sucessos: (int) $e->sucessos,
            falhas: $falhas,
        );
    }

    /**
     * @return array{execucao_id: int, data_calculo: string, posicoes_processadas: int, sucessos: int, falhas: list<array{posicao_id: int, motivo: string}>}
     */
    public function paraArray(): array
    {
        return [
            'execucao_id' => $this->execucaoId,
            'data_calculo' => $this->dataCalculo,
            'posicoes_processadas' => $this->posicoesProcessadas,
            'sucessos' => $this->sucessos,
            'falhas' => $this->falhas,
        ];
    }
}
