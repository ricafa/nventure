<?php

namespace App\Actions\Fortify;

use App\Models\Usuario;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules;

    /**
     * Validate and create a newly registered user.
     *
     * @param  array<string, string>  $input
     *
     * @throws ValidationException
     */
    public function create(array $input): Usuario
    {
        Validator::make($input, [
            'login' => [
                'required',
                'string',
                'max:60',
                Rule::unique(Usuario::class),
            ],
            'nome' => ['required', 'string', 'max:120'],
            'perfil' => ['required', Rule::in(['OPERADOR', 'GESTOR', 'ADMIN'])],
            'password' => $this->passwordRules(),
        ])->validate();

        return Usuario::create([
            'login' => $input['login'],
            'nome' => $input['nome'],
            'perfil' => $input['perfil'],
            'senha_hash' => $input['password'],
            'ativo' => true,
        ]);
    }
}
