<?php

namespace App\Services;

use App\Exceptions\ErroConflito;
use App\Exceptions\ErroNaoEncontrado;
use App\Models\Produto;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;

/**
 * CRUD de produtos diretamente em Eloquent (fat model — sem repositório).
 *
 * As RNs de produto vivem aqui (D-403/D-607): o nome único sai como `ErroConflito`
 * (409). O caminho primário é o `UNIQUE` do banco capturado no `INSERT` (D-412); o
 * pré-`SELECT` é só atalho de UX. `DELETE` é soft delete por `ativo` (D-405).
 */
class ServicoProdutos
{
    /** @return Collection<int, Produto> */
    public function listar(bool $apenasAtivos = false): Collection
    {
        return Produto::query()
            ->when($apenasAtivos, fn ($q) => $q->where('ativo', true))
            ->orderBy('nome')
            ->get();
    }

    public function buscar(int $id): Produto
    {
        return Produto::query()->find($id)
            ?? throw new ErroNaoEncontrado('Produto não encontrado.');
    }

    /** @param array<string, mixed> $dados */
    public function criar(array $dados): Produto
    {
        // Atalho de UX (mensagem amigável); o UNIQUE do banco é o caminho primário (D-412).
        if (Produto::query()->where('nome', $dados['nome'])->exists()) {
            throw new ErroConflito('Já existe um produto com esse nome.');
        }

        try {
            return Produto::query()->create($dados + ['ativo' => true]);
        } catch (QueryException $e) {                       // fecha a race SELECT→INSERT (A-1/D-412)
            $this->traduzConflitoNome($e);
        }
    }

    /** @param array<string, mixed> $dados */
    public function atualizar(int $id, array $dados): Produto
    {
        $produto = $this->buscar($id);

        if (isset($dados['nome'])
            && Produto::query()->where('nome', $dados['nome'])->whereKeyNot($id)->exists()) {
            throw new ErroConflito('Já existe um produto com esse nome.');
        }

        try {
            $produto->update($dados);
        } catch (QueryException $e) {
            $this->traduzConflitoNome($e);
        }

        return $produto;
    }

    /** Soft delete por `ativo` (D-405) — idempotente. */
    public function inativar(int $id): Produto
    {
        $produto = $this->buscar($id);
        $produto->update(['ativo' => false]);

        return $produto;
    }

    /** unique_violation (Postgres SQLSTATE 23505) → 409; demais erros re-lançados. */
    private function traduzConflitoNome(QueryException $e): never
    {
        if ($e->getCode() === '23505') {
            throw new ErroConflito('Já existe um produto com esse nome.');
        }

        throw $e;
    }
}
