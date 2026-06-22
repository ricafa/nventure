<?php

namespace App\Services;

use App\Exceptions\ErroConflito;
use App\Exceptions\ErroValidacao;
use App\Models\Futuro;
use App\Models\Posicao;
use App\Models\Usuario;
use App\Services\Dados\EstadoMovimentacao;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Abertura e movimentações de FUTURO (RN-020..025), em transação com lock pessimista.
 *
 * O preço médio/quantidade/realizado são **derivados** por `replay()` (RN-021), nunca
 * persistidos: o Service consolida apenas `quantidade`/`status` na mãe (D-504/RN-024).
 * A RN-022 é validada **antes** do `INSERT`, sob `lockForUpdate`, e o `replay()` roda
 * sobre a relação recarregada (D-501). `criado_por` é sempre injetado pelo Service,
 * nunca pelo cliente (D-507).
 */
class ServicoMovimentacoes
{
    private const EPSILON = 1e-4; // casa com NUMERIC(18,4)

    /**
     * @param  array<string, mixed>  $dados
     */
    public function movimentarFuturo(int $posicaoId, array $dados): EstadoMovimentacao
    {
        return DB::transaction(function () use ($posicaoId, $dados) {
            /** @var Futuro $posicao */
            $posicao = Posicao::query()->lockForUpdate()->findOrFail($posicaoId);

            // 409: só FUTURO ABERTA aceita movimentação (§5.2.3).
            if ($posicao->instrumento !== 'FUTURO' || $posicao->status !== 'ABERTA') {
                throw new ErroConflito('Apenas posições FUTURO abertas podem ser movimentadas.');
            }

            // RN-025: data_movimentacao >= data_entrada (422).
            if ($dados['data_movimentacao'] < $posicao->data_entrada->format('Y-m-d')) {
                throw new ErroValidacao('Data da movimentação anterior à data de entrada.');
            }

            // Estado atual sob lock (fonte única: posicao.quantidade consolidada, RN-024).
            $posicao->load('movimentacoes', 'futuro');
            $saldo = round($posicao->quantidadeAtual(), 4);

            // RN-022: redução > saldo é rejeitada ANTES do INSERT (422) — sem inversão de lado.
            if ($dados['tipo'] === 'REDUCAO' && round((float) $dados['quantidade'], 4) - $saldo > self::EPSILON) {
                throw new ErroValidacao('Redução superior à quantidade atual.');
            }

            $movimentacao = $posicao->movimentacoes()->create($dados + ['criado_por' => $this->criadoPor()]);

            // A-3: recarregar para o replay incluir a movimentação recém-criada.
            $posicao->load('movimentacoes');

            $qtd = round($posicao->quantidadeAtual(), 4);
            // RN-022: redução total encerra a posição (epsilon evita "fechamento zumbi").
            $status = $qtd <= self::EPSILON ? 'ENCERRADA' : 'ABERTA';

            // D-504/RN-024: consolida APENAS quantidade/status (colunas reais).
            // preco_medio NÃO é persistido — é derivado por replay() (RN-021).
            $posicao->update(['quantidade' => $qtd, 'status' => $status]);

            return new EstadoMovimentacao(
                posicaoId: (int) $posicao->id,
                movimentacaoId: (int) $movimentacao->id,
                quantidadeAtual: $qtd,
                precoMedio: $posicao->precoMedio(),   // float vindo do Model (D-506)
                plRealizado: $posicao->plRealizado(),
                status: $status,
            );
        });
    }

    /**
     * ABERTURA automática do FUTURO na mesma transação do cadastro (RN-020).
     * O `uq_mov_abertura` garante exatamente uma ABERTURA por posição: o 23505 vira
     * 409 (D-503) — defesa em profundidade contra reprocessamento.
     *
     * @param  array<string, mixed>  $dadosAbertura
     */
    public function criarAbertura(Posicao $posicao, array $dadosAbertura): void
    {
        try {
            $posicao->movimentacoes()->create($dadosAbertura + ['tipo' => 'ABERTURA']);
        } catch (QueryException $e) {
            if ($e->getCode() === '23505') {
                throw new ErroConflito('Movimentação de ABERTURA já existente para esta posição.');
            }
            throw $e;
        }
    }

    /** Origem do autor da auditoria. Fase 10 endurece com perfil real (D-402/D-507). */
    private function criadoPor(): string
    {
        $usuario = Auth::user();

        return $usuario instanceof Usuario ? $usuario->login : 'sistema';
    }
}
