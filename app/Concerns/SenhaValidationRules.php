<?php

namespace App\Concerns;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rules\Senha;

trait SenhaValidationRules
{
    /**
     * Get the validation rules used to validate senha_hashs.
     *
     * @return array<int, Senha|ValidationRule|array<mixed>|string>
     */
    protected function senha_hashRules(): array
    {
        return ['required', 'string', Senha::default(), 'confirmed'];
    }

    /**
     * Get the validation rules used to validate the current senha_hash.
     *
     * @return array<int, Senha|ValidationRule|array<mixed>|string>
     */
    protected function currentSenhaRules(): array
    {
        return ['required', 'string', 'current_senha_hash'];
    }
}
