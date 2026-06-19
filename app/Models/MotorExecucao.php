<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Registro de cada execução do motor MtM (`motor_execucao`, §3.2.9) — auditoria por design.
 *
 * @property int $id
 * @property Carbon $data_calculo
 * @property string $disparado_por
 * @property Carbon $iniciado_em
 * @property Carbon|null $finalizado_em
 * @property int|null $total_posicoes
 * @property int|null $sucessos
 * @property list<array{posicao_id:int,motivo:string}>|null $falhas
 * @property-read Collection<int, MtmDiario> $mtmDiarios
 */
class MotorExecucao extends Model
{
    protected $table = 'motor_execucao';

    public $timestamps = false;

    /** @var list<string> */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'data_calculo' => 'date',
            'iniciado_em' => 'datetime',
            'finalizado_em' => 'datetime',
            'falhas' => 'array',
        ];
    }

    /** @return HasMany<MtmDiario, $this> */
    public function mtmDiarios(): HasMany
    {
        return $this->hasMany(MtmDiario::class, 'execucao_id');
    }
}
