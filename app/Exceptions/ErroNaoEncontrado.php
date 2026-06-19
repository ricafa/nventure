<?php

namespace App\Exceptions;

/** Recurso inexistente — HTTP 404. */
class ErroNaoEncontrado extends ErroAplicacao
{
    protected int $statusHttp = 404;

    protected string $codigoErro = 'ERRO_NAO_ENCONTRADO';
}
