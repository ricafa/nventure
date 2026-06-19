<?php

use App\Exceptions\ErroAplicacao;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Envelope §5.1 — exclusivo da API REST. Só responde JSON quando a
        // requisição espera JSON (ou está sob o grupo `api`); requisições
        // web/Livewire mantêm as páginas de erro padrão do Laravel (D-605).
        $exceptions->render(function (ErroAplicacao $e, Request $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json($e->envelope(), $e->statusHttp());
            }

            return null;
        });
    })->create();
