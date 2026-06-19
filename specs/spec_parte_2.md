# Spec — Parte 2: Models (M) + cálculo de MtM (*fat model*)

> **Equivale à Fase 2 do `passos_dev.md`.** Materializa os **Models Eloquent gordos**
> que concentram persistência **e** cálculo de MtM, com polimorfismo via `newFromBuilder`
> e cálculo "puro" (sem query). Pré-requisito: **Fase 1** concluída (esquema §3 migrado,
> exceções base e pastas MVC criadas).
>
> **Fonte da verdade:** `specs/requisitos.md` (v1.6) — §3 (modelo de dados), §4 e §4.5
> (hierarquia de classes / hidratação), §7 (RNs) e §8.1 (exemplos de cálculo). Em
> divergência, `requisitos.md` prevalece. Roteiro: `specs/passos_dev.md` (Fase 2).
>
> **Natureza:** especificação executável — descreve **o que** entregar (Models, traits,
> relações, contratos de cálculo) e os critérios de aceite (DoD). **Não** altera regras de
> negócio, modelo de dados nem contratos de API. **Não** implementa Services, API nem
> telas (Fases 4–7); aqui não há orquestração com banco — só hidratação + cálculo.

---

## 0. Decisões desta parte (fixadas)

| # | Tema | Decisão |
|---|---|---|
| **D-MVC-1** | Herança de tabela única | Todas as subclasses (`Futuro`/`Ndf`/`Opcao`/`Otc`) apontam `$table = 'posicao'` e acessam a tabela-filha por **relação `hasOne`** (`futuro()`, `ndf()`, `opcao()`, `otc()`). Não há *Single Table Inheritance* por colunas: o discriminador é `instrumento` e a fábrica é o `newFromBuilder`. |
| **D-MVC-2** | Borda string⇄float | PDO/Postgres devolve `NUMERIC` como **string**; o cálculo opera em `float`. A conversão string→float e o arredondamento à escala da coluna ficam num **helper único** (trait `Concerns/ConverteDecimais`), nunca espalhados (D-712). |
| **D-712** | Escala/arredondamento | Resultados monetários arredondados à escala da coluna de destino: `mtm_valor`/`variacao_dia`/`pl_acumulado` = **2 casas** (`NUMERIC(18,2)`); quantidades/preços derivados = **4–6 casas** conforme a coluna. O **cálculo interno** usa `float`; o arredondamento de saída é explícito e centralizado. |
| **D-201** | Pureza do cálculo | `calcularMtm`, `sinal`, `plRealizado`, `precoMedio`, `quantidadeAtual`, `replay` operam **somente** sobre atributos/relações **já carregados** — não montam query nem tocam no banco. O *eager loading* é responsabilidade do Service (Fase 6). |
| **D-202** | Aritmética em *traits* puros | A aritmética reutilizável (replay de movimentações, sinal, conversão decimal) vive em `app/Models/Concerns/` como **funções sobre primitivos** (arrays/floats/strings), testáveis sem instanciar Eloquent (suporta a meta ≥ 90% da Fase 3 sem banco). |
| **D-203** | `Movimentacao` imutável | Sem `update`/`delete` expostos no Model (RN-025). A imutabilidade é de design (não há setters de negócio nem métodos de escrita); a persistência da `ABERTURA`/`AUMENTO`/`REDUCAO` é responsabilidade do Service na Fase 5. |
| **D-204** | `calcularMtm` na base | `Posicao` é Model Eloquent **concreto** (o Eloquent precisa instanciá-lo). `calcularMtm()` na base **lança** `LogicException` ("instrumento sem cálculo") — equivale ao "abstrato" do §4.2 sem quebrar a hidratação default. Toda linha de `posicao` real cai numa subclasse via `newFromBuilder`. |
| **D-205** | Model de usuário | Consolidar o usuário como **`App\Models\Usuario`** (tabela `usuario`, §3.2.10). A Parte 0 criou `App\Models\User`; esta parte o **renomeia/alinha** (ou cria `Usuario` e ajusta `config/auth.php`, Fortify e o componente de login para referenciá-lo), mantendo o contrato `Authenticatable`. Sobrescreve **`getAuthPassword()` e `getAuthPasswordName()`** → `senha_hash` (o Fortify/Auth recente usa o segundo internamente; só o primeiro não basta). |
| **D-206** | *Enforcement* da pureza | `Model::preventLazyLoading(! app()->isProduction())` no `AppServiceProvider::boot()`. Transforma a promessa da D-201 em garantia: acessar uma relação **não** carregada (eager loading esquecido pelo Service) **estoura** em dev/teste em vez de disparar *lazy loading* silencioso (N+1 no laço do motor — §9.1). |

