<?php

namespace App\Models;

use App\Models\Concerns\ConverteDecimais;
use Illuminate\Database\Eloquent\Model;

/**
 * Tabela-filha de NDF (`posicao_ndf`) — só dados/casts (D-MVC-1, §4.4 (a)).
 * O cálculo vive em {@see Ndf}.
 *
 * @property int $posicao_id
 * @property string $taxa_contratada
 * @property string $valor_nocional
 * @property string $moeda_nocional
 */
class PosicaoNdf extends Model
{
    use ConverteDecimais;

    protected $table = 'posicao_ndf';

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
            'taxa_contratada' => 'decimal:6',
            'valor_nocional' => 'decimal:2',
        ];
    }
}
