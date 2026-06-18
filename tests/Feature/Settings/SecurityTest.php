<?php

use App\Models\Usuario;
use Illuminate\Support\Facades\Hash;
use Laravel\Fortify\Features;
use Livewire\Livewire;

beforeEach(function () {
    $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());

    Features::twoFactorAuthentication([
        'confirm' => true,
        'confirmSenha' => true,
    ]);
    Features::passkeys([
        'confirmSenha' => true,
    ]);
});

test('security settings page can be rendered', function () {
    $user = Usuario::factory()->create();

    $response = $this->actingAs($user)
        ->withSession(['auth.senha_hash_confirmed_at' => time()])
        ->get(route('security.edit'));

    $response->assertOk();

    $response->assertSee('Passkeys');
    $response->assertSee('No passkeys yet');
    $response->assertSee('Two-factor authentication');
    $response->assertSee('Enable 2FA');
});

test('security settings page requires senha_hash confirmation when enabled', function () {
    $user = Usuario::factory()->create();

    $response = $this->actingAs($user)
        ->get(route('security.edit'));

    $response->assertRedirect(route('senha_hash.confirm'));
});

test('security settings page renders without two factor when feature is disabled', function () {
    config(['fortify.features' => []]);

    $user = Usuario::factory()->create();

    $this->actingAs($user)
        ->withSession(['auth.senha_hash_confirmed_at' => time()])
        ->get(route('security.edit'))
        ->assertOk()
        ->assertSee('Update senha_hash')
        ->assertDontSee('Manage your passkeys for senha_hashless sign-in')
        ->assertDontSee('Add a passkey to sign in without a senha_hash')
        ->assertDontSee('Two-factor authentication');
});

test('two factor authentication disabled when confirmation abandoned between requests', function () {
    $user = Usuario::factory()->create();

    $user->forceFill([
        'two_factor_secret' => encrypt('test-secret'),
        'two_factor_recovery_codes' => encrypt(json_encode(['code1', 'code2'])),
        'two_factor_confirmed_at' => null,
    ])->save();

    $this->actingAs($user);

    $component = Livewire::test('pages::settings.security');

    $component->assertSet('twoFactorEnabled', false);

    $this->assertDatabaseHas('usuario', [
        'id' => $user->id,
        'two_factor_secret' => null,
        'two_factor_recovery_codes' => null,
    ]);
});

test('senha_hash can be updated', function () {
    $user = Usuario::factory()->create([
        'senha_hash' => Hash::make('senha_hash'),
    ]);

    $this->actingAs($user);

    $response = Livewire::test('pages::settings.security')
        ->set('current_senha_hash', 'senha_hash')
        ->set('senha_hash', 'new-senha_hash')
        ->set('senha_hash_confirmation', 'new-senha_hash')
        ->call('updateSenha');

    $response->assertHasNoErrors();

    expect(Hash::check('new-senha_hash', $user->refresh()->senha_hash))->toBeTrue();
});

test('correct senha_hash must be provided to update senha_hash', function () {
    $user = Usuario::factory()->create([
        'senha_hash' => Hash::make('senha_hash'),
    ]);

    $this->actingAs($user);

    $response = Livewire::test('pages::settings.security')
        ->set('current_senha_hash', 'wrong-senha_hash')
        ->set('senha_hash', 'new-senha_hash')
        ->set('senha_hash_confirmation', 'new-senha_hash')
        ->call('updateSenha');

    $response->assertHasErrors(['current_senha_hash']);
});