> **Nota sobre os construtores do §4/§8.1.** Os trechos de `requisitos.md` §4.3 e §8.1
> mostram value objects com **construtores de argumentos nomeados** (`new Futuro(id: …)`).
> No *fat model* isso é **referência das fórmulas**, não a API real: Eloquent hidrata por
> `setRawAttributes`/`make([...])`. Os métodos de cálculo leem **atributos Eloquent**
> (`$this->lado`, `$this->preco_entrada`) e **relações carregadas** (`$this->movimentacoes`),
> não propriedades de construtor. Os **valores esperados** do §8.1 permanecem idênticos —
> a Fase 3 os reproduz instanciando via `make()` + `setRelation()`.

---

## 1. Objetivo e escopo

**Objetivo.** Ao final, `Posicao::query()->...->get()` devolve a **subclasse correta** por
`instrumento`, e cada subclasse calcula seu MtM (e, no `Futuro`, preço médio / quantidade
atual / P&L realizado) de forma **polimórfica e pura**, batendo com os valores do §8.1.

**Dentro do escopo (Parte 2)**
- Models Eloquent: `Posicao` (base) + `Futuro`, `Ndf`, `Opcao`, `Otc`; filhos `Perna`,
  `Movimentacao`; mestres `Produto`, `PrecoReferencia`, `MtmDiario`, `MotorExecucao`,
  `Usuario`.
- `newFromBuilder` na base com `match($instrumento)` → subclasse (§4.5).
- Relações `hasOne`/`hasMany`/`belongsTo` (§3.1).
- Métodos de cálculo (§4.3): `calcularMtm`, `sinal`, `plRealizado`, e no `Futuro`
  `replay`/`precoMedio`/`quantidadeAtual`.
- Traits puros em `app/Models/Concerns/`: conversão decimal e replay de movimentações.
- Casts (`decimal:`, `date`, `boolean`, `hashed`), `$fillable`/`$guarded`, `$timestamps`
  e `CREATED_AT = 'criado_em'` conforme cada tabela.

**Fora do escopo (outras fases)**
- Services/orquestração, transações, `lockForUpdate`, `updateOrCreate` → **Fases 4–6**.
- API REST, Form Requests, Resources → **Fases 4–7**.
- Livewire/telas → **Fases 4–7**.
- Os **testes** de cálculo do §8.1 (suíte unitária ≥ 90%) → **Fase 3** (esta parte só
  precisa de um teste mínimo de hidratação para o DoD).
- Factories/seeders → **Fase 8**. Policies/RBAC → **Fase 10**.

---

## 2. Mapa de Models × tabelas

| Model | Tabela | Tipo | Papel |
|---|---|---|---|
| `Posicao` | `posicao` | base (concreta) | atributos comuns, `sinal`, `plRealizado()→0`, `newFromBuilder`, relações filhas |
| `Futuro` | `posicao` | subclasse | `precoMedio`, `quantidadeAtual`, `plRealizado`, `calcularMtm`; relação `movimentacoes` |
| `Ndf` | `posicao` | subclasse | `calcularMtm` (taxa × nocional) |
| `Opcao` | `posicao` | subclasse | `calcularMtm` = Σ pernas; relação `pernas` |
| `Otc` | `posicao` | subclasse | `calcularMtm` (preço efetivo − entrada) |
| `Perna` | `posicao_opcao_perna` | filho | `sinal`, `calcularMtm` (valor intrínseco − prêmio) |
| `Movimentacao` | `posicao_movimentacao` | filho (imutável) | dados de ABERTURA/AUMENTO/REDUCAO |
| `Produto` | `produto` | mestre | `hasMany` preços e posições |
| `PrecoReferencia` | `preco_referencia` | mestre | `preco_fechamento`, `cambio_brl` |
| `MtmDiario` | `mtm_diario` | histórico | persistido pelo motor (Fase 6); aqui só Model + relações + casts |
| `MotorExecucao` | `motor_execucao` | auditoria | `falhas` cast `array` (JSONB) |
| `Usuario` | `usuario` | auth | `Authenticatable`; `senha_hash` cast `hashed` |

