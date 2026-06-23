<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\ErroNaoEncontrado;
use App\Models\Futuro;
use App\Models\MtmDiario;
use App\Models\Ndf;
use App\Models\Opcao;
use App\Models\Otc;
use App\Models\Posicao;
use App\Services\Dados\ExposicaoProduto;
use App\Services\Dados\LinhaPosicaoAberta;
use App\Services\Dados\PontoHistoricoMtm;
use App\Services\Dados\RelatorioPosicaoAberta;
use App\Services\Dados\ResumoPL;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Leitura agregada das 4 visões da mesa de risco (RN-016..019, D-701). NÃO recalcula
 * MtM: os números financeiros vêm prontos de `mtm_diario` (Fase 6); a única aritmética
 * viva é soma/agrupamento. O preço médio do FUTURO reusa `Futuro::precoMedio()` (Model).
 */
class ServicoRelatorios
{
    /**
     * Último `mtm_diario` (<= data) de cada posição ABERTA, em UM SELECT (D-702).
     * `DISTINCT ON` é específico do PostgreSQL; a `ORDER BY` casa com idx_mtm_posicao_data.
     *
     * @return Collection<int, object> keyBy posicao_id
     */
    private function snapshot(string $data): Collection
    {
        /** @var list<object> $linhas */
        $linhas = DB::select(<<<'SQL'
            SELECT DISTINCT ON (m.posicao_id)
                   m.posicao_id, m.mtm_valor, m.variacao_dia, m.pl_acumulado,
                   m.preco_mercado, m.data_calculo
              FROM mtm_diario m
              JOIN posicao p ON p.id = m.posicao_id
             WHERE p.status = 'ABERTA' AND m.data_calculo <= ?
             ORDER BY m.posicao_id, m.data_calculo DESC
        SQL, [$data]);

        return collect($linhas)->keyBy('posicao_id');
    }

    /**
     * Carga polimórfica POR SUBCLASSE (mesmo idioma do MotorMtm, D-608): cada query
     * hidrata sua subclasse com o eager loading que precisa. Motivação = consistência
     * de idioma no codebase, NÃO economia de query.
     *
     * @param  array<string, list<string>>  $eager  relações por instrumento
     * @return Collection<int, Posicao>
     */
    private function posicoesAbertas(array $eager): Collection
    {
        return collect()
            ->merge(Futuro::query()->with($eager['FUTURO'])->where('status', 'ABERTA')->where('instrumento', 'FUTURO')->get())
            ->merge(Ndf::query()->with($eager['NDF'])->where('status', 'ABERTA')->where('instrumento', 'NDF')->get())
            ->merge(Opcao::query()->with($eager['OPCAO'])->where('status', 'ABERTA')->where('instrumento', 'OPCAO')->get())
            ->merge(Otc::query()->with($eager['OTC'])->where('status', 'ABERTA')->where('instrumento', 'OTC')->get());
    }

    /** RN-016: posições ABERTA + último MtM disponível; PM do FUTURO reusa o Model (D-701). */
    public function posicaoAberta(string $data): RelatorioPosicaoAberta
    {
        $snap = $this->snapshot($data);

        // FUTURO traz futuro+movimentacoes (PM via replay); os demais só produto.
        $posicoes = $this->posicoesAbertas([
            'FUTURO' => ['produto', 'futuro', 'movimentacoes'],
            'NDF' => ['produto'],
            'OPCAO' => ['produto'],
            'OTC' => ['produto'],
        ]);

        $linhas = $posicoes->map(function (Posicao $p) use ($snap) {
            $m = $snap->get($p->id);

            return new LinhaPosicaoAberta(
                posicaoId: (int) $p->id,
                produtoId: (int) $p->produto_id,
                produtoNome: $p->produto->nome,
                instrumento: $p->instrumento,
                lado: $p->lado,
                quantidade: Posicao::paraFloat($p->quantidade),
                precoMedio: $p instanceof Futuro ? $p->precoMedio() : null,   // RN-016 (só FUTURO)
                precoMercado: $m !== null ? (float) $m->preco_mercado : null,
                dataVencimento: $p->data_vencimento->format('Y-m-d'),
                mtm: $m !== null ? (float) $m->mtm_valor : 0.0,
                variacaoDia: $m !== null ? (float) $m->variacao_dia : 0.0,
                temMtm: $m !== null,
            );
        })->values()->all();

        return new RelatorioPosicaoAberta($data, $linhas);
    }

