<?php

use App\Models\Usuario;
use Livewire\Livewire;

test('profile page is displayed', function () {
    $this->actingAs($user = Usuario::factory()->create());

    $this->get(route('profile.edit'))->assertOk();
});

test('profile information can be updated', function () {
    $user = Usuario::factory()->create();

    $this->actingAs($user);

    $response = Livewire::test('pages::settings.profile')
        ->set('nome', 'Test Usuario')
        ->set('login', 'test@example.com')
        ->call('updateProfileInformation');

    $response->assertHasNoErrors();

    $user->refresh();

    expect($user->nome)->toEqual('Test Usuario');
    expect($user->login)->toEqual('test@example.com');
    expect($user->login_verified_at)->toBeNull();
});

test('login verification status is unchanged when login address is unchanged', function () {
    $user = Usuario::factory()->create();

    $this->actingAs($user);

    $response = Livewire::test('pages::settings.profile')
        ->set('nome', 'Test Usuario')
        ->set('login', $user->login)
        ->call('updateProfileInformation');

    $response->assertHasNoErrors();

    expect($user->refresh()->login_verified_at)->not->toBeNull();
});

test('user can delete their account', function () {
    $user = Usuario::factory()->create();

    $this->actingAs($user);

    $response = Livewire::test('pages::settings.delete-user-modal')
        ->set('senha_hash', 'senha_hash')
        ->call('deleteUsuario');

    $response
        ->assertHasNoErrors()
        ->assertRedirect('/');

    expect($user->fresh())->toBeNull();
    expect(auth()->check())->toBeFalse();
});

test('correct senha_hash must be provided to delete account', function () {
    $user = Usuario::factory()->create();

    $this->actingAs($user);

    $response = Livewire::test('pages::settings.delete-user-modal')
        ->set('senha_hash', 'wrong-senha_hash')
        ->call('deleteUsuario');

    $response->assertHasErrors(['senha_hash']);

    expect($user->fresh())->not->toBeNull();
});
