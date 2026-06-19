<?php

namespace App\Models\Concerns;

/**
 * Borda string⇄float dos decimais (D-MVC-2, D-712).
 *
 * O PDO/Postgres devolve colunas `NUMERIC` como **string** e o cast `decimal:`
 * do Eloquent também formata como string; o cálculo, porém, opera em `float`.
 * A conversão e o arredondamento à escala da coluna ficam concentrados aqui —
 * nunca espalhados pelos Models. Os métodos recebem **primitivos**, de modo que
 * são testáveis sem instanciar Eloquent (D-202).
 */
trait ConverteDecimais
{
    /** NUMERIC do Postgres chega como string; normaliza para float. */
    public static function paraFloat(string|int|float|null $valor): float
    {
        return $valor === null ? 0.0 : (float) $valor;
    }

    /** Arredonda à escala da coluna de destino (default 4 casas — quantidades/preços). */
    public static function arredonda(float $valor, int $casas = 4): float
    {
        return round($valor, $casas);
    }
}
