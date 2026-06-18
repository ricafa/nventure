<?php

use App\Models\Usuario;

test('confirm senha_hash screen can be rendered', function () {
    $user = Usuario::factory()->create();

    $response = $this->actingAs($user)->get(route('senha_hash.confirm'));

    $response->assertOk();
});
