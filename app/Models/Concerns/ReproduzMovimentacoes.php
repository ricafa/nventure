<?php

namespace App\Models\Concerns;

/**
 * Replay puro das movimentações de FUTURO (RN-021..024).
 *
 * Reaplica abertura/aumentos/reduções sobre uma lista de tuplas primitivas
 * (não Models), reproduzindo preço médio ponderado e P&L realizado. Por operar
 * sobre arrays/floats, é testável isoladamente, sem banco (D-202).
 */
trait ReproduzMovimentacoes
{
    /**
     * @param  list<array{id:int,tipo:string,data:string,quantidade:float,preco:float}>  $movs
     * @return array{0:float,1:float,2:float} [quantidadeAtual, precoMedio, plRealizado]
     */
    public static function reproduzir(array $movs, int $sinal): array
    {
        // Desempate determinístico: por data, ABERTURA primeiro, e por fim o id de
        // inserção. Sem o id, um AUMENTO e uma REDUCAO no MESMO dia ficariam à mercê do
        // stable sort (ordem do banco) e produziriam P&L realizado diferente conforme a
        // ordem (a redução realiza contra o pm vigente; o aumento muda o pm). O id casa
        // com o índice idx_mov_posicao_data(posicao_id, data_movimentacao, id).
        usort($movs, fn (array $a, array $b) => [$a['data'], $a['tipo'] !== 'ABERTURA', $a['id']]
            <=> [$b['data'], $b['tipo'] !== 'ABERTURA', $b['id']]);

        $qtd = 0.0;
        $pm = 0.0;
        $realizado = 0.0;

        foreach ($movs as $m) {
            if ($m['tipo'] === 'ABERTURA' || $m['tipo'] === 'AUMENTO') {
                $pm = ($qtd * $pm + $m['quantidade'] * $m['preco']) / ($qtd + $m['quantidade']);
                $qtd += $m['quantidade'];
            } else { // REDUCAO — pm inalterado (RN-021)
                $realizado += ($m['preco'] - $pm) * $m['quantidade'] * $sinal;
                $qtd -= $m['quantidade'];
            }
        }

        return [$qtd, $pm, $realizado];
    }
}
