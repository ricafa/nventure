<?php

declare(strict_types=1);

namespace App\Services\Dados;

final class ResultadoProcessamento
{
    /** @var list<int> */
    public array $sucessos = [];

    /** @var list<array{posicao_id: int, motivo: string}> */
    public array $falhas = [];

    public function __construct(
        public readonly \DateTimeImmutable $data
    ) {}

    public function registrarSucesso(int $posicaoId): void
    {
        $this->sucessos[] = $posicaoId;
    }

    public function registrarFalha(int $posicaoId, string $motivo): void
    {
        $this->falhas[] = [
            'posicao_id' => $posicaoId,
            'motivo' => $motivo,
        ];
    }

    public function totalPosicoes(): int
    {
        return count($this->sucessos) + count($this->falhas);
    }
}