    /** RN-017 (diário, data exata) + RN-018 (acumulado, último <= data das ABERTA). */
    public function plDiario(string $data): ResumoPL
    {
        $plDiario = (float) MtmDiario::query()
            ->where('data_calculo', $data)
            ->sum('variacao_dia');                                  // RN-017

        $plAcumulado = (float) $this->snapshot($data)
            ->sum(fn (object $m) => (float) $m->pl_acumulado);      // RN-018

        // Série para o gráfico: "acumulado" usa SUM(pl_acumulado) — coerente com RN-018
        // (D-704), NÃO SUM(mtm_valor) (que ignora o realizado das reduções, RN-023).
        $serie = MtmDiario::query()
            ->selectRaw('data_calculo, SUM(variacao_dia) AS pl_dia, SUM(pl_acumulado) AS pl_acum')
            ->where('data_calculo', '<=', $data)
            ->groupBy('data_calculo')
            ->orderBy('data_calculo')
            ->get();

        return ResumoPL::montar($data, $plDiario, $plAcumulado, $serie);
    }

    /**
     * RN-019: Σ (quantidadeExposicao × sinal) por produto, sobre ABERTA. A quantidade é
     * polimórfica (D-705): base = posicao.quantidade (FUTURO/OTC); Ndf = valor_nocional;
     * Opcao = 1 (D-705a). Σ em PHP para usar os métodos do Model (defesa em profundidade);
     * o N de posições abertas é pequeno no MVP.
     *
     * @return list<ExposicaoProduto>
     */
    public function exposicaoLiquida(string $data): array
    {
        $snap = $this->snapshot($data);

        // NDF precisa de `ndf` para o nocional (Ndf::quantidadeExposicao() — senão N+1).
        $posicoes = $this->posicoesAbertas([
            'FUTURO' => ['produto'],
            'NDF' => ['produto', 'ndf'],
            'OPCAO' => ['produto'],
            'OTC' => ['produto'],
        ]);

        /** @var array<int, ExposicaoProduto> $acc */
        $acc = [];

        foreach ($posicoes as $p) {
            $e = $acc[$p->produto_id] ??= ExposicaoProduto::vazia((int) $p->produto_id, $p->produto->nome);
            $q = $p->quantidadeExposicao();                         // D-705: nocional p/ NDF, base p/ o resto
            $p->sinal() > 0 ? $e->somarComprado($q) : $e->somarVendido($q);
            $m = $snap->get($p->id);
            $e->somarMtm($m !== null ? (float) $m->mtm_valor : 0.0);
            $e->contar($p->instrumento);                            // D-705a: registra o mix de instrumentos
        }

        return array_values($acc);
    }

    /**
     * Série temporal de MtM de UMA posição (gráfico/sparkline). 404 se a posição não existe.
     *
     * @return list<PontoHistoricoMtm>
     */
    public function historicoMtm(int $posicaoId): array
    {
        Posicao::query()->find($posicaoId)
            ?? throw new ErroNaoEncontrado('Posição não encontrada.');   // envelope §5.1 (D-702/B-1)

        return MtmDiario::query()
            ->where('posicao_id', $posicaoId)
            ->orderBy('data_calculo')
            ->get()
            ->map(fn (MtmDiario $m) => PontoHistoricoMtm::deModel($m))
            ->all();
    }
}
