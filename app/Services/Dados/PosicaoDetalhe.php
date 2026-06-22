<?php

namespace App\Services\Dados;

use App\Models\Futuro;
use App\Models\Movimentacao;
use App\Models\Ndf;
use App\Models\Opcao;
use App\Models\Otc;
use App\Models\Perna;
use App\Models\Posicao;

/**
 * Agregação completa de uma posição (mãe + tabela-filha) para a visualização de
 * detalhe (§5.2.3, D-505). O bloco `detalhe` carrega os campos específicos do tipo;
 * para FUTURO, traz também o estado derivado (`replay()`) e o histórico de
 * movimentações. É um read model puro — nenhum Eloquent vaza para a HTTP/UI.
 *
 * O `match` por instrumento aqui é de **serialização** (fronteira de leitura), não do
 * motor de cálculo — a regra de "sem `if`/`switch` por tipo" vale para o motor (§4).
 */
final class PosicaoDetalhe
{
    /**
     * @param  array<string, mixed>  $detalhe  Campos específicos do instrumento.
     * @param  list<array<string, mixed>>  $movimentacoes  Histórico (somente FUTURO).
     */
    public function __construct(
        public PosicaoResumo $resumo,
        public array $detalhe,
        public array $movimentacoes = [],
        public ?string $observacoes = null,
    ) {}

    public static function deModel(Posicao $posicao): self
    {
        return new self(
            resumo: PosicaoResumo::deModel($posicao),
            detalhe: self::detalheDoTipo($posicao),
            movimentacoes: $posicao instanceof Futuro
                ? self::movimentacoes($posicao)
                : [],
            observacoes: $posicao->observacoes,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function detalheDoTipo(Posicao $posicao): array
    {
        return match (true) {
            $posicao instanceof Futuro => [
                'preco_entrada' => Posicao::paraFloat($posicao->futuro->preco_entrada),
                'codigo_contrato' => $posicao->futuro->codigo_contrato,
                'preco_medio' => round($posicao->precoMedio(), 6),
                'quantidade_atual' => round($posicao->quantidadeAtual(), 4),
                'pl_realizado' => round($posicao->plRealizado(), 2),
            ],
            $posicao instanceof Ndf => [
                'taxa_contratada' => Posicao::paraFloat($posicao->ndf->taxa_contratada),
                'valor_nocional' => Posicao::paraFloat($posicao->ndf->valor_nocional),
                'moeda_nocional' => $posicao->ndf->moeda_nocional,
            ],
            $posicao instanceof Opcao => [
                'nome_estrutura' => $posicao->opcao->nome_estrutura,
                'pernas' => $posicao->pernas->map(fn (Perna $p) => [
                    'sequencia' => (int) $p->sequencia,
                    'tipo_opcao' => $p->tipo_opcao,
                    'estilo' => $p->estilo,
                    'strike' => Posicao::paraFloat($p->strike),
                    'premio_pago' => Posicao::paraFloat($p->premio_pago),
                    'quantidade' => Posicao::paraFloat($p->quantidade),
                    'lado' => $p->lado,
                ])->all(),
            ],
            $posicao instanceof Otc => [
                'preco_entrada' => Posicao::paraFloat($posicao->otc->preco_entrada),
                'indexador' => $posicao->otc->indexador,
                'premio_otc' => Posicao::paraFloat($posicao->otc->premio_otc),
            ],
            default => [],
        };
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function movimentacoes(Futuro $posicao): array
    {
        return array_values(
            $posicao->movimentacoes
                ->sortBy([['data_movimentacao', 'asc'], ['id', 'asc']])
                ->map(fn (Movimentacao $m) => [
                    'id' => (int) $m->id,
                    'tipo' => $m->tipo,
                    'data_movimentacao' => $m->data_movimentacao->format('Y-m-d'),
                    'quantidade' => Posicao::paraFloat($m->quantidade),
                    'preco' => Posicao::paraFloat($m->preco),
                ])
                ->all()
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function paraArray(): array
    {
        return $this->resumo->paraArray() + [
            'observacoes' => $this->observacoes,
            'detalhe' => $this->detalhe,
            'movimentacoes' => $this->movimentacoes,
        ];
    }
}
