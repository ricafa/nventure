<?php

namespace App\Services;

use App\Exceptions\ErroConflito;
use App\Exceptions\ErroNaoEncontrado;
use App\Exceptions\ErroValidacao;
use App\Models\PrecoReferencia;
use App\Models\Produto;
use App\Services\Dados\ResultadoImportacao;
use App\Support\Csv\FontePrecos;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;

/**
 * Lançamento, listagem, remoção e importação de preços de referência.
 *
 * RNs de negócio (D-403/D-607): RN-007 (unicidade produto+data → 409), RN-008/009
 * (preço/câmbio > 0 → 422), RN-010a (remover preço referenciado por mtm_diario →
 * 409). A importação (RN-010) depende da interface `FontePrecos` (D-406): linhas
 * inválidas viram rejeição no relatório, sem abortar o lote.
 */
class ServicoPrecos
{
    /** @return Collection<int, PrecoReferencia> */
    public function listar(?int $produtoId, ?string $dataInicio, ?string $dataFim): Collection
    {
        return PrecoReferencia::query()
            ->when($produtoId, fn ($q) => $q->where('produto_id', $produtoId))
            ->when($dataInicio, fn ($q) => $q->where('data_preco', '>=', $dataInicio))
            ->when($dataFim, fn ($q) => $q->where('data_preco', '<=', $dataFim))
            ->orderByDesc('data_preco')
            ->get();
    }

    /** @param array<string, mixed> $dados */
    public function lancar(array $dados): PrecoReferencia
    {
        $this->validarRegras($dados);   // RN-007/008/009 (atalho de UX + FK/positividade)

        try {
            return PrecoReferencia::query()->create([
                'produto_id' => $dados['produto_id'],
                'data_preco' => $dados['data_preco'],
                'preco_fechamento' => $dados['preco_fechamento'],
                'cambio_brl' => $dados['cambio_brl'],
            ]);
        } catch (QueryException $e) {           // RN-007 sob concorrência (A-1/D-412)
            $this->traduzConflitoData($e);
        }
    }

    /** RN-010a: bloquear remoção de preço já referenciado por mtm_diario → 409. */
    public function remover(int $id): void
    {
        $preco = PrecoReferencia::query()->find($id)
            ?? throw new ErroNaoEncontrado('Preço não encontrado.');

        if ($preco->mtms()->exists()) {
            throw new ErroConflito('Preço já utilizado em cálculo de MtM; não pode ser removido.');
        }

        $preco->delete();
    }

    /** Importa de uma fonte arbitrária (D-406). Linhas inválidas → rejeitadas (RN-010). */
    public function importar(FontePrecos $fonte): ResultadoImportacao
    {
        $resultado = new ResultadoImportacao;

        foreach ($fonte->ler() as $linha) {
            // A fonte já tipou/sanitizou; aqui só as RNs de negócio.
            if (($linha['_erro'] ?? null) !== null) {          // erro de parsing/segurança (D-407)
                $resultado->rejeitar((int) $linha['_linha'], (string) $linha['_erro']);

                continue;
            }
            try {
                $this->validarRegras($linha);
                try {
                    PrecoReferencia::query()->create([
                        'produto_id' => $linha['produto_id'],
                        'data_preco' => $linha['data_preco'],
                        'preco_fechamento' => $linha['preco_fechamento'],
                        'cambio_brl' => $linha['cambio_brl'],
                    ]);
                } catch (QueryException $e) {       // duplicata sob concorrência → ErroConflito
                    $this->traduzConflitoData($e);
                }
                $resultado->aceitar();
            } catch (ErroValidacao|ErroConflito $e) {
                $resultado->rejeitar((int) $linha['_linha'], $e->getMessage());
            }
        }

        return $resultado;
    }

    /** @param array<string, mixed> $dados */
    private function validarRegras(array $dados): void
    {
        if (! Produto::query()->whereKey($dados['produto_id'])->exists()) {
            throw new ErroValidacao('Produto inexistente.');                    // integridade de FK
        }
        if ((float) $dados['preco_fechamento'] <= 0) {
            throw new ErroValidacao('Preço deve ser maior que zero.');          // RN-008
        }
        if ((float) $dados['cambio_brl'] <= 0) {
            throw new ErroValidacao('Câmbio deve ser maior que zero.');         // RN-009
        }
        $existe = PrecoReferencia::query()
            ->where('produto_id', $dados['produto_id'])
            ->where('data_preco', $dados['data_preco'])
            ->exists();
        if ($existe) {
            throw new ErroConflito('Já existe preço para esse produto nessa data.'); // RN-007 → 409
        }
    }

    /** unique_violation (produto_id, data_preco) → 409; demais erros re-lançados. */
    private function traduzConflitoData(QueryException $e): never
    {
        if ($e->getCode() === '23505') {
            throw new ErroConflito('Já existe preço para esse produto nessa data.');
        }

        throw $e;
    }
}