> A tabela-filha de cada subclasse (`posicao_futuro`, `posicao_ndf`, `posicao_opcao`,
> `posicao_otc`) é acessada **por relação `hasOne`** a partir da subclasse (D-MVC-1),
> com *eager loading* no motor. As colunas-filhas (`preco_entrada`, `taxa_contratada`,
> `valor_nocional`, `indexador`, `premio_otc`, `nome_estrutura`, …) **não** ficam em
> `posicao`; o cálculo lê via `$this->futuro->preco_entrada` etc.

---

## 3. Pré-requisitos

- **Fase 1 verde:** `php artisan migrate:fresh` cria o esquema §3; `DatabaseSchemaTest`
  passa (índice parcial `uq_mov_abertura`, UNIQUEs, CHECKs).
- Exceções base disponíveis (`app/Exceptions/`) — não usadas para cálculo, mas o
  `LogicException` de D-204 pode reusar `ErroAplicacao` se conveniente.
- PHPStan nível 8 sobre `app/` (Models entram na análise — `app/Models/` **não** está nas
  exclusões D-006; tipar com rigor).

---

## 4. Passo a passo

> Comandos rodam no container (`docker compose exec app …`). Use `make:model` apenas como
> scaffold; o conteúdo segue as seções abaixo. Models entram no PHPStan nível 8 — declarar
> tipos de retorno, `@property` e `@return` onde o Eloquent não infere.

### 4.0 *Enforcement* da pureza no `AppServiceProvider` (D-206)

Antes dos Models, blindar a promessa de pureza (D-201) no `boot()`:

```php
use Illuminate\Database\Eloquent\Model;

public function boot(): void
{
    // Em dev/teste, acessar relação não carregada estoura (em vez de lazy loading
    // silencioso). Força os Services a fazer eager loading e protege o laço do motor.
    Model::preventLazyLoading(! $this->app->isProduction());
}
```

> Mantém-se **desligado em produção** (não derruba requisição por uma relação esquecida),
> mas o **DoD #2** e os testes da Fase 3 rodam com ele **ligado** — qualquer query acidental
> no cálculo falha o teste.

### 4.1 Trait de conversão decimal — `Concerns/ConverteDecimais` (D-MVC-2, D-712)

Helper único da borda string⇄float, com arredondamento à escala da coluna. Recebe
**primitivos** (testável sem Eloquent — D-202):

```php
namespace App\Models\Concerns;

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
```

> `mtm_valor`/`variacao_dia`/`pl_acumulado` são gravados com **2 casas** (responsabilidade
> do Service/motor na Fase 6, usando `arredonda($v, 2)`); os Models expõem o cálculo em
> `float` e arredondam derivados de preço a 4–6 casas conforme a coluna.

### 4.2 Trait de replay — `Concerns/ReproduzMovimentacoes` (RN-021..024)

A aritmética do preço médio ponderado + P&L realizado, **pura**, sobre uma lista de tuplas
primitivas (não Models) — para que a Fase 3 a teste isoladamente:

