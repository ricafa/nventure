<?php

namespace App\Support\Csv;

/**
 * Fonte de preços para importação em lote (D-406).
 *
 * É o **único** ponto de extensão de ingestão que sobrevive como interface
 * (CLAUDE.md / requisitos §4): trocar a fonte (XLSX, feed, …) é uma nova
 * implementação sem tocar o `ServicoPrecos`. A fonte faz parsing + segurança +
 * tipagem; o Service aplica as RNs e persiste.
 */
interface FontePrecos
{
    /**
     * Lê a fonte e devolve linhas já tipadas/sanitizadas, sem persistir.
     *
     * Cada item traz as chaves de negócio
     *   ['produto_id'=>int, 'data_preco'=>string('Y-m-d'),
     *    'preco_fechamento'=>float, 'cambio_brl'=>float]
     * mais os metadados
     *   ['_linha'=>int, '_erro'=>?string]   // _erro != null ⇒ linha inválida (parsing/segurança)
     *
     * @return iterable<int, array<string, mixed>>
     */
    public function ler(): iterable;
}
