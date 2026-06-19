<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Tabela-filha de OPCAO (`posicao_opcao`) — só dados (D-MVC-1, §4.4 (a)).
 * As pernas e o cálculo vivem em {@see Opcao}.
 *
 * @property int $posicao_id
 * @property string|null $nome_estrutura
 */
class PosicaoOpcao extends Model
{
    protected $table = 'posicao_opcao';

    protected $primaryKey = 'posicao_id';

    public $incrementing = false;

    public $timestamps = false;

    /** @var list<string> */
    protected $guarded = [];
}
