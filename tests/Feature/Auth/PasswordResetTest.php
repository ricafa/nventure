<?php

use App\Models\Usuario;
use Illuminate\Auth\Notifications\ResetSenha;
use Illuminate\Support\Facades\Notification;
use Laravel\Fortify\Features;

beforeEach(function () {
    $this->skipUnlessFortifyHas(Features::resetSenhas());
});

test('reset senha_hash link screen can be rendered', function () {
    $response = $this->get(route('senha_hash.request'));

    $response->assertOk();
});

test('reset senha_hash link can be requested', function () {
    Notification::fake();

    $user = Usuario::factory()->create();

    $this->post(route('senha_hash.request'), ['login' => $user->login]);

    Notification::assertSentTo($user, ResetSenha::class);
});

test('reset senha_hash screen can be rendered', function () {
    Notification::fake();

    $user = Usuario::factory()->create();

    $this->post(route('senha_hash.request'), ['login' => $user->login]);

    Notification::assertSentTo($user, ResetSenha::class, function ($notification) {
        $response = $this->get(route('senha_hash.reset', $notification->token));

        $response->assertOk();

        return true;
    });
});

test('senha_hash can be reset with valid token', function () {
    Notification::fake();

    $user = Usuario::factory()->create();

    $this->post(route('senha_hash.request'), ['login' => $user->login]);

    Notification::assertSentTo($user, ResetSenha::class, function ($notification) use ($user) {
        $response = $this->post(route('senha_hash.update'), [
            'token' => $notification->token,
            'login' => $user->login,
            'senha_hash' => 'senha_hash',
            'senha_hash_confirmation' => 'senha_hash',
        ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('login', absolute: false));

        return true;
    });
});
