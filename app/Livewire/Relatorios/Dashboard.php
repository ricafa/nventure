<?php

declare(strict_types=1);

namespace App\Livewire\Relatorios;

use App\Models\MotorExecucao;
use App\Models\Posicao;
use App\Services\ServicoRelatorios;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Dashboard do dia (§6.1#2, D-708): P&L total/acumulado do dia, nº de posições abertas
 * e status da última execução do motor. Injeta `ServicoRelatorios` (sem auto-chamada
 * HTTP, padrão D-610). Espelha `DashboardScreen` (mock).
 */
#[Layout('components.layouts.app')]
class Dashboard extends Component
{
    public function render(ServicoRelatorios $rel): mixed
    {
        $hoje = now()->toDateString();
        $resumo = $rel->plDiario($hoje);

        return view('livewire.relatorios.dashboard', [
            'data' => $hoje,
            'plDiario' => $resumo->plDiario,
            'plAcumulado' => $resumo->plAcumulado,
            'posicoesAbertas' => Posicao::query()->where('status', 'ABERTA')->count(),
            'ultimaExecucao' => MotorExecucao::query()->orderByDesc('iniciado_em')->first(),
        ]);
    }
}
