<?php

use App\Models\Usuario;
use Laravel\Fortify\Features;

beforeEach(function () {
    $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());
});

test('two factor challenge redirects to login when not authenticated', function () {
    $response = $this->get(route('two-factor.login'));

    $response->assertRedirect(route('login'));
});

test('two factor challenge can be rendered', function () {
    Features::twoFactorAuthentication([
        'confirm' => true,
        'confirmSenha' => true,
    ]);

    $user = Usuario::factory()->withTwoFactor()->create();

    $this->post(route('login.store'), [
        'login' => $user->login,
        'senha_hash' => 'senha_hash',
    ])->assertRedirect(route('two-factor.login'));
});
