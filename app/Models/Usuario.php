<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyLogin;
use Database\Factories\UsuarioFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\Usuario as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * @property int $id
 * @property string $nome
 * @property string $login
 * @property string $senha_hash
 * @property string|null $perfil
 * @property bool $ativo
 * @property Carbon|null $criado_em
 */
#[Fillable(['nome', 'login', 'senha_hash', 'perfil', 'ativo'])]
#[Hidden(['senha_hash', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class Usuario extends Authenticatable implements PasskeyUser
{
    /** @use HasFactory<UsuarioFactory> */
    use HasFactory, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    protected $table = 'usuario';

    public $timestamps = false;

    /**
     * Get the senha_hash for the user.
     *
     * @return string
     */
    public function getAuthSenha()
    {
        return $this->senha_hash;
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'senha_hash' => 'hashed',
            'ativo' => 'boolean',
            'criado_em' => 'datetime',
        ];
    }

    /**
     * Get the user's initials
     */
    public function initials(): string
    {
        return Str::of($this->nome)
            ->explode(' ')
            ->take(2)
            ->map(fn ($word) => Str::substr($word, 0, 1))
            ->implode('');
    }
}
