<?php

namespace App\Concerns;

use App\Models\Usuario;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

trait ProfileValidationRules
{
    /**
     * Get the validation rules used to validate user profiles.
     *
     * @return array<string, array<int, ValidationRule|array<mixed>|string>>
     */
    protected function profileRules(?int $userId = null): array
    {
        return [
            'nome' => $this->nomeRules(),
            'login' => $this->loginRules($userId),
        ];
    }

    /**
     * Get the validation rules used to validate user nomes.
     *
     * @return array<int, ValidationRule|array<mixed>|string>
     */
    protected function nomeRules(): array
    {
        return ['required', 'string', 'max:255'];
    }

    /**
     * Get the validation rules used to validate user logins.
     *
     * @return array<int, ValidationRule|array<mixed>|string>
     */
    protected function loginRules(?int $userId = null): array
    {
        return [
            'required',
            'string',
            'login',
            'max:255',
            $userId === null
                ? Rule::unique(Usuario::class)
                : Rule::unique(Usuario::class)->ignore($userId),
        ];
    }
}
