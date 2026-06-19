<?php

use App\Models\Usuario;

it('redirects guests to the login page', function () {
    $this->get('/')
        ->assertRedirect('/login');
});

it('authenticates an active user with login and password', function () {
    $user = Usuario::factory()->create([
        'login' => 'operador',
        'senha_hash' => 'password',
        'ativo' => true,
    ]);

    $this->get('/login');

    $this->post('/login', [
        '_token' => session()->token(),
        'login' => 'operador',
        'password' => 'password',
    ])->assertRedirect('/dashboard');

    $this->assertAuthenticatedAs($user);
});

it('rejects inactive users', function () {
    Usuario::factory()->create([
        'login' => 'bloqueado',
        'senha_hash' => 'password',
        'ativo' => false,
    ]);

    $this->get('/login');

    $this->post('/login', [
        '_token' => session()->token(),
        'login' => 'bloqueado',
        'password' => 'password',
    ])->assertInvalid(['login']);

    $this->assertGuest();
});

it('does not expose public registration or password reset screens', function () {
    $this->get('/register')->assertNotFound();
    $this->get('/forgot-password')->assertNotFound();
});
