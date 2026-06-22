<?php

namespace App\Livewire\Posicoes;

use App\Exceptions\ErroAplicacao;
use App\Models\Produto;
use App\Services\ServicoPosicoes;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Tela 5 (§6.1) — cadastro de posição com formulário que se adapta ao instrumento
 * (FUTURO/NDF/OPCAO/OTC). Reaproveita a `ServicoPosicoes` da API (as RNs vivem no
 * Service). Para OPCAO, as pernas são linhas dinâmicas (RN-004a..c).
 */
#[Layout('components.layouts.app')]
class FormNovaPosicao extends Component
{
    public string $tipo = 'FUTURO';

    // Comuns à mãe `posicao`.
    public ?int $produtoId = null;

    public string $mercado = 'BOLSA';

    public string $lado = 'COMPRADO';

    public ?string $quantidade = null;

    public string $dataEntrada = '';

    public string $dataVencimento = '';

    public ?string $contraparte = null;

    public ?string $observacoes = null;

    // FUTURO.
    public ?string $precoEntrada = null;

    public ?string $codigoContrato = null;

    // NDF.
    public ?string $taxaContratada = null;

    public ?string $valorNocional = null;

    public string $moedaNocional = 'USD';

    // OPCAO.
    public ?string $nomeEstrutura = null;

    /** @var list<array<string, mixed>> */
    public array $pernas = [];

    // OTC.
    public ?string $indexador = null;

    public ?string $premioOtc = null;

    public function mount(): void
    {
        $this->pernas = [$this->pernaVazia()];
    }

    public function updatedTipo(): void
    {
        $this->resetValidation();
    }

    public function adicionarPerna(): void
    {
        $this->pernas[] = $this->pernaVazia();
    }

    public function removerPerna(int $indice): void
    {
        unset($this->pernas[$indice]);
        $this->pernas = array_values($this->pernas);
    }

    public function salvar(ServicoPosicoes $servico): void
    {
        // payload() valida (rules() por instrumento) e monta o payload do Service.
        $payload = $this->payload();

        try {
            match ($this->tipo) {
                'FUTURO' => $servico->criarFuturo($payload),
                'NDF' => $servico->criarNdf($payload),
                'OPCAO' => $servico->criarOpcao($payload),
                'OTC' => $servico->criarOtc($payload),
            };
        } catch (ErroAplicacao $e) {
            $this->addError('tipo', $e->getMessage());

            return;
        }

        session()->flash('status', 'Posição cadastrada com sucesso.');
        $this->redirectRoute('posicoes.index', navigate: true);
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        $comum = [
            'produtoId' => ['required', 'integer', 'exists:produto,id'],
            'mercado' => ['required', 'in:BOLSA,BALCAO'],
            'lado' => ['required', 'in:COMPRADO,VENDIDO'],
            'dataEntrada' => ['required', 'date_format:Y-m-d'],
            'dataVencimento' => ['required', 'date_format:Y-m-d', 'after:dataEntrada'],
            'contraparte' => ['nullable', 'string', 'max:100', 'required_if:mercado,BALCAO'],
            'observacoes' => ['nullable', 'string'],
        ];

        $porTipo = match ($this->tipo) {
            'FUTURO' => [
                'quantidade' => ['required', 'numeric', 'gt:0'],
                'precoEntrada' => ['required', 'numeric', 'gt:0'],
                'codigoContrato' => ['required', 'string', 'max:20'],
            ],
            'NDF' => [
                'quantidade' => ['required', 'numeric', 'gt:0'],
                'taxaContratada' => ['required', 'numeric', 'gt:0'],
                'valorNocional' => ['required', 'numeric', 'gt:0'],
                'moedaNocional' => ['required', 'string', 'size:3'],
            ],
            'OPCAO' => [
                'nomeEstrutura' => ['nullable', 'string', 'max:60'],
                'pernas' => ['required', 'array', 'min:1'],
                'pernas.*.tipo_opcao' => ['required', 'in:CALL,PUT'],
                'pernas.*.estilo' => ['required', 'in:EUROPEIA,AMERICANA'],
                'pernas.*.strike' => ['required', 'numeric', 'gt:0'],
                'pernas.*.premio_pago' => ['required', 'numeric', 'gte:0'],
                'pernas.*.quantidade' => ['required', 'numeric', 'gt:0'],
                'pernas.*.lado' => ['required', 'in:COMPRADO,VENDIDO'],
            ],
            'OTC' => [
                'quantidade' => ['required', 'numeric', 'gt:0'],
                'precoEntrada' => ['required', 'numeric', 'gt:0'],
                'indexador' => ['required', 'string', 'max:30'],
                'premioOtc' => ['nullable', 'numeric'],
            ],
            default => [],
        };

        return $comum + $porTipo;
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        $this->validate();

        $base = [
            'produto_id' => $this->produtoId,
            'mercado' => $this->mercado,
            'lado' => $this->lado,
            'quantidade' => $this->quantidade,
            'data_entrada' => $this->dataEntrada,
            'data_vencimento' => $this->dataVencimento,
            'contraparte' => $this->contraparte,
            'observacoes' => $this->observacoes,
        ];

        return $base + match ($this->tipo) {
            'FUTURO' => [
                'preco_entrada' => $this->precoEntrada,
                'codigo_contrato' => $this->codigoContrato,
            ],
            'NDF' => [
                'taxa_contratada' => $this->taxaContratada,
                'valor_nocional' => $this->valorNocional,
                'moeda_nocional' => $this->moedaNocional,
            ],
            'OPCAO' => [
                'nome_estrutura' => $this->nomeEstrutura,
                'pernas' => $this->pernas,
            ],
            'OTC' => [
                'preco_entrada' => $this->precoEntrada,
                'indexador' => $this->indexador,
                'premio_otc' => $this->premioOtc ?? 0,
            ],
            default => [],
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function pernaVazia(): array
    {
        return [
            'tipo_opcao' => 'CALL',
            'estilo' => 'EUROPEIA',
            'strike' => null,
            'premio_pago' => null,
            'quantidade' => null,
            'lado' => 'COMPRADO',
        ];
    }

    public function render(): mixed
    {
        return view('livewire.posicoes.form-nova-posicao', [
            'produtos' => Produto::query()->where('ativo', true)->orderBy('nome')->get(),
        ]);
    }
}
