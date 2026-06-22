<?php

namespace App\Services\Dados;

use App\Models\Posicao;

/**
 * Representação simplificada de uma posição para a listagem (§5.2.3, D-505).
 *
 * Mascara os detalhes do tipo (futuro/ndf/opcao/otc): expõe só os campos comuns da
 * mãe `posicao`. Construída a partir do Model na borda do Service — o Eloquent é
 * usado para extrair primitivos, mas não vaza para a HTTP/UI.
 */
final class PosicaoResumo
{
    public function __construct(
        public int $id,
        public int $produtoId,
        public string $instrumento,
        public string $mercado,
        public string $lado,
        public float $quantidade,
        public string $status,
        public string $dataEntrada,
        public string $dataVencimento,
        public ?string $contraparte,
        public ?string $criadoEm,
    ) {}

    public static function deModel(Posicao $posicao): self
    {
        return new self(
            id: (int) $posicao->id,
            produtoId: (int) $posicao->produto_id,
            instrumento: $posicao->instrumento,
            mercado: $posicao->mercado,
            lado: $posicao->lado,
            quantidade: Posicao::paraFloat($posicao->quantidade),
            status: $posicao->status,
            dataEntrada: $posicao->data_entrada->format('Y-m-d'),
            dataVencimento: $posicao->data_vencimento->format('Y-m-d'),
            contraparte: $posicao->contraparte,
            criadoEm: $posicao->criado_em->toIso8601String(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function paraArray(): array
    {
        return [
            'id' => $this->id,
            'produto_id' => $this->produtoId,
            'instrumento' => $this->instrumento,
            'mercado' => $this->mercado,
            'lado' => $this->lado,
            'quantidade' => $this->quantidade,
            'status' => $this->status,
            'data_entrada' => $this->dataEntrada,
            'data_vencimento' => $this->dataVencimento,
            'contraparte' => $this->contraparte,
            'criado_em' => $this->criadoEm,
        ];
    }
}
