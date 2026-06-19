<?php

namespace App\Exceptions;

use Exception;
use Throwable;

/**
 * Exceção base de aplicação do NeverVenture.
 *
 * Fixa um status HTTP default (500) e um código de erro determinístico para que
 * qualquer erro de aplicação não mapeado por uma subclasse ainda produza o
 * envelope §5.1 ({ "erro": ..., "mensagem": ... }) com status previsível.
 */
class ErroAplicacao extends Exception
{
    protected int $statusHttp = 500;

    protected string $codigoErro = 'ERRO_APLICACAO';

    public function __construct(
        string $mensagem = 'Erro interno da aplicação.',
        ?string $codigoErro = null,
        ?Throwable $anterior = null,
    ) {
        parent::__construct($mensagem, 0, $anterior);

        if ($codigoErro !== null) {
            $this->codigoErro = $codigoErro;
        }
    }

    /** Status HTTP que a API REST deve devolver para esta exceção. */
    public function statusHttp(): int
    {
        return $this->statusHttp;
    }

    /** Código estável do erro (campo `erro` do envelope §5.1). */
    public function codigoErro(): string
    {
        return $this->codigoErro;
    }

    /**
     * Envelope JSON padronizado da §5.1.
     *
     * @return array{erro: string, mensagem: string}
     */
    public function envelope(): array
    {
        return [
            'erro' => $this->codigoErro,
            'mensagem' => $this->getMessage(),
        ];
    }
}
