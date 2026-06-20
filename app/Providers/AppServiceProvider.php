<?php

namespace App\Providers;

use App\Services\ServicoPrecos;
use App\Services\ServicoProdutos;
use Illuminate\Database\Eloquent\Model;
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
        // Os serviços de aplicação (ServicoPosicoes, ServicoMotor, …) e suas
        // Facades convenientes (Posicoes, Motor, …) chegam nas próximas fases.
        // Parte 4 — Produtos & Preços (D-410):
        $this->app->singleton(ServicoProdutos::class);
        $this->app->singleton(ServicoPrecos::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Em dev/teste, acessar relação não carregada estoura (em vez de lazy loading
        // silencioso). Força os Services a fazer eager loading e protege o laço do motor
        // (D-206, §9.1). Desligado em produção para não derrubar uma requisição por uma
        // relação esquecida.
        Model::preventLazyLoading(! $this->app->isProduction());
    }
}