```php
namespace App\Models\Concerns;

trait ReproduzMovimentacoes
{
    /**
     * @param list<array{id:int,tipo:string,data:string,quantidade:float,preco:float}> $movs
     * @return array{0:float,1:float,2:float} [quantidadeAtual, precoMedio, plRealizado]
     */
    public static function reproduzir(array $movs, int $sinal): array
    {
        // Desempate determinístico: por data, ABERTURA primeiro, e por fim o id de
        // inserção. Sem o id, um AUMENTO e uma REDUCAO no MESMO dia ficariam à mercê do
        // stable sort (ordem do banco) e produziriam P&L realizado diferente conforme a
        // ordem (a redução realiza contra o pm vigente; o aumento muda o pm). O id casa
        // com o índice idx_mov_posicao_data(posicao_id, data_movimentacao, id).
        usort($movs, fn ($a, $b) =>
            [$a['data'], $a['tipo'] !== 'ABERTURA', $a['id']]
            <=> [$b['data'], $b['tipo'] !== 'ABERTURA', $b['id']]);

        $qtd = 0.0; $pm = 0.0; $realizado = 0.0;
        foreach ($movs as $m) {
            if ($m['tipo'] === 'ABERTURA' || $m['tipo'] === 'AUMENTO') {
                $pm = ($qtd * $pm + $m['quantidade'] * $m['preco']) / ($qtd + $m['quantidade']);
                $qtd += $m['quantidade'];
            } else { // REDUCAO — pm inalterado (RN-021)
                $realizado += ($m['preco'] - $pm) * $m['quantidade'] * $sinal;
                $qtd -= $m['quantidade'];
            }
        }

        return [$qtd, $pm, $realizado];
    }
}
```

> **Empate de data:** `ABERTURA` vem antes; entre `AUMENTO` e `REDUCAO` no mesmo dia o `id`
> de inserção decide (determinístico), pois a ordem altera o realizado. A regra de
> **rejeição** de redução excedente (RN-022) é do **Service** (Fase 5); aqui o replay apenas
> reproduz o que já foi persistido. (O §4.3.1 do `requisitos.md` omite o `id`; o índice
> `idx_mov_posicao_data` já o inclui — adotamos o desempate explícito.)

### 4.3 Model base `Posicao` + `newFromBuilder` (§4.2, §4.5)

```php
namespace App\Models;

use App\Models\Concerns\ConverteDecimais;
use Illuminate\Database\Eloquent\Model;

class Posicao extends Model
{
    use ConverteDecimais;

    protected $table = 'posicao';
    public $timestamps = false;          // tabela usa criado_em (sem updated_at)

    protected $casts = [
        'quantidade'      => 'decimal:4',
        'data_entrada'    => 'date',
        'data_vencimento' => 'date',
        'criado_em'       => 'datetime',
    ];

    // Único ponto onde o tipo importa (fábrica). Depois disso, polimorfismo puro.
    public function newFromBuilder($attributes = [], $connection = null)
    {
        $classe = match ($attributes->instrumento ?? null) {
            'FUTURO' => Futuro::class,
            'NDF'    => Ndf::class,
            'OPCAO'  => Opcao::class,
            'OTC'    => Otc::class,
            default  => static::class,
        };

        $model = (new $classe)->newInstance([], true);
        $model->setRawAttributes((array) $attributes, true);
        $model->setConnection($connection ?: $this->getConnectionName());

        return $model;
    }

    // A base só conhece o que é comum (D-MVC-1). As relações com a tabela-filha
    // (futuro/ndf/opcao/otc) são declaradas em CADA subclasse, sempre com a FK explícita
    // 'posicao_id' (ver §4.4–§4.7), pois o Eloquent chutaria 'futuro_id' etc.
    public function produto()       { return $this->belongsTo(Produto::class, 'produto_id'); }
    public function movimentacoes() { return $this->hasMany(Movimentacao::class, 'posicao_id'); }
    public function mtmDiarios()    { return $this->hasMany(MtmDiario::class, 'posicao_id'); }

    public function sinal(): int
    {
        // match restrito + throw: o ternário cairia silenciosamente em -1 (VENDIDO) para
        // qualquer valor != COMPRADO, e o sinal INVERTE o P&L. O CHECK do banco já barra
        // valor inválido; isto é defesa em profundidade / fail-fast.
        return match ($this->lado) {
            'COMPRADO' => 1,
            'VENDIDO'  => -1,
            default    => throw new \DomainException("Lado da posição inválido: {$this->lado}"),
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
```

