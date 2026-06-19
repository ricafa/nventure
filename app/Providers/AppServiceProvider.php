<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // -------------------------------------------------------------------
        // Bindings / Singletons de serviços (origem das Facades — app/Facades)
        // -------------------------------------------------------------------
        // Os serviços de aplicação (ServicoProdutos, ServicoPrecos,
        // ServicoPosicoes, ServicoMotor, …) e suas Facades convenientes
        // (Posicoes, Motor, …) chegam a partir da Fase 4. Registrar aqui, ex.:
        //
        //     $this->app->singleton(\App\Services\ServicoMotor::class);
        //
        // Por ora nenhum serviço existe; região reservada (esqueleto).
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
