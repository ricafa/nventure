<?php

namespace App\Models;

use App\Models\Concerns\ConverteDecimais;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Perna de uma estrutura de opção (`posicao_opcao_perna`, §4.3.3). Precificada por
 * valor intrínseco menos prêmio; o `lado` da perna governa o sinal (RN-004c/e).
 *
 * @property int $id
 * @property int $posicao_id
 * @property int $sequencia
 * @property string $tipo_opcao
 * @property string $estilo
 * @property string $strike
 * @property string $premio_pago
 * @property string $quantidade
 * @property string $lado
 */
class Perna extends Model
{
    use ConverteDecimais;

    protected $table = 'posicao_opcao_perna';

    public $timestamps = false;

    /** @var list<string> */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'strike' => 'decimal:6',
            'premio_pago' => 'decimal:6',
            'quantidade' => 'decimal:4',
        ];
    }

    /** @return BelongsTo<Opcao, $this> */
    public function opcao(): BelongsTo
    {
        return $this->belongsTo(Opcao::class, 'posicao_id');
    }

    public function sinal(): int
    {
        return match ($this->lado) {        // fail-fast, como em Posicao::sinal()
            'COMPRADO' => 1,
            'VENDIDO' => -1,
            default => throw new \DomainException("Lado da perna inválido: {$this->lado}"),
        };
    }

    public function calcularMtm(float $precoMercado): float
    {
        $strike = self::paraFloat($this->strike);
        $intrinseco = $this->tipo_opcao === 'CALL'
            ? max($precoMercado - $strike, 0.0)
            : max($strike - $precoMercado, 0.0);

        return ($intrinseco - self::paraFloat($this->premio_pago))
             * self::paraFloat($this->quantidade)
             * $this->sinal();
    }
}
