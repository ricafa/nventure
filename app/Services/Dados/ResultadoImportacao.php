<?php

namespace App\Services\Dados;

/**
 * Relatório de uma importação de preços (D-409).
 *
 * Conta linhas aceitas e lista as rejeitadas (`{linha, motivo}`). Serializado em
 * `POST /precos/upload` e exibido na tela de preços. É a fronteira limpa entre o
 * `ServicoPrecos` e a camada HTTP/UI.
 */
final class ResultadoImportacao
{
    /** @param list<array{linha: int, motivo: string}> $rejeitadas */
    public function __construct(
        public int $aceitas = 0,
        public array $rejeitadas = [],
    ) {}

    public function aceitar(): void
    {
        $this->aceitas++;
    }

    public function rejeitar(int $linha, string $motivo): void
    {
        $this->rejeitadas[] = ['linha' => $linha, 'motivo' => $motivo];
    }

    public function total(): int
    {
        return $this->aceitas + count($this->rejeitadas);
    }

    /** @return array{total: int, aceitas: int, rejeitadas: list<array{linha: int, motivo: string}>} */
    public function paraArray(): array
    {
        return [
            'total' => $this->total(),
            'aceitas' => $this->aceitas,
            'rejeitadas' => $this->rejeitadas,
        ];
    }
}
