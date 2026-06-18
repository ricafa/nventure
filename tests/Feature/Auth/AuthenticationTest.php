<?php

use App\Models\Usuario;
use Laravel\Fortify\Features;

test('login screen can be rendered', function () {
    $response = $this->get(route('login'));

    $response->assertOk();
});

test('usuario can authenticate using the login screen', function () {
    $user = Usuario::factory()->create();

    $response = $this->post(route('login.store'), [
        'login' => $user->login,
        'senha_hash' => 'senha_hash',
    ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticated();
});

test('usuario can not authenticate with invalid senha_hash', function () {
    $user = Usuario::factory()->create();

    $response = $this->post(route('login.store'), [
        'login' => $user->login,
        'senha_hash' => 'wrong-senha_hash',
    ]);

    $response->assertSessionHasErrorsIn('login');

    $this->assertGuest();
});

test('usuario with two factor enabled are redirected to two factor challenge', function () {
    $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());

    Features::twoFactorAuthentication([
        'confirm' => true,
        'confirmSenha' => true,
    ]);

    $user = Usuario::factory()->withTwoFactor()->create();

    $response = $this->post(route('login.store'), [
        'login' => $user->login,
        'senha_hash' => 'senha_hash',
    ]);

    $response->assertRedirect(route('two-factor.login'));
    $this->assertGuest();
});

test('usuario can logout', function () {
    $user = Usuario::factory()->create();

    $response = $this->actingAs($user)->post(route('logout'));

    $response->assertRedirect(route('home'));

    $this->assertGuest();
});