> **Relações-filhas (D-MVC-1).** Cada subclasse declara `hasOne` para **sua** tabela-filha
> (`Futuro::futuro()` → `posicao_futuro`, etc.). Evite declarar as quatro na base; a base
> conhece apenas o que é comum (`produto`, `movimentacoes`, `mtmDiarios`). O motor faz
> `->with(['futuro.movimentacoes','ndf','opcao.pernas','otc'])`: como a coleção vem
> hidratada como subclasses, cada relação existe na classe certa.

### 4.4 `Futuro` (§4.3.1, RN-020..024)

- `$table = 'posicao'`; `futuro()` → `hasOne(PosicaoFuturo)` **ou** acesso às colunas-filhas
  via relação. **Decisão de modelagem a registrar no PR:** (a) um Model `PosicaoFuturo`
  para a tabela-filha, OU (b) tratar `posicao_futuro` como extensão acessada por `hasOne`
  sem subclasse de cálculo. Recomenda-se **(a)** Models simples para as filhas
  (`PosicaoFuturo`, `PosicaoNdf`, `PosicaoOpcao`, `PosicaoOtc`) — só dados/casts, sem
  cálculo — mantendo o cálculo na subclasse de `Posicao`.
- `movimentacoes()` herda da base (`hasMany`).
- Métodos (puros, sobre `$this->movimentacoes` já carregada):

```php
public function futuro() { return $this->hasOne(PosicaoFuturo::class, 'posicao_id'); }

private function replay(): array
{
    $movs = $this->movimentacoes->map(fn (Movimentacao $m) => [
        'id'         => $m->id,                               // desempate determinístico (§4.2)
        'tipo'       => $m->tipo,
        'data'       => $m->data_movimentacao->format('Y-m-d'),
        'quantidade' => self::paraFloat($m->quantidade),
        'preco'      => self::paraFloat($m->preco),
    ])->all();

    return self::reproduzir($movs, $this->sinal());      // trait ReproduzMovimentacoes
}

public function precoMedio(): float
{
    return $this->movimentacoes->isNotEmpty()
        ? $this->replay()[1]
        : self::paraFloat($this->futuro->preco_entrada);
}

public function quantidadeAtual(): float
{
    return $this->movimentacoes->isNotEmpty()
        ? $this->replay()[0]
        : self::paraFloat($this->quantidade);
}

public function plRealizado(): float
{
    return $this->movimentacoes->isNotEmpty() ? $this->replay()[2] : 0.0;
}

public function calcularMtm(float $precoMercado): float
{
    return ($precoMercado - $this->precoMedio()) * $this->quantidadeAtual() * $this->sinal();
}
```

> `preco_entrada` permanece como preço da **abertura** e nunca é reaproveitado como preço
> médio (RN-021). Quando há movimentações (sempre haverá, pois o cadastro cria a `ABERTURA`
> — RN-020), `precoMedio`/`quantidadeAtual` vêm do `replay`; os fallbacks cobrem cenários
> de teste que montam um `Futuro` sem a coleção carregada.

### 4.5 `Ndf` (§4.3.2, RN-015)

```php
public function ndf() { return $this->hasOne(PosicaoNdf::class, 'posicao_id'); }

public function calcularMtm(float $precoMercado): float
{
    return ($precoMercado - self::paraFloat($this->ndf->taxa_contratada))
         * self::paraFloat($this->ndf->valor_nocional)
         * $this->sinal();
}
```

> A conversão BRL (× `cambio_brl`) é do **motor** (Fase 6). Para NDF cambial, a convenção
> `cambio_brl = 1` do produto-moeda mantém a multiplicação neutra (§4.3.2). O Model **não**
> trata câmbio.

### 4.6 `Opcao` + `Perna` (§4.3.3, RN-004a..e)

```php
// Opcao
public function pernas() { return $this->hasMany(Perna::class, 'posicao_id'); }

public function calcularMtm(float $precoMercado): float
{
    return $this->pernas->sum(fn (Perna $p) => $p->calcularMtm($precoMercado));
}
```

