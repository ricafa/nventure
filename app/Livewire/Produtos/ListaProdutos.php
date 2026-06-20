<?php

namespace App\Livewire\Produtos;

use App\Services\ServicoProdutos;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Tela 3 (§6.1) — listagem de produtos com ações Editar/Inativar. O formulário de
 * criar/editar vive no componente filho {@see FormProduto}; este escuta o evento
 * `produtos-alterados` para re-renderizar. Reaproveita a mesma `ServicoProdutos`
 * da API (regras no Service).
 */
#[Layout('components.layouts.app')]
class ListaProdutos extends Component
{
    public function inativar(int $id, ServicoProdutos $servico): void
    {
        $servico->inativar($id);
    }

    #[On('produtos-alterados')]
    public function recarregar(): void
    {
        // A re-renderização acontece automaticamente após o handler do evento.
    }

    public function render(): mixed
    {
        return view('livewire.produtos.lista-produtos', [
            'produtos' => app(ServicoProdutos::class)->listar(),
        ]);
    }
}
