<?php

namespace App\Models;

use Database\Factories\PrecoReferenciaFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
 * @property-read Collection<int, MtmDiario> $mtms
 */
class PrecoReferencia extends Model
{
    /** @use HasFactory<PrecoReferenciaFactory> */
    use HasFactory;

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

    /**
     * MtMs que referenciam este preço (FK `preco_ref_id`) — base da RN-010a:
     * um preço já usado em cálculo de MtM não pode ser removido.
     *
     * @return HasMany<MtmDiario, $this>
     */
    public function mtms(): HasMany
    {
        return $this->hasMany(MtmDiario::class, 'preco_ref_id');
    }
}