```php
// Perna — Model de posicao_opcao_perna
class Perna extends Model
{
    use ConverteDecimais;
    protected $table = 'posicao_opcao_perna';
    public $timestamps = false;
    protected $casts = [
        'strike' => 'decimal:6', 'premio_pago' => 'decimal:6', 'quantidade' => 'decimal:4',
    ];

    public function sinal(): int
    {
        return match ($this->lado) {        // fail-fast, como em Posicao::sinal()
            'COMPRADO' => 1,
            'VENDIDO'  => -1,
            default    => throw new \DomainException("Lado da perna inválido: {$this->lado}"),
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
```

> O `lado` da **perna** governa o cálculo; o `lado` da posição mãe é informativo (RN-004e).
> A posição mãe de OPCAO tem `quantidade = 1` por convenção. Estruturas multi-perna
> (straddle, bull call spread, butterfly) são apenas várias `Perna` — sem `if` por estrutura.

### 4.7 `Otc` (§4.3.4)

```php
public function otc() { return $this->hasOne(PosicaoOtc::class, 'posicao_id'); }

public function calcularMtm(float $precoMercado): float
{
    $efetivo = $precoMercado + self::paraFloat($this->otc->premio_otc);
    return ($efetivo - self::paraFloat($this->otc->preco_entrada))
         * self::paraFloat($this->quantidade)
         * $this->sinal();
}
```

### 4.8 `Movimentacao` (imutável — D-203, RN-025)

```php
class Movimentacao extends Model
{
    use ConverteDecimais;
    protected $table = 'posicao_movimentacao';
    public $timestamps = false;
    const CREATED_AT = 'criado_em';      // se optar por timestamps parciais; senão gerencie criado_em manual

    protected $casts = [
        'data_movimentacao' => 'date',
        'quantidade'        => 'decimal:4',
        'preco'             => 'decimal:6',
        'criado_em'         => 'datetime',
    ];

    public function posicao() { return $this->belongsTo(Posicao::class, 'posicao_id'); }
}
```

> Sem métodos de escrita de negócio (nem `update`/`delete` expostos). A criação da
> `ABERTURA`/`AUMENTO`/`REDUCAO` é do Service (Fase 5), em transação com `lockForUpdate`.

### 4.9 Mestres e auditoria

- **`Produto`** (`produto`): `hasMany(PrecoReferencia)`, `hasMany(Posicao)`; cast
  `ativo => boolean`, `criado_em => datetime`; `$timestamps = false`.
- **`PrecoReferencia`** (`preco_referencia`): casts `data_preco => date`,
  `preco_fechamento`/`cambio_brl => decimal:6`, `vol_implicita`/`taxa_juros => decimal:4`
  (nullable, não usados no MVP); `belongsTo(Produto)`.
- **`MtmDiario`** (`mtm_diario`): casts `data_calculo => date`,
  `preco_mercado => decimal:6`, `mtm_valor`/`variacao_dia`/`pl_acumulado => decimal:2`,
  `processado_em => datetime`; `belongsTo(Posicao)`, `belongsTo(PrecoReferencia,'preco_ref_id')`,
  `belongsTo(MotorExecucao,'execucao_id')`. Persistência via `updateOrCreate` fica na Fase 6.
- **`MotorExecucao`** (`motor_execucao`): casts `data_calculo => date`,
  `iniciado_em`/`finalizado_em => datetime`, **`falhas => array`** (JSONB);
  `hasMany(MtmDiario,'execucao_id')`.
- **`Usuario`** (`usuario`, D-205): `extends Authenticatable`; `$table = 'usuario'`;
  `$timestamps = false`; sobrescreve **ambos** `getAuthPassword(): string` → `$this->senha_hash`
  e `getAuthPasswordName(): string` → `'senha_hash'` (o Fortify/Auth recente usa o segundo
  internamente — só o primeiro não basta); casts `senha_hash => hashed`, `ativo => boolean`,
  `criado_em => datetime`; `$hidden = ['senha_hash']`; `perfil` exposto para o RBAC da
  Fase 10. **Alinhar** `config/auth.php`/Fortify/login se a Parte 0 deixou `User`.

---

## 5. Estrutura esperada após a Parte 2

