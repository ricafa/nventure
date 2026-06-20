<?php

namespace App\Livewire\Precos;

use App\Exceptions\ErroAplicacao;
use App\Services\ServicoPrecos;
use App\Services\ServicoProdutos;
use App\Support\Csv\ImportadorPrecosCsv;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Tela 4 (§6.1) — lançamento de preços: (a) form manual; (b) upload CSV com
 * relatório aceitas/rejeitadas; e listagem filtrável com remoção (RN-010a → 409
 * tratado como mensagem). Reaproveita `ServicoPrecos`/`ServicoProdutos` da API.
 */
#[Layout('components.layouts.app')]
class LancamentoPrecos extends Component
{
    use WithFileUploads;

    // Form manual
    public ?int $produto_id = null;

    public string $data_preco = '';

    public string $preco_fechamento = '';

    public string $cambio_brl = '';

    // Upload CSV
    public mixed $arquivo = null;

    /** @var array{total: int, aceitas: int, rejeitadas: list<array{linha: int, motivo: string}>}|null */
    public ?array $resultadoImportacao = null;

    // Filtros da listagem
    public ?int $filtroProduto = null;

    public string $filtroInicio = '';

    public string $filtroFim = '';

    public function lancar(ServicoPrecos $servico): void
    {
        $dados = $this->validate([
            'produto_id' => ['required', 'integer'],
            'data_preco' => ['required', 'date_format:Y-m-d'],
            'preco_fechamento' => ['required', 'numeric', 'gt:0'],
            'cambio_brl' => ['required', 'numeric', 'gt:0'],
        ]);

        try {
            $servico->lancar($dados);
        } catch (ErroAplicacao $e) {
            $this->addError('produto_id', $e->getMessage());

            return;
        }

        $this->reset(['data_preco', 'preco_fechamento', 'cambio_brl']);
    }

    public function importar(ServicoPrecos $servico): void
    {
        $this->validate(['arquivo' => ['required', 'file', 'max:2048']]);

        $resultado = $servico->importar(new ImportadorPrecosCsv($this->arquivo->getRealPath()));

        $this->resultadoImportacao = $resultado->paraArray();
        $this->reset('arquivo');
    }

    public function remover(int $id, ServicoPrecos $servico): void
    {
        try {
            $servico->remover($id);
        } catch (ErroAplicacao $e) {
            $this->addError('remocao', $e->getMessage());
        }
    }

    public function render(): mixed
    {
        return view('livewire.precos.lancamento-precos', [
            'produtos' => app(ServicoProdutos::class)->listar(apenasAtivos: true),
            'precos' => app(ServicoPrecos::class)->listar(
                $this->filtroProduto ?: null,
                $this->filtroInicio ?: null,
                $this->filtroFim ?: null,
            ),
        ]);
    }
}
