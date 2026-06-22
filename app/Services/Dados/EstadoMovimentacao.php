<?php

namespace App\Services\Dados;

/**
 * Estado recalculado de uma posição FUTURO após uma movimentação (§5.2.3, D-505).
 *
 * É o read model devolvido por `POST /posicoes/{id}/movimentacoes`: blinda a HTTP
 * da forma como os Models estruturam as tabelas e carrega o estado **derivado** por
 * `replay()` (preço médio/quantidade/realizado) — nunca um valor persistido (D-504).
 */
final class EstadoMovimentacao
{
    public function __construct(
        public int $posicaoId,
        public int $movimentacaoId,
        public float $quantidadeAtual,
        public float $precoMedio,
        public float $plRealizado,
        public string $status,
    ) {}

    /**
     * @return array{
     *     posicao_id: int,
     *     movimentacao_id: int,
     *     quantidade_atual: float,
     *     preco_medio: float,
     *     pl_realizado: float,
     *     status: string
     * }
     */
    public function paraArray(): array
    {
        return [
            'posicao_id' => $this->posicaoId,
            'movimentacao_id' => $this->movimentacaoId,
            'quantidade_atual' => round($this->quantidadeAtual, 4),
            'preco_medio' => round($this->precoMedio, 6),
            'pl_realizado' => round($this->plRealizado, 2),
            'status' => $this->status,
        ];
    }
}
