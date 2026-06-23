<?php

declare(strict_types=1);

namespace App\Services\Dados;

/**
 * Uma linha do relatório de posição aberta (RN-016, D-710): a posição mais o último
 * MtM disponível (`data_calculo <= data`). `precoMedio` só é preenchido para o FUTURO
 * (RN-016) e vem de `Futuro::precoMedio()`; `temMtm=false` sinaliza posição ABERTA
 * ainda sem nenhum MtM processado até a data (valores zerados).
 */
final class LinhaPosicaoAberta
{
    public function __construct(
        public int $posicaoId,
        public int $produtoId,
        public string $produtoNome,
        public string $instrumento,
        public string $lado,
        public float $quantidade,
        public ?float $precoMedio,      // só FUTURO (RN-016)
        public ?float $precoMercado,    // null se ainda sem MtM <= data
        public string $dataVencimento,
        public float $mtm,
        public float $variacaoDia,
        public bool $temMtm,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function paraArray(): array
    {
        return [
            'posicao_id' => $this->posicaoId,
            'produto_id' => $this->produtoId,
            'produto' => $this->produtoNome,
            'instrumento' => $this->instrumento,
            'lado' => $this->lado,
            'quantidade' => $this->quantidade,
            'preco_medio' => $this->precoMedio,
            'preco_mercado' => $this->precoMercado,
            'data_vencimento' => $this->dataVencimento,
            'mtm' => $this->mtm,
            'variacao_dia' => $this->variacaoDia,
            'tem_mtm' => $this->temMtm,
        ];
    }
}
