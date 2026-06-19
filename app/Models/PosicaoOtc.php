<?php

namespace App\Models;

use App\Models\Concerns\ConverteDecimais;
use Illuminate\Database\Eloquent\Model;

/**
 * Tabela-filha de OTC (`posicao_otc`) — só dados/casts (D-MVC-1, §4.4 (a)).
 * O cálculo vive em {@see Otc}.
 *
 * @property int $posicao_id
 * @property string $preco_entrada
 * @property string $indexador
 * @property string $premio_otc
 */
class PosicaoOtc extends Model
{
    use ConverteDecimais;

    protected $table = 'posicao_otc';

    protected $primaryKey = 'posicao_id';

    public $incrementing = false;

    public $timestamps = false;

    /** @var list<string> */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'preco_entrada' => 'decimal:6',
            'premio_otc' => 'decimal:6',
        ];
    }
}