```
app/Models/
├── Concerns/
│   ├── ConverteDecimais.php        (D-MVC-2/D-712)
│   └── ReproduzMovimentacoes.php   (RN-021..024, puro)
├── Posicao.php                     (base + newFromBuilder)
├── Futuro.php  Ndf.php  Opcao.php  Otc.php
├── Perna.php   Movimentacao.php
├── PosicaoFuturo.php PosicaoNdf.php PosicaoOpcao.php PosicaoOtc.php   (filhas — só dados/casts)
├── Produto.php PrecoReferencia.php MtmDiario.php MotorExecucao.php
└── Usuario.php                     (substitui/alinha User.php da Parte 0)
```

Nenhum Service, Controller, rota ou view é criado nesta parte. A única edição fora de
`app/Models/` é o `AppServiceProvider::boot()` (`preventLazyLoading`, D-206).

---

## 6. Arquivos a entregar (checklist)

- [ ] `Model::preventLazyLoading(! isProduction())` no `AppServiceProvider::boot()` (D-206).
- [ ] `Concerns/ConverteDecimais.php` e `Concerns/ReproduzMovimentacoes.php` (puros; replay
      ordena por `[data, !ABERTURA, id]`).
- [ ] `Posicao.php` com `newFromBuilder` (`match` por instrumento), relações comuns,
      `sinal`, `plRealizado()→0`, `calcularMtm` base lançando `LogicException`.
- [ ] `Futuro`/`Ndf`/`Opcao`/`Otc` com `calcularMtm` e relações da subclasse; `Futuro`
      com `replay`/`precoMedio`/`quantidadeAtual`/`plRealizado`.
- [ ] `Perna` (sinal + intrínseco) e `Movimentacao` (imutável).
- [ ] Models-filha (`PosicaoFuturo`…`PosicaoOtc`) só com dados/casts (decisão (a) do §4.4).
- [ ] `Produto`, `PrecoReferencia`, `MtmDiario`, `MotorExecucao` com casts/relações.
- [ ] `Usuario` consolidado (tabela `usuario`, `senha_hash` hashed, `getAuthPassword()` **e**
      `getAuthPasswordName()`) + ajuste de auth se necessário; remover/renomear `User.php`.
- [ ] Casts `decimal:`/`date`/`boolean`/`array`/`hashed` e `$timestamps`/`CREATED_AT` por
      tabela; `$fillable`/`$guarded` definidos.
- [ ] **Teste mínimo de hidratação** (DoD): `Posicao::query()->get()` devolve a subclasse
      certa para cada `instrumento` (um por tipo). Os testes de fórmula do §8.1 ficam na
      **Fase 3**.

---

## 7. Definition of Done (critérios de aceite)

1. **Hidratação polimórfica:** com uma linha de cada `instrumento` no banco,
   `Posicao::query()->get()` devolve respectivamente `Futuro`/`Ndf`/`Opcao`/`Otc`
   (teste de hidratação por tipo — DoD da Fase 2 no `passos_dev.md`).
2. **Cálculo puro:** com `preventLazyLoading` **ligado** (D-206), instanciar um Model via
   `make()`/`setRawAttributes` + `setRelation` e chamar `calcularMtm`/`precoMedio` **não**
   dispara query (sem banco) — acesso a relação não carregada estoura. Pelo menos um caso
   por subclasse confere com o §8.1 (validação ampla fica na Fase 3).
3. **Sem regressão de qualidade:** `vendor/bin/pint --test` e `vendor/bin/phpstan analyse`
   (nível 8, incluindo `app/Models/`) **sem erros**.
4. **`composer test` verde** (a suíte da Fase 1 continua passando + o teste de hidratação).
5. **`Usuario` consolidado:** login do starter kit continua autenticando por `login` contra
   a tabela `usuario` (sem regressão da Parte 0).

---

## 8. Riscos e pontos a verificar

