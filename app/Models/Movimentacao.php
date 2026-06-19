<?php

namespace App\Models;

use App\Models\Concerns\ConverteDecimais;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Movimentação de FUTURO (`posicao_movimentacao`, §3.2.4a). Imutável por design
 * (RN-025, D-203): não há setters de negócio nem métodos de escrita; a criação da
 * ABERTURA/AUMENTO/REDUCAO é responsabilidade do Service (Fase 5), em transação.
 *
 * @property int $id
 * @property int $posicao_id
 * @property string $tipo
 * @property Carbon $data_movimentacao
 * @property string $quantidade
 * @property string $preco
 * @property Carbon $criado_em
 * @property string $criado_por
 */
class Movimentacao extends Model
{
    use ConverteDecimais;

    protected $table = 'posicao_movimentacao';

    public $timestamps = false;

    const CREATED_AT = 'criado_em';

    /** @var list<string> */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'data_movimentacao' => 'date',
            'quantidade' => 'decimal:4',
            'preco' => 'decimal:6',
            'criado_em' => 'datetime',
        ];
    }

    /** @return BelongsTo<Posicao, $this> */
    public function posicao(): BelongsTo
    {
        return $this->belongsTo(Posicao::class, 'posicao_id');
    }
}
