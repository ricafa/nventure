<?php

namespace App\Actions\Fortify;

use App\Concerns\ProfileValidationRules;
use App\Concerns\SenhaValidationRules;
use App\Models\Usuario;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUsuario implements CreatesNewUsers
{
    use ProfileValidationRules, SenhaValidationRules;

    /**
     * Validate and create a newly registered user.
     *
     * @param  array<string, string>  $input
     */
    public function create(array $input): Usuario
    {
        Validator::make($input, [
            ...$this->profileRules(),
            'senha_hash' => $this->senha_hashRules(),
        ])->validate();

        return Usuario::create([
            'nome' => $input['nome'],
            'login' => $input['login'],
            'senha_hash' => $input['senha_hash'],
        ]);
    }
}
