<?php

use App\Models\Futuro;
use App\Models\Ndf;
use App\Models\Opcao;
use App\Models\Otc;
use App\Models\Posicao;
use Tests\TestCase;

/*
| newFromBuilder direto, SEM banco (D-306): chama o método com um stdClass de atributos e
| afirma a subclasse retornada. O único match($instrumento) da hierarquia vive aqui. A
| hidratação com banco real (Posicao::query()->get()) permanece em Feature/ (Fase 2).
*/
uses(TestCase::class);

dataset('instrumentos', [
    'FUTURO' => ['FUTURO', Futuro::class],
    'NDF' => ['NDF', Ndf::class],
    'OPCAO' => ['OPCAO', Opcao::class],
    'OTC' => ['OTC', Otc::class],
]);

it('newFromBuilder devolve a subclasse por instrumento (sem banco)', function (string $instr, string $classe) {
    $model = (new Posicao)->newFromBuilder((object) ['instrumento' => $instr, 'lado' => 'COMPRADO']);

    expect($model)->toBeInstanceOf($classe);
})->with('instrumentos');

it('newFromBuilder cai na base quando o instrumento é desconhecido/nulo', function () {
    $model = (new Posicao)->newFromBuilder((object) ['instrumento' => 'XPTO']);

    expect($model)->toBeInstanceOf(Posicao::class)
        ->and($model)->not->toBeInstanceOf(Futuro::class);
});
