<?php

namespace App\Models;

use App\Models\Concerns\ConverteDecimais;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Model base da hierarquia de posições (*fat model*, §4.2).
 *
 * É concreto por exigência do Eloquent (a hidratação default precisa instanciá-lo),
 * mas toda linha real de `posicao` cai numa subclasse via {@see newFromBuilder()} —
 * o único ponto onde o `instrumento` importa. Depois disso, o cálculo é polimórfico.
 *
 * @property int $id
 * @property int $produto_id
 * @property string $instrumento
 * @property string $mercado
 * @property string $lado
 * @property string $quantidade
 * @property Carbon $data_entrada
 * @property Carbon $data_vencimento
 * @property string|null $contraparte
 * @property string $status
 * @property string|null $observacoes
 * @property Carbon $criado_em
 * @property string $criado_por
 * @property-read Produto $produto
 * @property-read Collection<int, Movimentacao> $movimentacoes
 * @property-read Collection<int, MtmDiario> $mtmDiarios
 */
class Posicao extends Model
{
    use ConverteDecimais;

    protected $table = 'posicao';

    public $timestamps = false;          // a tabela usa criado_em (sem updated_at)

    /** @var list<string> */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantidade' => 'decimal:4',
            'data_entrada' => 'date',
            'data_vencimento' => 'date',
            'criado_em' => 'datetime',
        ];
    }

    /**
     * Fábrica de hidratação polimórfica (§4.5). Único ponto onde o tipo importa;
     * depois disso o polimorfismo é puro. O Eloquent passa os atributos como `stdClass`
     * na hidratação; o cast `(object)` mantém a assinatura compatível com a base.
     *
     * @param  string|null  $connection
     */
    public function newFromBuilder($attributes = [], $connection = null): Posicao
    {
        $classe = match (((object) $attributes)->instrumento ?? null) {
            'FUTURO' => Futuro::class,
            'NDF' => Ndf::class,
            'OPCAO' => Opcao::class,
            'OTC' => Otc::class,
            default => static::class,
        };

        $model = (new $classe)->newInstance([], true);
        $model->setRawAttributes((array) $attributes, true);
        $model->setConnection($connection ?: $this->getConnectionName());

        return $model;
    }

    // A base conhece apenas o que é comum (D-MVC-1). As relações com a tabela-filha
    // (futuro/ndf/opcao/otc) ficam em CADA subclasse, sempre com a FK explícita
    // 'posicao_id', pois o Eloquent chutaria 'futuro_id' etc. (ver §4.4–§4.7).

    /** @return BelongsTo<Produto, $this> */
    public function produto(): BelongsTo
    {
        return $this->belongsTo(Produto::class, 'produto_id');
    }

    /** @return HasMany<Movimentacao, $this> */
    public function movimentacoes(): HasMany
    {
        return $this->hasMany(Movimentacao::class, 'posicao_id');
    }

    /** @return HasMany<MtmDiario, $this> */
    public function mtmDiarios(): HasMany
    {
        return $this->hasMany(MtmDiario::class, 'posicao_id');
    }

    public function sinal(): int
    {
        // match restrito + throw: o ternário cairia silenciosamente em -1 (VENDIDO) para
        // qualquer valor != COMPRADO, e o sinal INVERTE o P&L. O CHECK do banco já barra
        // valor inválido; isto é defesa em profundidade / fail-fast.
        return match ($this->lado) {
            'COMPRADO' => 1,
            'VENDIDO' => -1,
            default => throw new \DomainException("Lado da posição inválido: {$this->lado}"),
        };
    }

    /** Futuro sobrescreve; demais instrumentos não têm realizado no MVP. */
    public function plRealizado(): float
    {
        return 0.0;
    }

    /** Base concreta por exigência do Eloquent; toda linha real cai numa subclasse (D-204). */
    public function calcularMtm(float $precoMercado): float
    {
        throw new \LogicException('Posição sem instrumento de cálculo definido.');
    }
}
