<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\MotorExecucao;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ExecucaoMotorResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var MotorExecucao $this */
        return [
            'execucao_id' => $this->id,
            'data_calculo' => $this->data_calculo->format('Y-m-d'),
            'disparado_por' => $this->disparado_por,
            'iniciado_em' => $this->iniciado_em?->toIso8601String(),
            'finalizado_em' => $this->finalizado_em?->toIso8601String(),
            'total_posicoes' => $this->total_posicoes,
            'sucessos' => $this->sucessos,
            'falhas' => is_array($this->falhas) ? $this->falhas : json_decode((string) $this->falhas, true),
        ];
    }
}
