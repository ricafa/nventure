<?php

declare(strict_types=1);

namespace App\Livewire\Relatorios;

use App\Services\ServicoRelatorios;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Relatório de P&L (RN-017/018, D-708): cartões (acumulado, do dia, melhor/pior pregão),
 * série temporal e detalhe por posição. Injeta o Service (sem HTTP). Espelha `RelPLScreen`.
 */
#[Layout('components.layouts.app')]
class PL extends Component
{
    #[Url]
    public string $data = '';

    public function mount(): void
    {
        if ($this->data === '') {
            $this->data = now()->toDateString();
        }
    }

    public function render(ServicoRelatorios $rel): mixed
    {
        $resumo = $rel->plDiario($this->data);

        $melhor = collect($resumo->serie)->max('pl_dia') ?? 0.0;
        $pior = collect($resumo->serie)->min('pl_dia') ?? 0.0;

        return view('livewire.relatorios.pl', [
            'resumo' => $resumo,
            'posicoes' => $rel->posicaoAberta($this->data)->linhas,
            'melhorPregao' => (float) $melhor,
            'piorPregao' => (float) $pior,
        ]);
    }
}
