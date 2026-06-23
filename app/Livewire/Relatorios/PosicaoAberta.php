<?php

declare(strict_types=1);

namespace App\Livewire\Relatorios;

use App\Services\ServicoRelatorios;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Relatório de posição aberta (RN-016, D-708). Seletor de `data`, alternância de
 * agrupamento (produto/tipo), totais e tabela (PM só para FUTURO). Injeta o Service
 * (sem HTTP). Espelha `RelPosicaoAbertaScreen`.
 */
#[Layout('components.layouts.app')]
class PosicaoAberta extends Component
{
    #[Url]
    public string $data = '';

    #[Url]
    public string $agrupar = 'produto';   // 'produto' | 'tipo'

    public function mount(): void
    {
        if ($this->data === '') {
            $this->data = now()->toDateString();
        }
    }

    public function render(ServicoRelatorios $rel): mixed
    {
        $relatorio = $rel->posicaoAberta($this->data);

        // Agrupa para a UI (produto ou instrumento). Mantém o Service agnóstico de UI.
        $grupos = collect($relatorio->linhas)->groupBy(
            fn ($l) => $this->agrupar === 'tipo' ? $l->instrumento : $l->produtoNome
        );

        return view('livewire.relatorios.posicao-aberta', [
            'relatorio' => $relatorio,
            'grupos' => $grupos,
        ]);
    }
}
