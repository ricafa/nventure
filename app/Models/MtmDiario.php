<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Histórico de marcação a mercado por posição por dia (`mtm_diario`, §3.2.8).
 * Persistido pelo motor via `updateOrCreate` (Fase 6); aqui só Model + casts + relações.
 *
 * @property int $id
 * @property int $posicao_id
 * @property int $preco_ref_id
 * @property Carbon $data_calculo
 * @property string $preco_mercado
 * @property string $mtm_valor
 * @property string $variacao_dia
 * @property string $pl_acumulado
 * @property int|null $execucao_id
 * @property Carbon $processado_em
 * @property-read Posicao $posicao
 * @property-read PrecoReferencia $precoReferencia
 * @property-read MotorExecucao|null $execucao
 */
class MtmDiario extends Model
{
    protected $table = 'mtm_diario';

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
            'preco_mercado' => 'decimal:6',
            'mtm_valor' => 'decimal:2',
            'variacao_dia' => 'decimal:2',
            'pl_acumulado' => 'decimal:2',
            'processado_em' => 'datetime',
        ];
    }

    /** @return BelongsTo<Posicao, $this> */
    public function posicao(): BelongsTo
    {
        return $this->belongsTo(Posicao::class, 'posicao_id');
    }

    /** @return BelongsTo<PrecoReferencia, $this> */
    public function precoReferencia(): BelongsTo
    {
        return $this->belongsTo(PrecoReferencia::class, 'preco_ref_id');
    }

    /** @return BelongsTo<MotorExecucao, $this> */
    public function execucao(): BelongsTo
    {
        return $this->belongsTo(MotorExecucao::class, 'execucao_id');
    }
}
