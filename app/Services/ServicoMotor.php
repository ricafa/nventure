<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\ErroNaoEncontrado;
use App\Models\MotorExecucao;
use App\Services\Dados\ResumoExecucao;
use Illuminate\Pagination\LengthAwarePaginator;

class ServicoMotor
{
    public function __construct(private readonly MotorMtm $motor) {}

    public function processar(\DateTimeImmutable $data, string $disparadoPor): ResumoExecucao
    {
        // D-602: abre a auditoria ANTES do laço; execucao_id propaga ao mtm_diario.
        $execucao = MotorExecucao::query()->create([
            'data_calculo' => $data->format('Y-m-d'),
            'disparado_por' => $disparadoPor,
            'iniciado_em' => now(),
        ]);

        $resultado = $this->motor->processarDia($data, $execucao->id);

        $execucao->update([
            'finalizado_em' => now(),
            'total_posicoes' => $resultado->totalPosicoes(),
            'sucessos' => count($resultado->sucessos),
            'falhas' => $resultado->falhas, // JSONB [{posicao_id, motivo}]
        ]);

        return ResumoExecucao::deExecucao($execucao->refresh());
    }

    /**
     * @return LengthAwarePaginator<int, MotorExecucao>
     */
    public function listar(): LengthAwarePaginator
    {
        return MotorExecucao::query()
            ->orderByDesc('id')
            ->paginate(20);
    }

    public function detalhar(int $id): MotorExecucao
    {
        $execucao = MotorExecucao::query()->find($id);

        if ($execucao === null) {
            throw new ErroNaoEncontrado("Execução de motor {$id} não encontrada.");
        }

        return $execucao;
    }
}
