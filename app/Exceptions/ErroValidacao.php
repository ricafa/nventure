<?php

namespace App\Exceptions;

/** Entrada inválida / regra de negócio violada — HTTP 422. */
class ErroValidacao extends ErroAplicacao
{
    protected int $statusHttp = 422;

    protected string $codigoErro = 'ERRO_VALIDACAO';
}
