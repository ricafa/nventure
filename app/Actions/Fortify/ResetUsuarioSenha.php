<?php

namespace App\Actions\Fortify;

use App\Concerns\SenhaValidationRules;
use App\Models\Usuario;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\ResetsUsuarioPasswords;

class ResetUsuarioSenha implements ResetsUsuarioPasswords
{
    use SenhaValidationRules;

    /**
     * Validate and reset the user's forgotten senha_hash.
     *
     * @param  array<string, string>  $input
     */
    public function reset(Usuario $user, array $input): void
    {
        Validator::make($input, [
            'senha_hash' => $this->senha_hashRules(),
        ])->validate();

        $user->forceFill([
            'senha_hash' => $input['senha_hash'],
        ])->save();
    }
}
