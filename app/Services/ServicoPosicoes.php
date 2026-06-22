<?php

namespace App\Services;

use App\Exceptions\ErroConflito;
use App\Exceptions\ErroNaoEncontrado;
use App\Exceptions\ErroValidacao;
use App\Models\Futuro;
use App\Models\Ndf;
use App\Models\Opcao;
use App\Models\Otc;
use App\Models\Posicao;
use App\Models\Produto;
use App\Models\Usuario;
use App\Services\Dados\PosicaoDetalhe;
use App\Services\Dados\PosicaoResumo;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Cadastro dos 4 instrumentos, listagem paginada, deleção segura (D-502) e
 * encerramento manual (D-507).
 *
 * Cada `criar*` persiste a mãe `posicao` + a tabela-filha em transação, sempre
 * injetando `criado_por` (D-507) — nunca vindo do cliente. FUTURO ainda dispara a
 * `ABERTURA` automática (RN-020, via {@see ServicoMovimentacoes}). As RNs que exigem
 * lookup no banco (RN-006) vivem aqui; a validação estrutural fica nos Form Requests.
 */
class ServicoPosicoes
{
    public function __construct(private readonly ServicoMovimentacoes $movimentacoes) {}

    /**
     * Listagem paginada (50/página, §9.1) com os filtros da §5.2.3.
     *
     * @return LengthAwarePaginator<int, PosicaoResumo>
     */
    public function listar(?string $status = null, ?int $produtoId = null): LengthAwarePaginator
    {
        return Posicao::query()
            ->when($status, fn ($q) => $q->where('status', $status))
            ->when($produtoId, fn ($q) => $q->where('produto_id', $produtoId))
            ->orderByDesc('id')
            ->paginate(50)
            ->through(fn (Posicao $p) => PosicaoResumo::deModel($p));
    }

    /**
     * `findOrFail` hidrata a subclasse certa (via `newFromBuilder`); as relações da
     * tabela-filha são carregadas sob demanda dentro do DTO. Eager loading não é viável
     * aqui porque os nomes (`futuro`/`ndf`/…) só existem nas subclasses, não na base.
     */
    public function detalhar(int $id): PosicaoDetalhe
    {
        $posicao = Posicao::query()->find($id)
            ?? throw new ErroNaoEncontrado('Posição não encontrada.');

        return PosicaoDetalhe::deModel($posicao);
    }

    /** @param array<string, mixed> $dados */
    public function criarFuturo(array $dados): Posicao
    {
        return DB::transaction(function () use ($dados) {
            $criadoPor = $this->criadoPor(); // D-507; nunca vem do cliente

            // Cria pela subclasse para que a relação da tabela-filha exista (a base
            // `Posicao` não declara `futuro()`); `create()` não passa por newFromBuilder.
            $posicao = Futuro::query()->create($this->camposMae($dados, 'FUTURO') + [
                'criado_por' => $criadoPor,
            ]);

            $posicao->futuro()->create([
                'preco_entrada' => $dados['preco_entrada'],
                'codigo_contrato' => $dados['codigo_contrato'],
            ]);

            // RN-020: ABERTURA automática, mesma transação, data_movimentacao = data_entrada.
            $this->movimentacoes->criarAbertura($posicao, [
                'data_movimentacao' => $dados['data_entrada'],
                'quantidade' => $dados['quantidade'],
                'preco' => $dados['preco_entrada'],
                'criado_por' => $criadoPor,
            ]);

            return $posicao->load('futuro', 'movimentacoes');
        });
    }

    /** @param array<string, mixed> $dados */
    public function criarNdf(array $dados): Posicao
    {
        return DB::transaction(function () use ($dados) {
            $posicao = Ndf::query()->create($this->camposMae($dados, 'NDF') + [
                'criado_por' => $this->criadoPor(),
            ]);

            $posicao->ndf()->create([
                'taxa_contratada' => $dados['taxa_contratada'],
                'valor_nocional' => $dados['valor_nocional'],
                'moeda_nocional' => $dados['moeda_nocional'],
            ]);

            return $posicao->load('ndf');
        });
    }

