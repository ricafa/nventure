<?php

namespace App\Livewire\Produtos;

use App\Exceptions\ErroAplicacao;
use App\Services\ServicoProdutos;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Tela 3 (§6.1) — formulário de criar/editar produto, embutido em {@see ListaProdutos}.
 * Validação estrutural local; as RNs (nome único etc.) chegam como `ErroAplicacao`
 * do Service e viram mensagem amigável (sem stack).
 */
class FormProduto extends Component
{
    public ?int $produtoId = null;

    public bool $aberto = false;

    public string $nome = '';

    public string $unidade = '';

    public string $bolsa_ref = '';

    public string $moeda_cotacao = '';

    public bool $ativo = true;

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        return [
            'nome' => ['required', 'string', 'max:60'],
            'unidade' => ['required', 'string', 'max:20'],
            'bolsa_ref' => ['required', 'string', 'max:20'],
            'moeda_cotacao' => ['required', 'string', 'size:3'],
            'ativo' => ['boolean'],
        ];
    }

    #[On('novo-produto')]
    public function novo(): void
    {
        $this->reset(['produtoId', 'nome', 'unidade', 'bolsa_ref', 'moeda_cotacao']);
        $this->ativo = true;
        $this->resetValidation();
        $this->aberto = true;
    }

    #[On('editar-produto')]
    public function editar(int $id, ServicoProdutos $servico): void
    {
        $produto = $servico->buscar($id);

        $this->produtoId = $produto->id;
        $this->nome = $produto->nome;
        $this->unidade = $produto->unidade;
        $this->bolsa_ref = $produto->bolsa_ref;
        $this->moeda_cotacao = $produto->moeda_cotacao;
        $this->ativo = $produto->ativo;
        $this->resetValidation();
        $this->aberto = true;
    }

    public function salvar(ServicoProdutos $servico): void
    {
        $dados = $this->validate();

        try {
            $this->produtoId === null
                ? $servico->criar($dados)
                : $servico->atualizar($this->produtoId, $dados);
        } catch (ErroAplicacao $e) {
            $this->addError('nome', $e->getMessage());

            return;
        }

        $this->aberto = false;
        $this->dispatch('produtos-alterados');
    }

    public function cancelar(): void
    {
        $this->aberto = false;
        $this->resetValidation();
    }

    public function render(): mixed
    {
        return view('livewire.produtos.form-produto');
    }
}
