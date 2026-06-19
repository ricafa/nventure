<?php

namespace App\Models;

use Database\Factories\UsuarioFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\HasApiTokens;

/**
 * Usuário da aplicação (`usuario`, §3.2.10, D-205). Autentica por `login` e usa
 * `senha_hash` como coluna de senha — daí o override de `getAuthPassword()` **e**
 * `getAuthPasswordName()` (o Fortify/Auth recente usa o segundo internamente).
 *
 * @property int $id
 * @property string $login
 * @property string $nome
 * @property string $senha_hash
 * @property string $perfil
 * @property bool $ativo
 * @property Carbon $criado_em
 */
#[Fillable(['login', 'nome', 'senha_hash', 'perfil', 'ativo'])]
#[Hidden(['senha_hash', 'remember_token'])]
class Usuario extends Authenticatable
{
    /** @use HasFactory<UsuarioFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    protected $table = 'usuario';

    public $timestamps = false;

    public function getAuthPassword(): string
    {
        return (string) $this->senha_hash;
    }

    public function getAuthPasswordName(): string
    {
        return 'senha_hash';
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'ativo' => 'boolean',
            'criado_em' => 'datetime',
            'senha_hash' => 'hashed',
        ];
    }
}
