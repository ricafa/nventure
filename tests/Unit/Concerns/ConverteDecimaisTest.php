<?php

use App\Models\Concerns\ConverteDecimais;

/*
| Trait puro (D-202/D-304): exercido sobre primitivos (string/int/float/null), sem
| instanciar Eloquent nem abrir conexão. Classe anônima só expõe o trait estaticamente.
*/

$c = new class
{
    use ConverteDecimais;
};

it('paraFloat normaliza string/int/float e trata null como 0.0', function () use ($c) {
    expect($c::paraFloat('1418.6500'))->toBe(1418.65)
        ->and($c::paraFloat(1400))->toBe(1400.0)
        ->and($c::paraFloat(1400.5))->toBe(1400.5)
        ->and($c::paraFloat(null))->toBe(0.0);
});

it('arredonda à escala da coluna (2/4/6 casas)', function () use ($c) {
    expect($c::arredonda(1410.56789, 2))->toBe(1410.57)
        ->and($c::arredonda(1410.56789, 4))->toBe(1410.5679)
        ->and($c::arredonda(1410.567891, 6))->toBe(1410.567891)
        ->and($c::arredonda(1410.5))->toBe(1410.5);        // default 4 casas
});