| Risco | Mitigação / ação |
|---|---|
| `NUMERIC` chega como **string**; `===`/aritmética silenciosamente erram | Sempre passar pela borda `ConverteDecimais::paraFloat` (D-MVC-2); nunca comparar string de decimal direto. |
| Cast `decimal:` retorna **string** (não float) — pode mascarar o item acima | Tratar o cast como formatação de saída; para cálculo, converter explicitamente a float. |
| Métodos de cálculo **disparando query** (quebra a pureza/§8.4) | `Model::preventLazyLoading` ligado em dev/teste (D-206): relação não carregada **estoura**, não faz lazy loading silencioso. *Eager loading* é do Service; cobrir com teste que falha se houver query. |
| **Precisão `float` (IEEE-754)** em P&L/preço médio — acúmulo de erro no `replay` e em reduções fracionadas | **Limitação aceita no MVP.** O armazenamento é **`NUMERIC` (`decimal`)** — exato em repouso e definitivo; o §8.1 (fonte da verdade) fixa os valores com `toBe()` em **float** e o §4 usa assinaturas `float`. O `float` é só o tipo de trabalho **interno** do cálculo: replay opera em precisão plena e **só a saída** é arredondada à escala da coluna (D-712); nunca arredondar pm intermediário. **Não** se introduz outro tipo numérico (mantém-se `decimal`/`float`). A comparação de quantidade com zero no encerramento (RN-022) usa tolerância — tratada no Service (Fase 5). |
| **STI via `newFromBuilder`** atrita com features nativas (Model Factories, hidratação Livewire, eventos de ciclo de vida) | Tratar nas fases que as usam: **Factories** (Fase 8) precisam fixar `instrumento`/usar `state` por subclasse; **Livewire** (Fases 4–7) re-hidrata por `newFromBuilder` — validar serialização de subclasse. Não bloqueia a Fase 2. |
| **Custo do `replay()` O(n)** por posição/dia (motor — §9.1: 1.000 posições < 30s) | `quantidadeAtual()` lê `posicao.quantidade` (mantida transacionalmente — RN-024) no caso comum, evitando replay; só `precoMedio()` exige replay. Avaliar *profiling*/otimização na **Fase 12**. **Não** cachear pm em `posicao_futuro` (contraria o design derivado-de-movimentações da RN-021). |
| `newFromBuilder` não cobre `default` → `Posicao` base com `calcularMtm` que lança | Garantir CHECK de `instrumento` (Fase 1) + `match` com os 4 casos; base lança `LogicException` (D-204). |
| Relações-filha declaradas na base poluem subclasses erradas | Declarar cada `hasOne` **na subclasse** (D-MVC-1); base só conhece o comum. |
| `Usuario` vs `User` da Parte 0 deixa auth órfã | Renomear e ajustar `config/auth.php`/Fortify/login num passo só; rodar o teste de login da Parte 0 como regressão. |
| Empate de data em movimentações inverte ABERTURA/AUMENTO | Ordenar por `[data, tipo !== 'ABERTURA']` (réplica §4.3.1); cobrir no teste de replay (Fase 3). |
| PHPStan nível 8 reclama de propriedades mágicas do Eloquent | Anotar `@property`/`@property-read` e retornos; usar `larastan` (já configurado na Fase 0). |
| Arredondamento divergente (2 vs 4 casas) | Centralizar em `arredonda($v,$casas)` (D-712); o motor arredonda a saída monetária a 2 casas na Fase 6. |

---

## 9. Referências

- `specs/requisitos.md` §3 (modelo de dados), §4/§4.5 (Models e `newFromBuilder`),
  §7 (RN-001..025), §8.1 (exemplos de cálculo), §8.4 (metas de cobertura).
- `specs/passos_dev.md` — Fase 2 (objetivo, tarefas, DoD) e Fase 3 (testes que consomem
  estes Models).
- `specs/spec_parte_0.md` (D-008 — tabela `usuario`/login) e migrations da Fase 1
  (`database/migrations/2026_06_19_10xx_*`) — nomes de coluna canônicos.
- Eloquent: hidratação (`newFromBuilder`), casts (`decimal:`, `array`, `hashed`), relações
  `hasOne`/`hasMany`/`belongsTo` — https://laravel.com/docs/13.x/eloquent

---

**Fim do documento.** Próxima etapa: **Fase 3 — Testes unitários** (`passos_dev.md`), que
trava as fórmulas do §8.1 sobre estes Models (cobertura ≥ 90%, sem banco).
