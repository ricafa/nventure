<?php

declare(strict_types=1);

namespace App\Services\Dados;

final class RegistroMtm
{
    public function __construct(
        public readonly int $posicaoId,
        public readonly int $precoRefId,
        public readonly float $precoMercado,
        public readonly float $mtmValor,
        public readonly float $variacaoDia,
        public readonly float $plAcumulado,
    ) {}
}
