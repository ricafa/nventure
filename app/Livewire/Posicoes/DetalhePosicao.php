<?php

namespace App\Livewire\Posicoes;

use App\Exceptions\ErroAplicacao;
use App\Services\ServicoMovimentacoes;
use App\Services\ServicoPosicoes;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Detalhe de uma posição (modal) embutido em {@see ListaPosicoes}. Mostra a mãe + a
 * tabela-filha; para FUTURO ABERTA, expõe o formulário Movimentar (AUMENTO/REDUCAO) e
 * o histórico. As ações Encerrar (D-507) e Excluir (D-502) delegam ao Service e
 * propagam `posicoes-alterados`. Erros de RN chegam como `ErroAplicacao` e viram
 * mensagem amigável (sem stack).
 */
class DetalhePosicao extends Component
{
    public ?int $posicaoId = null;

    public bool $aberto = false;

    // Formulário Movimentar (somente FUTURO ABERTA).
    public string $tipo = 'AUMENTO';

    public string $dataMovimentacao = '';

    public ?string $quantidade = null;

    public ?string $preco = null;

    #[On('ver-posicao')]
    public function abrir(int $id): void
    {
        $this->resetValidation();
        $this->reset(['tipo', 'dataMovimentacao', 'quantidade', 'preco']);
        $this->tipo = 'AUMENTO';
        $this->posicaoId = $id;
        $this->aberto = true;
    }

    public function movimentar(ServicoMovimentacoes $servico): void
    {
        $dados = $this->validate([
            'tipo' => ['required', 'in:AUMENTO,REDUCAO'],
            'dataMovimentacao' => ['required', 'date_format:Y-m-d'],
            'quantidade' => ['required', 'numeric', 'gt:0'],
            'preco' => ['required', 'numeric', 'gt:0'],
        ]);

        try {
            $servico->movimentarFuturo((int) $this->posicaoId, [
                'tipo' => $dados['tipo'],
                'data_movimentacao' => $dados['dataMovimentacao'],
                'quantidade' => $dados['quantidade'],
                'preco' => $dados['preco'],
            ]);
        } catch (ErroAplicacao $e) {
            $this->addError('quantidade', $e->getMessage());

            return;
        }

        $this->reset(['quantidade', 'preco', 'dataMovimentacao']);
        $this->dispatch('posicoes-alterados');
    }

    public function encerrar(ServicoPosicoes $servico): void
    {
        try {
            $servico->encerrar((int) $this->posicaoId);
        } catch (ErroAplicacao $e) {
            $this->addError('quantidade', $e->getMessage());

            return;
        }

        $this->dispatch('posicoes-alterados');
    }

    public function excluir(ServicoPosicoes $servico): void
    {
        try {
            $servico->remover((int) $this->posicaoId);
        } catch (ErroAplicacao $e) {
            $this->addError('quantidade', $e->getMessage());

            return;
        }

        $this->aberto = false;
        $this->dispatch('posicoes-alterados');
    }

    public function fechar(): void
    {
        $this->aberto = false;
    }

    public function render(): mixed
    {
        // O DTO é recomputado a cada render (não é estado serializável do Livewire).
        $detalhe = $this->aberto && $this->posicaoId !== null
            ? app(ServicoPosicoes::class)->detalhar($this->posicaoId)
            : null;

        return view('livewire.posicoes.detalhe-posicao', ['detalhe' => $detalhe]);
    }
}
