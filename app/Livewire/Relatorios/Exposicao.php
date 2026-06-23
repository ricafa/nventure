<?php

declare(strict_types=1);

namespace App\Livewire\Relatorios;

use App\Services\ServicoRelatorios;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Relatório de exposição líquida por produto (RN-019, D-708/D-705a): comprado×vendido×
 * líquido, mix de instrumentos e aviso quando `unidade_mista=true`. Injeta o Service
 * (sem HTTP). Espelha `RelExposicaoScreen`.
 */
#[Layout('components.layouts.app')]
class Exposicao extends Component
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
        return view('livewire.relatorios.exposicao', [
            'data' => $this->data,
            'produtos' => $rel->exposicaoLiquida($this->data),
        ]);
    }
}
