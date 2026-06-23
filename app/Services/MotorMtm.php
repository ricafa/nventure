<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Futuro;
use App\Models\MtmDiario;
use App\Models\Ndf;
use App\Models\Opcao;
use App\Models\Otc;
use App\Models\Posicao;
use App\Models\PrecoReferencia;
use App\Services\Dados\RegistroMtm;
use App\Services\Dados\ResultadoProcessamento;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Throwable;

class MotorMtm
{
    /**
     * Processa todas as posições ABERTA para a data. Idempotente (RN-013);
     * falhas isoladas não interrompem o lote (RN-012); sem `if` por tipo.
     */
    public function processarDia(\DateTimeImmutable $data, int $execucaoId): ResultadoProcessamento
    {
        $dataStr = $data->format('Y-m-d');
        $resultado = new ResultadoProcessamento($data);

        // RN-011 + eager loading (D-608): carrega por subclasse para não estourar método na base.
        $posicoes = collect()
            ->merge(Futuro::query()->with(['futuro', 'movimentacoes'])->where('status', 'ABERTA')->where('instrumento', 'FUTURO')->get())
            ->merge(Ndf::query()->with('ndf')->where('status', 'ABERTA')->where('instrumento', 'NDF')->get())
            ->merge(Opcao::query()->with(['opcao', 'pernas'])->where('status', 'ABERTA')->where('instrumento', 'OPCAO')->get())
            ->merge(Otc::query()->with('otc')->where('status', 'ABERTA')->where('instrumento', 'OTC')->get());

        // D-608: preços do dia em UM SELECT, indexados por produto_id (evita N+1).
        $precos = PrecoReferencia::query()
            ->where('data_preco', $dataStr)
            ->get()
            ->keyBy('produto_id');

        foreach ($posicoes as $posicao) {
            try {
                $preco = $precos->get($posicao->produto_id);
                if ($preco === null) {
                    $resultado->registrarFalha($posicao->id, 'Preço não cadastrado para a data');

                    continue; // RN-012
                }

                DB::transaction(function () use ($posicao, $preco, $dataStr, $execucaoId) {
                    $registro = $this->calcularRegistro($posicao, $preco, $dataStr);
                    $this->persistir($registro, $dataStr, $execucaoId);

                    // RN-014 (D-605/M-3): vencida só quem teve sucesso ∩ venceu, sob lock (D-501).
                    if ($posicao->data_vencimento->format('Y-m-d') <= $dataStr) {
                        /** @var Posicao|null $posLock */
                        $posLock = Posicao::lockForUpdate()->find($posicao->id);
                        if ($posLock !== null && $posLock->status === 'ABERTA') {
                            $posLock->update(['status' => 'VENCIDA']);
                        }
                    }
                });

                $resultado->registrarSucesso($posicao->id);
            } catch (QueryException $e) {
                // A-1: mensagem genérica, não vazar SQL na auditoria/API
                $resultado->registrarFalha($posicao->id, 'Conflito ao gravar MtM / reprocessamento concorrente');
            } catch (Throwable $e) {
                // A-1: mensagem genérica para erros inesperados
                $resultado->registrarFalha($posicao->id, 'Erro inesperado ao processar a posição');
            }
        }

        return $resultado;
    }

    /**
     * Aritmética pura (RN-015/RN-023): nenhuma escrita, só leitura de relações já carregadas.
     */
    private function calcularRegistro(Posicao $posicao, PrecoReferencia $preco, string $dataStr): RegistroMtm
    {
        $cambio = (float) $preco->cambio_brl;

        $mtmBrl = $posicao->calcularMtm((float) $preco->preco_fechamento) * $cambio; // RN-015
        $plAcumulado = $mtmBrl + $posicao->plRealizado() * $cambio;                  // RN-023

        $mtmOntem = MtmDiario::query()
            ->where('posicao_id', $posicao->id)
            ->where('data_calculo', '<', $dataStr)
            ->orderByDesc('data_calculo')               // idx_mtm_posicao_data
            ->value('mtm_valor');
        $variacao = $mtmBrl - (float) ($mtmOntem ?? 0.0);

        return new RegistroMtm(
            posicaoId: $posicao->id,
            precoRefId: $preco->id,
            precoMercado: (float) $preco->preco_fechamento,
            mtmValor: round($mtmBrl, 2),
            variacaoDia: round($variacao, 2),
            plAcumulado: round($plAcumulado, 2),
        );
    }

    /**
     * UPSERT idempotente (RN-013, D-603) com proveniência condicional (D-604).
     */
    private function persistir(RegistroMtm $r, string $dataStr, int $execucaoId): void
    {
        $mtm = MtmDiario::query()->firstOrNew([
            'posicao_id' => $r->posicaoId,
            'data_calculo' => $dataStr,
        ]);

        $mtm->fill([
            'preco_ref_id' => $r->precoRefId,
            'preco_mercado' => $r->precoMercado,
            'mtm_valor' => $r->mtmValor,
            'variacao_dia' => $r->variacaoDia,
            'pl_acumulado' => $r->plAcumulado,
        ]);

        // D-604: só carimba autoria/timestamp quando algo financeiro mudou.
        if (! $mtm->exists || $mtm->isDirty(['preco_ref_id', 'preco_mercado', 'mtm_valor', 'variacao_dia', 'pl_acumulado'])) {
            $mtm->execucao_id = $execucaoId;
            $mtm->processado_em = now();
        }

        $mtm->save();
    }
}
