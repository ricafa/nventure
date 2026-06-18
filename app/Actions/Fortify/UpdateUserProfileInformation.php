<?php

namespace App\Actions\Fortify;

use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\UpdatesUserProfileInformation;

class UpdateUserProfileInformation implements UpdatesUserProfileInformation
{
    /**
     * Validate and update the given user's profile information.
     *
     * @param  array<string, string>  $input
     *
     * @throws ValidationException
     */
    public function update(User $user, array $input): void
    {
        Validator::make($input, [
            'login' => [
                'required',
                'string',
                'max:60',
                Rule::unique('usuario', 'login')->ignore($user->id),
            ],
            'nome' => ['required', 'string', 'max:120'],
        ])->validateWithBag('updateProfileInformation');

        $user->forceFill([
            'login' => $input['login'],
            'nome' => $input['nome'],
        ])->save();
    }
}
