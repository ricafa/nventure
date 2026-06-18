<?php

use Laravel\Fortify\Features;

beforeEach(function () {
    $this->skipUnlessFortifyHas(Features::registration());
});

test('registration screen can be rendered', function () {
    $response = $this->get(route('register'));

    $response->assertOk();
});

test('new usuario can register', function () {
    $response = $this->post(route('register.store'), [
        'nome' => 'John Doe',
        'login' => 'test@example.com',
        'senha_hash' => 'senha_hash',
        'senha_hash_confirmation' => 'senha_hash',
    ]);

    $response->assertSessionHasNoErrors()
        ->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticated();
});
