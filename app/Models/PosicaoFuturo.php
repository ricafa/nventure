<?php

namespace App\Models;

use App\Models\Concerns\ConverteDecimais;
use Illuminate\Database\Eloquent\Model;

/**
 * Tabela-filha de FUTURO (`posicao_futuro`) — só dados/casts (D-MVC-1, §4.4 (a)).
 * O cálculo vive em {@see Futuro}.
 *
 * @property int $posicao_id
 * @property string $preco_entrada
 * @property string $codigo_contrato
 */
class PosicaoFuturo extends Model
{
    use ConverteDecimais;

    protected $table = 'posicao_futuro';

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
        ];
    }
}
