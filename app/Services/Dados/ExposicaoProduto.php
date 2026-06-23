<?php

declare(strict_types=1);

namespace App\Services\Dados;

/**
 * Exposição líquida de um produto (RN-019, D-705/D-705a, D-710). Mutável durante a
 * agregação no `ServicoRelatorios` (`somar*`/`contar`); na borda HTTP/UI vira `paraArray()`.
 *
 * O "líquido" pode somar grandezas de unidades diferentes — contratos/quantidade física
 * (FUTURO/OTC) e nocional em moeda (NDF). `unidade_mista` sinaliza esse mismatch (D-705a):
 * é `true` quando há NDF junto de FUTURO/OTC no mesmo produto. A OPCAO (quantidade = 1)
 * não dispara o flag sozinha — não soma grandeza com sentido direcional.
 */
final class ExposicaoProduto
{
    /** @var array{FUTURO: int, NDF: int, OPCAO: int, OTC: int} */
    public array $mix = ['FUTURO' => 0, 'NDF' => 0, 'OPCAO' => 0, 'OTC' => 0];

    public function __construct(
        public int $produtoId,
        public string $produtoNome,
        public float $comprado = 0.0,
        public float $vendido = 0.0,
        public float $mtm = 0.0,
        public int $posicoes = 0,
    ) {}

    public static function vazia(int $produtoId, string $produtoNome): self
    {
        return new self($produtoId, $produtoNome);
    }

    public function somarComprado(float $quantidade): void
    {
        $this->comprado += $quantidade;
    }

    public function somarVendido(float $quantidade): void
    {
        $this->vendido += $quantidade;
    }

    public function somarMtm(float $mtm): void
    {
        $this->mtm += $mtm;
    }

    public function contar(string $instrumento): void
    {
        if (array_key_exists($instrumento, $this->mix)) {
            $this->mix[$instrumento]++;
        }
        $this->posicoes++;
    }

    public function liquido(): float
    {
        return round($this->comprado - $this->vendido, 4);
    }

    /** D-705a: soma nocional em moeda (NDF) com contratos/quantidade física (FUTURO/OTC). */
    public function unidadeMista(): bool
    {
        return $this->mix['NDF'] > 0 && ($this->mix['FUTURO'] > 0 || $this->mix['OTC'] > 0);
    }

    /**
     * @return array<string, mixed>
     */
    public function paraArray(): array
    {
        return [
            'produto_id' => $this->produtoId,
            'produto' => $this->produtoNome,
            'comprado' => round($this->comprado, 4),
            'vendido' => round($this->vendido, 4),
            'liquido' => $this->liquido(),
            'mtm' => round($this->mtm, 2),
            'posicoes' => $this->posicoes,
            'mix' => $this->mix,
            'unidade_mista' => $this->unidadeMista(),
        ];
    }
}
