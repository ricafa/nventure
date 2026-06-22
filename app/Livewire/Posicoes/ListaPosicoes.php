<?php

namespace App\Livewire\Posicoes;

use App\Models\Produto;
use App\Services\ServicoPosicoes;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Tela 6 (§6.1) — listagem de posições com filtros (status/tipo/produto) e detalhe.
 * O cadastro vive em `/posicoes/nova` ({@see FormNovaPosicao}); o detalhe e a ação
 * Movimentar ficam no componente filho {@see DetalhePosicao}, aberto por evento.
 * As colunas de MtM (hoje/Δ) são da Fase 6 e não aparecem aqui.
 */
#[Layout('components.layouts.app')]
class ListaPosicoes extends Component
{
    use WithPagination;

    #[Url]
    public string $status = 'ABERTA';

    #[Url]
    public string $tipo = 'TODOS';

    #[Url]
    public ?int $produtoId = null;

    public function updating(): void
    {
        $this->resetPage();
    }

    #[On('posicoes-alterados')]
    public function recarregar(): void
    {
        // Re-render automático após o handler do evento.
    }

    public function render(): mixed
    {
        $posicoes = app(ServicoPosicoes::class)->listar(
            status: $this->status === 'TODAS' ? null : $this->status,
            produtoId: $this->produtoId,
        );

        // Filtro por instrumento na borda da UI (o Service filtra status/produto, §5.2.3).
        if ($this->tipo !== 'TODOS') {
            $posicoes->setCollection(
                $posicoes->getCollection()->filter(fn ($p) => $p->instrumento === $this->tipo)->values(),
            );
        }

        return view('livewire.posicoes.lista-posicoes', [
            'posicoes' => $posicoes,
            'produtos' => Produto::query()->orderBy('nome')->pluck('nome', 'id'),
        ]);
    }
}