    /** @param array<string, mixed> $dados */
    public function criarOpcao(array $dados): Posicao
    {
        return DB::transaction(function () use ($dados) {
            // RN-004e: a mãe OPCAO tem quantidade = 1 (sobrescreve o payload); lado informativo.
            $campos = $this->camposMae($dados, 'OPCAO');
            $campos['quantidade'] = 1;
            $campos['criado_por'] = $this->criadoPor();

            $posicao = Opcao::query()->create($campos);

            $posicao->opcao()->create([
                'nome_estrutura' => $dados['nome_estrutura'] ?? null,
            ]);

            // RN-004a/b/c: 1..N pernas, cada uma com quantidade e lado próprios.
            foreach (array_values($dados['pernas']) as $i => $perna) {
                $posicao->pernas()->create([
                    'sequencia' => $i + 1,
                    'tipo_opcao' => $perna['tipo_opcao'],
                    'estilo' => $perna['estilo'],
                    'strike' => $perna['strike'],
                    'premio_pago' => $perna['premio_pago'],
                    'quantidade' => $perna['quantidade'],
                    'lado' => $perna['lado'],
                ]);
            }

            return $posicao->load('opcao', 'pernas');
        });
    }

    /** @param array<string, mixed> $dados */
    public function criarOtc(array $dados): Posicao
    {
        // RN-006 — Service, não Form Request: exige lookup no banco.
        if (! Produto::query()->where('nome', $dados['indexador'])->exists()) {
            throw new ErroValidacao('Indexador não corresponde a um produto cadastrado.');
        }

        return DB::transaction(function () use ($dados) {
            $posicao = Otc::query()->create($this->camposMae($dados, 'OTC') + [
                'criado_por' => $this->criadoPor(),
            ]);

            $posicao->otc()->create([
                'preco_entrada' => $dados['preco_entrada'],
                'indexador' => $dados['indexador'],
                'premio_otc' => $dados['premio_otc'] ?? 0,
            ]);

            return $posicao->load('otc');
        });
    }

    /** Deleção só "virgem" (sem MtM); pós-MtM o caminho é `encerrar` (D-502). */
    public function remover(int $id): void
    {
        $posicao = Posicao::query()->find($id)
            ?? throw new ErroNaoEncontrado('Posição não encontrada.');

        if ($posicao->mtmDiarios()->exists()) { // D-502
            throw new ErroConflito('Posição já possui registro de MtM. Utilize o encerramento em vez de deletar.');
        }

        $posicao->delete(); // cascata apaga pernas/movimentações/filhas
    }

    /**
     * Encerramento manual (D-507) — idempotente. Para NDF/OPCAO/OTC (sem movimentações)
     * e para FUTURO sem redução total. Distinto do encerramento automático por RN-022 e
     * da VENCIDA (RN-014, Fase 6). MtM existente NÃO bloqueia (ao contrário do DELETE).
     */
    public function encerrar(int $id): Posicao
    {
        return DB::transaction(function () use ($id) {
            $posicao = Posicao::query()->lockForUpdate()->find($id)
                ?? throw new ErroNaoEncontrado('Posição não encontrada.');

            if ($posicao->status === 'ENCERRADA') {
                return $posicao; // idempotente
            }
            if ($posicao->status !== 'ABERTA') { // ex.: VENCIDA
                throw new ErroConflito('Apenas posições ABERTAS podem ser encerradas.');
            }

            $posicao->update(['status' => 'ENCERRADA']);

            return $posicao;
        });
    }

    /**
     * Campos comuns da mãe `posicao` (§3.2.3). `quantidade` entra aqui para
     * FUTURO/NDF/OTC; OPCAO a sobrescreve com 1 (RN-004e).
     *
     * @param  array<string, mixed>  $dados
     * @return array<string, mixed>
     */
    private function camposMae(array $dados, string $instrumento): array
    {
        return [
            'produto_id' => $dados['produto_id'],
            'instrumento' => $instrumento,
            'mercado' => $dados['mercado'],
            'lado' => $dados['lado'],
            'quantidade' => $dados['quantidade'] ?? 1,
            'data_entrada' => $dados['data_entrada'],
            'data_vencimento' => $dados['data_vencimento'],
            'contraparte' => $dados['contraparte'] ?? null,
            'observacoes' => $dados['observacoes'] ?? null,
        ];
    }

    /** Origem do autor da auditoria. Fase 10 endurece com perfil real (D-402/D-507). */
    private function criadoPor(): string
    {
        $usuario = Auth::user();

        return $usuario instanceof Usuario ? $usuario->login : 'sistema';
    }
}
