<?php

namespace App\Models;

use App\Models\Concerns\ReproduzMovimentacoes;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * FUTURO (§4.3.1, RN-020..024). Preço médio, quantidade atual e P&L realizado são
 * **derivados** das movimentações (replay puro); `preco_entrada` permanece como o
 * preço da abertura e nunca é reaproveitado como preço médio (RN-021).
 *
 * @property-read PosicaoFuturo $futuro
 */
class Futuro extends Posicao
{
    use ReproduzMovimentacoes;

    /** @return HasOne<PosicaoFuturo, $this> */
    public function futuro(): HasOne
    {
        return $this->hasOne(PosicaoFuturo::class, 'posicao_id');
    }

    /**
     * Replay puro sobre `$this->movimentacoes` já carregada (D-201).
     *
     * @return array{0:float,1:float,2:float} [quantidadeAtual, precoMedio, plRealizado]
     */
    private function replay(): array
    {
        $movs = array_values($this->movimentacoes->map(fn (Movimentacao $m) => [
            'id' => (int) $m->id,                       // desempate determinístico (§4.2)
            'tipo' => $m->tipo,
            'data' => $m->data_movimentacao->format('Y-m-d'),
            'quantidade' => self::paraFloat($m->quantidade),
            'preco' => self::paraFloat($m->preco),
        ])->all());

        return self::reproduzir($movs, $this->sinal());
    }

    public function precoMedio(): float
    {
        return $this->movimentacoes->isNotEmpty()
            ? $this->replay()[1]
            : self::paraFloat($this->futuro->preco_entrada);
    }

    public function quantidadeAtual(): float
    {
        return $this->movimentacoes->isNotEmpty()
            ? $this->replay()[0]
            : self::paraFloat($this->quantidade);
    }

    public function plRealizado(): float
    {
        return $this->movimentacoes->isNotEmpty() ? $this->replay()[2] : 0.0;
    }

    public function calcularMtm(float $precoMercado): float
    {
        return ($precoMercado - $this->precoMedio()) * $this->quantidadeAtual() * $this->sinal();
    }
}
