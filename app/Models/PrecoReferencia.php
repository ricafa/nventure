<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Preço de fechamento diário do produto (`preco_referencia`, §3.2.2).
 * `vol_implicita`/`taxa_juros` são persistidos mas não usados no MVP.
 *
 * @property int $id
 * @property int $produto_id
 * @property Carbon $data_preco
 * @property string $preco_fechamento
 * @property string $cambio_brl
 * @property string|null $vol_implicita
 * @property string|null $taxa_juros
 * @property Carbon $criado_em
 * @property-read Produto $produto
 */
class PrecoReferencia extends Model
{
    protected $table = 'preco_referencia';

    public $timestamps = false;

    /** @var list<string> */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'data_preco' => 'date',
            'preco_fechamento' => 'decimal:6',
            'cambio_brl' => 'decimal:6',
            'vol_implicita' => 'decimal:4',
            'taxa_juros' => 'decimal:4',
            'criado_em' => 'datetime',
        ];
    }

    /** @return BelongsTo<Produto, $this> */
    public function produto(): BelongsTo
    {
        return $this->belongsTo(Produto::class, 'produto_id');
    }
}
