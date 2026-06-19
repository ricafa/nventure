<?php

use App\Exceptions\ErroValidacao;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Request;
use Tests\TestCase;

// Precisa do app Laravel (handler de bootstrap/app.php), mas não do banco.
uses(TestCase::class);

it('renderiza ErroValidacao no envelope §5.1 com status 422', function () {
    $request = Request::create('/api/v1/qualquer', 'GET');
    $request->headers->set('Accept', 'application/json');

    $response = app(ExceptionHandler::class)
        ->render($request, new ErroValidacao('Campo obrigatório ausente.'));

    expect($response->getStatusCode())->toBe(422);
    expect(json_decode((string) $response->getContent(), true))->toBe([
        'erro' => 'ERRO_VALIDACAO',
        'mensagem' => 'Campo obrigatório ausente.',
    ]);
});
