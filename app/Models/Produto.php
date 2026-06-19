<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Cadastro mestre de commodities (`produto`, §3.2.1).
 *
 * @property int $id
 * @property string $nome
 * @property string $unidade
 * @property string $bolsa_ref
 * @property string $moeda_cotacao
 * @property bool $ativo
 * @property Carbon $criado_em
 * @property-read Collection<int, PrecoReferencia> $precos
 * @property-read Collection<int, Posicao> $posicoes
 */
class Produto extends Model
{
    protected $table = 'produto';

    public $timestamps = false;

    /** @var list<string> */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'ativo' => 'boolean',
            'criado_em' => 'datetime',
        ];
    }

    /** @return HasMany<PrecoReferencia, $this> */
    public function precos(): HasMany
    {
        return $this->hasMany(PrecoReferencia::class, 'produto_id');
    }

    /** @return HasMany<Posicao, $this> */
    public function posicoes(): HasMany
    {
        return $this->hasMany(Posicao::class, 'produto_id');
    }
}
