<?php

namespace App\Exceptions;

/** Conflito de estado (ex.: recurso referenciado, estado incompatível) — HTTP 409. */
class ErroConflito extends ErroAplicacao
{
    protected int $statusHttp = 409;

    protected string $codigoErro = 'ERRO_CONFLITO';
}
