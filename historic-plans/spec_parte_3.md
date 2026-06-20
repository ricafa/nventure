# Spec — Parte 3: Testes unitários (cálculo de MtM/PM/P&L, sem banco)

> **Equivale à Fase 3 do `passos_dev.md`.** Trava as **fórmulas** do *fat model* antes de
> qualquer orquestração: `calcularMtm`/`sinal`/`plRealizado`/`precoMedio`/`quantidadeAtual`
> e os *traits* puros de `app/Models/Concerns/`. Meta de cobertura do **núcleo de cálculo
> ≥ 90 %** (§8.4), **sem tocar o banco**. Pré-requisito: **Fase 2** verde (Models e traits
> implementados; `preventLazyLoading` ligado em dev/teste — D-206).
>
> **Fonte da verdade:** `specs/requisitos.md` (v1.6) — §8.1 (exemplos de cálculo com valores
> esperados), §8.4 (metas de cobertura), §4.3 (fórmulas), §7 (RNs). Em divergência,
> `requisitos.md` prevalece. Roteiro: `specs/passos_dev.md` (Fase 3).
>
> **Natureza:** especificação executável — descreve **quais testes** entregar, os **valores
> esperados** (golden values) e os critérios de aceite (DoD). **Não** altera regras de
> negócio, modelo de dados, contratos de API nem os Models (Fase 2). **Não** cobre
> orquestração com banco (Services/feature → Fases 4–9), idempotência do motor com
> persistência (Fase 6/9) nem validação de endpoints (Fase 4/5) — embora o §8.1 os cite,
> aqui só entra o que é **puro e sem banco**.

---

## 0. Decisões desta parte (fixadas)

| # | Tema | Decisão |
|---|---|---|
| **D-301** | Testes sem banco | Nenhum teste de cálculo usa `RefreshDatabase`/`DatabaseTransactions` nem abre conexão. Os Models são instanciados por `make()`/`setRawAttributes` + `setRelation`; os *traits* são exercitados como funções estáticas sobre primitivos. Com `preventLazyLoading` **ligado** (D-206), qualquer acesso a relação não carregada **estoura** — a suíte falha se um cálculo "vazar" uma query. É a garantia executável de pureza (D-201/§8.4). |
| **D-302** | *Golden values* | Os valores esperados do **§8.1 são reproduzidos idênticos** (mesmos `toBe()`). As estruturas multi-perna que o §8.1 **não** traz (strangle, collar, bear put spread, butterfly) entram com a **aritmética por perna explícita** no comentário do teste, para serem auditáveis (mesmo formato do §8.1). Nenhum valor "mágico" sem conta ao lado. |
| **D-303** | Escopo da cobertura ≥ 90 % | O "núcleo de cálculo" cuja meta é **≥ 90 %** é: `Posicao` (`sinal`/`plRealizado`/`calcularMtm`/`newFromBuilder`), `Futuro`, `Ndf`, `Opcao`, `Otc`, `Perna` e `Concerns/{ConverteDecimais,ReproduzMovimentacoes}`. Os Models de **persistência pura** (`Produto`, `PrecoReferencia`, `MtmDiario`, `MotorExecucao`, `Usuario`, `PosicaoFuturo/Ndf/Opcao/Otc`, `Movimentacao`) **não** entram nessa meta — são cobertos como *feature* nas Fases 9–10. O recorte é fixado no `phpunit.xml` (`<source>`/`--coverage`). |
| **D-304** | Trait testado direto | `ReproduzMovimentacoes::reproduzir()` e `ConverteDecimais::{paraFloat,arredonda}()` são testados **sobre primitivos** (arrays/strings/floats), sem instanciar Eloquent (D-202). O teste via `Futuro` cobre a **integração** trait↔Model; o teste do trait cobre os ramos finos (empate de data, sinal). |
| **D-305** | Comparação de `float` | Valores-ouro são desenhados para serem **exatos** em IEEE-754 (`toBe()`). Onde o resultado depende de divisão não exata (ex.: pm de replay), comparar com `toBe()` somente quando o valor for representável; caso contrário, usar `toEqualWithDelta($v, 1e-9)`. **Não** se introduz `bcmath` na Fase 3 (a precisão `float` interna é limitação aceita do MVP — §8 da spec_parte_2; o armazenamento `NUMERIC` é exato). |
| **D-306** | Polimorfismo sem banco | O teste dedicado do `newFromBuilder` (um por instrumento) chama o método **diretamente** com um `stdClass` de atributos (`(object) ['instrumento' => 'FUTURO']`), afirmando a subclasse retornada — **sem** `save()`/`get()`. A hidratação com banco real (1 linha por tipo) permanece como *feature* (`tests/Feature/HidratacaoPolimorficaTest.php`, já entregue na Fase 2). |

---

## 1. Objetivo e escopo

**Objetivo.** Ao final, a suíte unitária está **verde**, cobre **≥ 90 %** do núcleo de
cálculo (D-303) e **nenhum** teste de cálculo toca o banco. As fórmulas do §8.1 ficam
"congeladas": qualquer regressão futura na aritmética de MtM/PM/P&L falha o build.

**Dentro do escopo (Parte 3)**
- `calcularMtm` de **cada** subclasse (`Futuro`, `Ndf`, `Opcao`, `Otc`) com ≥ 4 cenários:
  comprado/vendido × mercado a favor/contra (§8.1, item 1).
- `sinal()` da base (`Posicao`) e de `Perna` — incluindo o **fail-fast** (`DomainException`)
  em `lado` inválido.
- `Perna::calcularMtm` (valor intrínseco − prêmio) para **CALL/PUT × comprada/vendida ×
  ITM/OTM**.
- Estruturas multi-perna como soma de `Perna` (sem `if` por estrutura): **straddle** e
  **bull call spread** (§8.1, idênticos) + **strangle, collar, bear put spread, butterfly**
  (aritmética explícita — D-302).
- `Futuro` com movimentações (replay): PM ponderado após **aumento**; **redução** que mantém
  PM e gera realizado; **redução total** que zera a quantidade; **sinal invertido** em
  posição vendida; **desempate determinístico** de eventos no **mesmo dia** (ponto 4 do
  parecer / §4.2).
- *Traits* puros: `ConverteDecimais::{paraFloat,arredonda}` (borda string⇄float;
  arredondamento a 2/4 casas) e `ReproduzMovimentacoes::reproduzir` (sobre primitivos).
- Polimorfismo de `newFromBuilder` (um caso por instrumento + `default` → base).
- **Cobertura:** recorte do núcleo no `phpunit.xml`; gate `--min=90` (núcleo).

**Fora do escopo (outras fases)**
- Qualquer teste com `RefreshDatabase`/banco → **Fase 9** (integração).
- Validações de **endpoint** (quantidade negativa, datas invertidas, strike zero, estrutura
  sem pernas — §8.1, item 5) → **Fase 4/5** (Form Requests/feature).
- **Idempotência do motor** com persistência (rodar 2× não duplica — §8.1, item 6) →
  **Fase 6/9** (precisa de `updateOrCreate` + banco).
- Rejeição de **redução excedente** (RN-022 → 422): a **regra** é do Service (Fase 5); aqui
  o replay apenas reproduz o que foi persistido (o teste de redução total cobre o `qtd = 0`,
  não a rejeição).
- Conversão **BRL** do motor (× `cambio_brl`) e `cambio_brl = 1` da NDF cambial → **Fase 6**.

---

## 2. Mapa de arquivos de teste × escopo

| Arquivo (em `tests/`) | Tipo | Cobre |
|---|---|---|
| `Unit/CalculoMtmTest.php` | unit (Model, sem banco) | `calcularMtm`/`precoMedio`/`quantidadeAtual`/`plRealizado` das 4 subclasses + multi-perna; `sinal` |
| `Unit/Concerns/ReproduzMovimentacoesTest.php` | unit (trait puro) | `reproduzir()` sobre primitivos: PM ponderado, realizado, redução total, sinal, **empate de data por id** |
| `Unit/Concerns/ConverteDecimaisTest.php` | unit (trait puro) | `paraFloat` (string/int/float/null) e `arredonda` (2/4/6 casas) |
| `Unit/HidratacaoPolimorficaTest.php` | unit (`newFromBuilder` direto) | `match($instrumento)` → subclasse correta + `default` → `Posicao` |
| `Feature/HidratacaoPolimorficaTest.php` | feature (Fase 2 — **mantido**) | hidratação com **banco real**: `Posicao::query()->get()` por tipo |

> O `Unit/CalculoMtmTest.php` já existe (semente da Fase 2 com 1 caso por instrumento);
> esta fase o **expande** para a matriz completa. O `Feature/HidratacaoPolimorficaTest.php`
> permanece (toca o banco de propósito — é integração de hidratação). Remover o
> `tests/Unit/ExampleTest.php` (scaffold do starter kit) se ainda presente.

---

## 3. Pré-requisitos

- **Fase 2 verde:** Models e traits implementados; `Model::preventLazyLoading(! isProduction())`
  no `AppServiceProvider::boot()` (D-206); `composer test` passando com o teste de hidratação.
- **Cobertura habilitada no container:** `pcov` (preferido em CI por velocidade) **ou** Xdebug
  em modo coverage. Confirmar com `docker compose exec app php -m | grep -E 'pcov|xdebug'`.
  Se ausente, instalar `pcov` na imagem (`pecl install pcov` + `docker-php-ext-enable pcov`)
  — ajuste no `Dockerfile`, registrado no PR.
- **Pest** configurado (Fase 0). Bancos de teste **não** são exigidos por esta suíte (D-301),
  mas o app Laravel precisa bootar (resolver de casts/relações do Eloquent) — daí
  `uses(TestCase::class)` sem `RefreshDatabase`.

---

## 4. Passo a passo

> Comandos rodam no container (`docker compose exec app …`). Todos os testes desta fase usam
> `uses(Tests\TestCase::class)` e instanciam por `make()`/`setRelation` — **nunca** `create()`
> nem `save()`.

### 4.0 Recorte da cobertura no `phpunit.xml` (D-303)

Restringir o relatório/gate ao núcleo de cálculo, para que a meta de **90 %** meça o que o
§8.4 chama de "cálculo dos Models", sem ser diluída por persistência pura:

```xml
<source>
  <include>
    <directory>app/Models</directory>
  </include>
  <exclude>
    <!-- persistência pura: cobertos por feature nas Fases 9–10, fora da meta de 90% -->
    <file>app/Models/Produto.php</file>
    <file>app/Models/PrecoReferencia.php</file>
    <file>app/Models/MtmDiario.php</file>
    <file>app/Models/MotorExecucao.php</file>
    <file>app/Models/Usuario.php</file>
    <file>app/Models/Movimentacao.php</file>
    <file>app/Models/PosicaoFuturo.php</file>
    <file>app/Models/PosicaoNdf.php</file>
    <file>app/Models/PosicaoOpcao.php</file>
    <file>app/Models/PosicaoOtc.php</file>
  </exclude>
</source>
```

> Mede o núcleo: `Posicao`, `Futuro`, `Ndf`, `Opcao`, `Otc`, `Perna`, `Concerns/*`. Rodar:
> `docker compose exec app ./vendor/bin/pest --coverage --min=90`. (O gate **total** ≥ 75 %
> e aplicação ≥ 70 % são das Fases 9/13; aqui o gate é o **núcleo** ≥ 90 %.)

### 4.1 `Unit/Concerns/ConverteDecimaisTest.php` (D-MVC-2 / D-712)

Borda string⇄float e arredondamento, sobre primitivos (sem Eloquent):

```php
use App\Models\Concerns\ConverteDecimais;

// classe anônima só para expor o trait estaticamente no teste
$c = new class { use ConverteDecimais; };

it('paraFloat normaliza string/int/float e trata null como 0.0', function () use ($c) {
    expect($c::paraFloat('1418.6500'))->toBe(1418.65)
        ->and($c::paraFloat(1400))->toBe(1400.0)
        ->and($c::paraFloat(1400.5))->toBe(1400.5)
        ->and($c::paraFloat(null))->toBe(0.0);
});

it('arredonda à escala da coluna (2/4/6 casas)', function () use ($c) {
    expect($c::arredonda(1410.56789, 2))->toBe(1410.57)
        ->and($c::arredonda(1410.56789, 4))->toBe(1410.5679)
        ->and($c::arredonda(1410.5))->toBe(1410.5);        // default 4 casas
});
```

### 4.2 `Unit/Concerns/ReproduzMovimentacoesTest.php` (RN-021..024, §4.2)

O *trait* puro, recebendo a lista de tuplas e o `$sinal`. Cobre os ramos que o teste via
`Futuro` não isola — em especial o **desempate por `id` no mesmo dia** (ponto 4 do parecer):

```php
use App\Models\Concerns\ReproduzMovimentacoes;

$r = new class { use ReproduzMovimentacoes; };

it('PM ponderado após aumento; redução mantém PM e gera realizado', function () use ($r) {
    [$qtd, $pm, $real] = $r::reproduzir([
        ['id' => 1, 'tipo' => 'ABERTURA', 'data' => '2026-01-10', 'quantidade' => 100, 'preco' => 1400.0],
        ['id' => 2, 'tipo' => 'AUMENTO',  'data' => '2026-02-10', 'quantidade' => 50,  'preco' => 1430.0],
        ['id' => 3, 'tipo' => 'REDUCAO',  'data' => '2026-03-10', 'quantidade' => 50,  'preco' => 1440.0],
    ], 1);

    expect($pm)->toBe(1410.0)        // (100×1400 + 50×1430)/150
        ->and($qtd)->toBe(100.0)
        ->and($real)->toBe(1500.0);  // (1440−1410)×50×1
});

it('redução total zera a quantidade (encerramento é do Service — Fase 5)', function () use ($r) {
    [$qtd,, $real] = $r::reproduzir([
        ['id' => 1, 'tipo' => 'ABERTURA', 'data' => '2026-01-10', 'quantidade' => 100, 'preco' => 1400.0],
        ['id' => 2, 'tipo' => 'REDUCAO',  'data' => '2026-02-10', 'quantidade' => 100, 'preco' => 1410.0],
    ], 1);

    expect($qtd)->toBe(0.0)->and($real)->toBe(1000.0);   // (1410−1400)×100×1
});

it('posição vendida inverte o sinal do realizado', function () use ($r) {
    [$qtd,, $real] = $r::reproduzir([
        ['id' => 1, 'tipo' => 'ABERTURA', 'data' => '2026-01-10', 'quantidade' => 120, 'preco' => 320.0],
        ['id' => 2, 'tipo' => 'REDUCAO',  'data' => '2026-02-10', 'quantidade' => 30,  'preco' => 305.0],
    ], -1);

    expect($qtd)->toBe(90.0)->and($real)->toBe(450.0);   // (305−320)×30×(−1)
});

it('DESEMPATE no mesmo dia: id ordena AUMENTO antes de REDUCAO (resultado determinístico)', function () use ($r) {
    // ABERTURA 100@1000; no MESMO dia 2026-02-10: AUMENTO 100@1200 (id2) e REDUCAO 100@1100 (id3).
    // Ordem por id → AUMENTO primeiro: pm=(100×1000+100×1200)/200=1100; REDUCAO 100@1100 realiza 0.
    // Se a REDUCAO viesse antes (sem o id), realizaria (1100−1000)×100=10000 → resultado divergente.
    [$qtd, $pm, $real] = $r::reproduzir([
        ['id' => 3, 'tipo' => 'REDUCAO',  'data' => '2026-02-10', 'quantidade' => 100, 'preco' => 1100.0],
        ['id' => 1, 'tipo' => 'ABERTURA', 'data' => '2026-01-10', 'quantidade' => 100, 'preco' => 1000.0],
        ['id' => 2, 'tipo' => 'AUMENTO',  'data' => '2026-02-10', 'quantidade' => 100, 'preco' => 1200.0],
    ], 1);

    expect($pm)->toBe(1100.0)->and($qtd)->toBe(100.0)->and($real)->toBe(0.0);
});
```

> O último teste embaralha a ordem de entrada de propósito: o `usort` por
> `[data, tipo!=='ABERTURA', id]` deve produzir o mesmo resultado. Sem o desempate por `id`,
> `real` seria `10000.0` — o teste falharia, protegendo a correção do parecer.

### 4.3 `Unit/CalculoMtmTest.php` — matriz por instrumento (§8.1, item 1)

Expandir o arquivo da Fase 2. **FUTURO** — 4 quadrantes (pm fixado por uma `ABERTURA`):

```php
use App\Models\{Futuro, Movimentacao};

dataset('futuro_quadrantes', [
    // lado,      preco_merc, esperado            (pm 1400, qtd 100)
    'comprado a favor'  => ['COMPRADO', 1450.0,  5000.0],   // (1450−1400)×100×(+1)
    'comprado contra'   => ['COMPRADO', 1350.0, -5000.0],
    'vendido a favor'   => ['VENDIDO',  1350.0,  5000.0],   // (1350−1400)×100×(−1)
    'vendido contra'    => ['VENDIDO',  1450.0, -5000.0],
]);

it('Futuro: calcularMtm nos 4 quadrantes', function (string $lado, float $merc, float $esp) {
    $f = Futuro::make(['lado' => $lado]);
    $f->setRelation('movimentacoes', collect([
        Movimentacao::make(['id' => 1, 'tipo' => 'ABERTURA', 'data_movimentacao' => '2026-01-10', 'quantidade' => 100, 'preco' => 1400.0]),
    ]));
    expect($f->calcularMtm($merc))->toBe($esp);
})->with('futuro_quadrantes');
```

**NDF** (taxa de mercado − contratada × nocional × sinal; nocional 100 000, contratada 5,00):

```php
use App\Models\{Ndf, PosicaoNdf};

dataset('ndf_quadrantes', [
    'comprado a favor' => ['COMPRADO', 5.50,  50000.0],   // (5.50−5.00)×100000×(+1)
    'comprado contra'  => ['COMPRADO', 4.50, -50000.0],
    'vendido a favor'  => ['VENDIDO',  4.50,  50000.0],
    'vendido contra'   => ['VENDIDO',  5.50, -50000.0],
]);

it('Ndf: calcularMtm nos 4 quadrantes', function (string $lado, float $merc, float $esp) {
    $n = Ndf::make(['lado' => $lado]);
    $n->setRelation('ndf', PosicaoNdf::make(['taxa_contratada' => 5.00, 'valor_nocional' => 100000]));
    expect($n->calcularMtm($merc))->toBe($esp);
})->with('ndf_quadrantes');
```

**OTC** (preço efetivo = mercado + prêmio OTC; (efetivo − entrada) × qtd × sinal; entrada
1400, qtd 100):

```php
use App\Models\{Otc, PosicaoOtc};

dataset('otc_quadrantes', [
    'comprado a favor' => ['COMPRADO', 1450.0, 0.0,   5000.0],
    'comprado contra'  => ['COMPRADO', 1350.0, 0.0,  -5000.0],
    'vendido a favor'  => ['VENDIDO',  1350.0, 0.0,   5000.0],
    'vendido contra'   => ['VENDIDO',  1450.0, 0.0,  -5000.0],
]);

it('Otc: calcularMtm nos 4 quadrantes', function (string $lado, float $merc, float $premio, float $esp) {
    $o = Otc::make(['lado' => $lado, 'quantidade' => 100]);
    $o->setRelation('otc', PosicaoOtc::make(['preco_entrada' => 1400.0, 'premio_otc' => $premio]));
    expect($o->calcularMtm($merc))->toBe($esp);
})->with('otc_quadrantes');

it('Otc: o prêmio entra no preço efetivo', function () {
    $o = Otc::make(['lado' => 'COMPRADO', 'quantidade' => 100]);
    $o->setRelation('otc', PosicaoOtc::make(['preco_entrada' => 1400.0, 'premio_otc' => 10.0]));
    expect($o->calcularMtm(1450.0))->toBe(6000.0);   // (1450+10−1400)×100×1
});
```

**OPCAO (perna única)** — CALL/PUT × comprada/vendida × ITM/OTM (cobre `Perna::calcularMtm`):

```php
use App\Models\{Opcao, Perna};

function opcaoUmaPerna(string $tipo, float $strike, float $premio, string $ladoPerna): Opcao {
    $op = Opcao::make(['lado' => 'COMPRADO']);   // lado da mãe é informativo (RN-004e)
    $op->setRelation('pernas', collect([
        Perna::make(['tipo_opcao' => $tipo, 'strike' => $strike, 'premio_pago' => $premio, 'quantidade' => 100, 'lado' => $ladoPerna]),
    ]));
    return $op;
}

it('Opcao 1 perna: CALL comprada ITM',  fn () => expect(opcaoUmaPerna('CALL', 1450, 30, 'COMPRADO')->calcularMtm(1500.0))->toBe(2000.0));  // (50−30)×100×1
it('Opcao 1 perna: CALL comprada OTM',  fn () => expect(opcaoUmaPerna('CALL', 1450, 30, 'COMPRADO')->calcularMtm(1400.0))->toBe(-3000.0)); // (0−30)×100×1
it('Opcao 1 perna: PUT comprada ITM',   fn () => expect(opcaoUmaPerna('PUT',  1450, 28, 'COMPRADO')->calcularMtm(1400.0))->toBe(2200.0));  // (50−28)×100×1
it('Opcao 1 perna: CALL vendida ITM',   fn () => expect(opcaoUmaPerna('CALL', 1450, 30, 'VENDIDO')->calcularMtm(1500.0))->toBe(-2000.0));  // (50−30)×100×(−1)
```

### 4.4 `Unit/CalculoMtmTest.php` — estruturas multi-perna (§8.1 + D-302)

`Opcao::calcularMtm` = Σ pernas (sem `if` por estrutura). **Straddle** e **bull call spread**
reproduzem o §8.1 **idêntico**; as demais trazem a conta por perna no comentário:

```php
// helper: monta uma Opcao a partir de pernas [tipo, strike, premio, qtd, lado]
function opcao(array $pernas): Opcao {
    $op = Opcao::make(['lado' => 'COMPRADO']);
    $op->setRelation('pernas', collect(array_map(fn ($p) => Perna::make([
        'tipo_opcao' => $p[0], 'strike' => $p[1], 'premio_pago' => $p[2], 'quantidade' => $p[3], 'lado' => $p[4],
    ]), $pernas)));
    return $op;
}

it('straddle com mercado acima do strike (§8.1)', function () {
    // CALL 1450 c/30 comprada + PUT 1450 c/28 comprada @1500
    // (50−30)×100 + (0−28)×100 = 2000 − 2800 = −800
    expect(opcao([
        ['CALL', 1450, 30, 100, 'COMPRADO'],
        ['PUT',  1450, 28, 100, 'COMPRADO'],
    ])->calcularMtm(1500.0))->toBe(-800.0);
});

it('bull call spread entre os strikes (§8.1)', function () {
    // CALL 1400 c/60 comprada + CALL 1450 c/30 vendida @1500
    // (100−60)×100×1 + (50−30)×100×(−1) = 4000 − 2000 = 2000
    expect(opcao([
        ['CALL', 1400, 60, 100, 'COMPRADO'],
        ['CALL', 1450, 30, 100, 'VENDIDO'],
    ])->calcularMtm(1500.0))->toBe(2000.0);
});

it('strangle (CALL OTM + PUT OTM, ambas compradas) @1550', function () {
    // CALL 1500 c/20: (50−20)×100×1 = 3000 ; PUT 1400 c/18: (0−18)×100×1 = −1800 → 1200
    expect(opcao([
        ['CALL', 1500, 20, 100, 'COMPRADO'],
        ['PUT',  1400, 18, 100, 'COMPRADO'],
    ])->calcularMtm(1550.0))->toBe(1200.0);
});

it('collar (PUT comprada + CALL vendida) @1450', function () {
    // PUT 1400 c/18 comprada: (0−18)×100×1 = −1800 ; CALL 1500 c/20 vendida: (0−20)×100×(−1) = 2000 → 200
    expect(opcao([
        ['PUT',  1400, 18, 100, 'COMPRADO'],
        ['CALL', 1500, 20, 100, 'VENDIDO'],
    ])->calcularMtm(1450.0))->toBe(200.0);
});

it('bear put spread (PUT alta comprada + PUT baixa vendida) @1420', function () {
    // PUT 1450 c/40 comprada: (30−40)×100×1 = −1000 ; PUT 1400 c/20 vendida: (0−20)×100×(−1) = 2000 → 1000
    expect(opcao([
        ['PUT', 1450, 40, 100, 'COMPRADO'],
        ['PUT', 1400, 20, 100, 'VENDIDO'],
    ])->calcularMtm(1420.0))->toBe(1000.0);
});

it('butterfly (long call: +1 1400, −2 1450, +1 1500) @1450', function () {
    // CALL 1400 c/60 comprada 100: (50−60)×100×1 = −1000
    // CALL 1450 c/30 vendida 200: (0−30)×200×(−1) = 6000
    // CALL 1500 c/15 comprada 100: (0−15)×100×1 = −1500 → 3500
    expect(opcao([
        ['CALL', 1400, 60, 100, 'COMPRADO'],
        ['CALL', 1450, 30, 200, 'VENDIDO'],
        ['CALL', 1500, 15, 100, 'COMPRADO'],
    ])->calcularMtm(1450.0))->toBe(3500.0);
});
```

### 4.5 `Unit/CalculoMtmTest.php` — `Futuro` com movimentações (§8.1, item 7)

Reproduz **idêntico** os exemplos do §8.1 (integração trait↔Model, via `setRelation`):

```php
it('preço médio após aumento (§8.1)', function () {
    $f = Futuro::make(['lado' => 'COMPRADO']);
    $f->setRelation('movimentacoes', collect([
        Movimentacao::make(['id' => 1, 'tipo' => 'ABERTURA', 'data_movimentacao' => '2026-01-10', 'quantidade' => 100, 'preco' => 1400.0]),
        Movimentacao::make(['id' => 2, 'tipo' => 'AUMENTO',  'data_movimentacao' => '2026-02-10', 'quantidade' => 50,  'preco' => 1430.0]),
    ]));
    expect($f->precoMedio())->toBe(1410.0)
        ->and($f->quantidadeAtual())->toBe(150.0)
        ->and($f->plRealizado())->toBe(0.0)
        ->and($f->calcularMtm(1450.0))->toBe(6000.0);   // (1450−1410)×150
});

it('redução mantém o PM e gera realizado (§8.1)', function () {
    $f = Futuro::make(['lado' => 'COMPRADO']);
    $f->setRelation('movimentacoes', collect([
        Movimentacao::make(['id' => 1, 'tipo' => 'ABERTURA', 'data_movimentacao' => '2026-01-10', 'quantidade' => 100, 'preco' => 1400.0]),
        Movimentacao::make(['id' => 2, 'tipo' => 'AUMENTO',  'data_movimentacao' => '2026-02-10', 'quantidade' => 50,  'preco' => 1430.0]),
        Movimentacao::make(['id' => 3, 'tipo' => 'REDUCAO',  'data_movimentacao' => '2026-03-10', 'quantidade' => 50,  'preco' => 1440.0]),
    ]));
    expect($f->precoMedio())->toBe(1410.0)
        ->and($f->quantidadeAtual())->toBe(100.0)
        ->and($f->plRealizado())->toBe(1500.0);   // (1440−1410)×50×1
});

it('redução em posição vendida inverte o sinal do realizado (§8.1)', function () {
    $f = Futuro::make(['lado' => 'VENDIDO']);
    $f->setRelation('movimentacoes', collect([
        Movimentacao::make(['id' => 1, 'tipo' => 'ABERTURA', 'data_movimentacao' => '2026-01-10', 'quantidade' => 120, 'preco' => 320.0]),
        Movimentacao::make(['id' => 2, 'tipo' => 'REDUCAO',  'data_movimentacao' => '2026-02-10', 'quantidade' => 30,  'preco' => 305.0]),
    ]));
    expect($f->quantidadeAtual())->toBe(90.0)
        ->and($f->plRealizado())->toBe(450.0);   // (305−320)×30×(−1)
});
```

### 4.6 `Unit/CalculoMtmTest.php` — `sinal()` base e `Perna` (§8.1, item 2)

```php
use App\Models\Perna;

it('sinal: COMPRADO=+1, VENDIDO=−1 na base e na perna', function () {
    expect(Futuro::make(['lado' => 'COMPRADO'])->sinal())->toBe(1)
        ->and(Ndf::make(['lado' => 'VENDIDO'])->sinal())->toBe(-1)
        ->and(Perna::make(['lado' => 'COMPRADO'])->sinal())->toBe(1)
        ->and(Perna::make(['lado' => 'VENDIDO'])->sinal())->toBe(-1);
});

it('sinal: fail-fast (DomainException) em lado inválido — base e perna', function () {
    expect(fn () => Futuro::make(['lado' => 'XPTO'])->sinal())->toThrow(DomainException::class);
    expect(fn () => Perna::make(['lado' => ''])->sinal())->toThrow(DomainException::class);
});

it('Posicao base: calcularMtm sem instrumento lança LogicException (D-204)', function () {
    expect(fn () => (new \App\Models\Posicao(['lado' => 'COMPRADO']))->calcularMtm(100.0))
        ->toThrow(LogicException::class);
});
```

### 4.7 `Unit/HidratacaoPolimorficaTest.php` — `newFromBuilder` direto (D-306)

```php
use App\Models\{Posicao, Futuro, Ndf, Opcao, Otc};

dataset('instrumentos', [
    'FUTURO' => ['FUTURO', Futuro::class],
    'NDF'    => ['NDF',    Ndf::class],
    'OPCAO'  => ['OPCAO',  Opcao::class],
    'OTC'    => ['OTC',    Otc::class],
]);

it('newFromBuilder devolve a subclasse por instrumento (sem banco)', function (string $instr, string $classe) {
    $model = (new Posicao)->newFromBuilder((object) ['instrumento' => $instr, 'lado' => 'COMPRADO']);
    expect($model)->toBeInstanceOf($classe);
})->with('instrumentos');

it('newFromBuilder cai na base quando o instrumento é desconhecido/nulo', function () {
    $model = (new Posicao)->newFromBuilder((object) ['instrumento' => 'XPTO']);
    expect($model)->toBeInstanceOf(Posicao::class)
        ->and($model)->not->toBeInstanceOf(Futuro::class);
});
```

> Este teste **não** abre conexão (chama o método diretamente). O `Feature/HidratacaoPolimorficaTest.php`
> (Fase 2) cobre a hidratação com **banco real** e permanece como está.

---

## 5. Estrutura esperada após a Parte 3

```
tests/
├── Unit/
│   ├── CalculoMtmTest.php                 (expandido: 4 quadrantes×4 instrumentos + multi-perna + futuro/movs + sinal)
│   ├── HidratacaoPolimorficaTest.php      (novo: newFromBuilder direto, sem banco)
│   └── Concerns/
│       ├── ConverteDecimaisTest.php       (novo)
│       └── ReproduzMovimentacoesTest.php  (novo)
└── Feature/
    └── HidratacaoPolimorficaTest.php      (mantido da Fase 2 — com banco)
```

Edições fora de `tests/`: apenas o **recorte `<source>`** no `phpunit.xml` (D-303) e,
se necessário, habilitar `pcov` no `Dockerfile`. `tests/Unit/ExampleTest.php` removido.

---

## 6. Arquivos a entregar (checklist)

- [ ] `phpunit.xml` com `<source>` recortando o núcleo de cálculo (D-303); `pcov`/Xdebug
      coverage disponível no container.
- [ ] `Unit/Concerns/ConverteDecimaisTest.php` — `paraFloat` (string/int/float/null) e
      `arredonda` (2/4/default).
- [ ] `Unit/Concerns/ReproduzMovimentacoesTest.php` — PM ponderado, redução c/ realizado,
      redução total (qtd 0), sinal vendido, **empate de data por id**.
- [ ] `Unit/CalculoMtmTest.php` — **4 quadrantes** de FUTURO/NDF/OTC; OTC com prêmio;
      OPCAO 1 perna (CALL/PUT × comprada/vendida × ITM/OTM); **straddle, bull call spread**
      (§8.1) + **strangle, collar, bear put spread, butterfly**; FUTURO com movs (3 exemplos
      §8.1); `sinal` base/perna + fail-fast; `Posicao::calcularMtm` base → `LogicException`.
- [ ] `Unit/HidratacaoPolimorficaTest.php` — `newFromBuilder` por instrumento + `default`.
- [ ] Remover `tests/Unit/ExampleTest.php` (scaffold).
- [ ] Relatório de cobertura do núcleo anexado ao PR (saída do `--coverage`).

---

## 7. Definition of Done (critérios de aceite)

1. **Suíte unitária verde:** `docker compose exec app ./vendor/bin/pest tests/Unit` passa;
   todos os valores do §8.1 batem **idênticos** (`toBe`).
2. **Cobertura do núcleo ≥ 90 %:** `./vendor/bin/pest --coverage --min=90` (recorte do
   `<source>`, D-303) passa; relatório anexado.
3. **Sem banco no cálculo:** nenhum teste de `tests/Unit/` usa `RefreshDatabase`/conexão;
   com `preventLazyLoading` ligado (D-206), acessar relação não carregada estouraria —
   a suíte roda sem `postgres`/`postgres_test` no ar (exceto o boot do app).
4. **Determinismo do replay:** o teste de **empate de data por `id`** falha se o desempate
   for removido (protege o ponto 4 do parecer `spec_parte_2_critica.md`).
5. **Fail-fast preservado:** `sinal()` inválido (base e `Perna`) lança `DomainException`;
   `Posicao::calcularMtm` base lança `LogicException`.
6. **Sem regressão de qualidade:** `vendor/bin/pint --test` e `vendor/bin/phpstan analyse`
   (nível 8) sem erros; `composer test` global continua verde (Fases 1–2 inclusas).

---

## 8. Riscos e pontos a verificar

| Risco | Mitigação / ação |
|---|---|
| Cast `decimal:` devolve **string** → `toBe()` com `float` falha sutilmente | O cálculo já passa pela borda `ConverteDecimais::paraFloat` (D-MVC-2); montar os Models com `make()` e ler **via método de cálculo**, nunca comparar o atributo cru. Esperados sempre `float` literais (`5000.0`). |
| `toBe()` com `float` de divisão não exata (pm de replay) | Desenhar golden values representáveis (todos os do §8.1 e das estruturas são exatos); onde não for, usar `toEqualWithDelta(.., 1e-9)` (D-305). |
| **`preventLazyLoading` desligado** em teste mascara vazamento de query | Garantir `app()->isProduction() === false` no ambiente de teste (`APP_ENV=testing`); o `Feature` de hidratação (com banco) **não** conta para a meta de 90 % nem para a garantia "sem banco". |
| Cobertura diluída por **persistência pura** não derruba/empola o número | Recorte `<source>` (D-303) exclui Models sem cálculo; a meta de 90 % mede só o núcleo. |
| `pcov`/Xdebug ausente no container → `--coverage` não roda | Verificar `php -m`; habilitar `pcov` na imagem (registrar no `Dockerfile`/PR). CI deve falhar explicitamente se a extensão faltar (não "passar verde" sem medir). |
| Teste de empate de data **passa por acaso** (stable sort coincide com id) | Embaralhar a **ordem de inserção** no array de entrada (REDUCAO antes da ABERTURA) — força o `usort` a reordenar; sem o critério `id`, o resultado divergiria. |
| `Movimentacao::make` com `data_movimentacao` string e cast `date` → `format('Y-m-d')` no replay | O cast `date` do Model converte string→`Carbon`; o replay chama `->format('Y-m-d')`. Confirmar que `make()` aplica o cast (aplica) — senão passar `Carbon::parse(...)`. |
| Esperado de multi-perna calculado errado na spec | Cada estrutura traz a **aritmética por perna** no comentário (D-302); conferir soma antes de fixar `toBe`. |

---

## 9. Referências

- `specs/requisitos.md` §8.1 (exemplos/golden values), §8.4 (metas de cobertura), §4.3
  (fórmulas de cálculo), §7 (RN-001..025).
- `specs/passos_dev.md` — Fase 3 (objetivo, escopo, tarefas, DoD) e Apêndice (RN × Fase).
- `specs/spec_parte_2.md` — Models e *traits* sob teste (D-201/D-202/D-206) e §8 (limitação
  `float` aceita no MVP).
- `specs/future/spec_parte_2_critica.md` — ponto 4 (desempate de data por `id`), travado
  pelo teste do §4.2/DoD #4.
- Pest: datasets, `expect`, `toThrow`, `toEqualWithDelta` — https://pestphp.com/docs.
- Cobertura: `pest --coverage --min`, `<source>` no `phpunit.xml` —
  https://pestphp.com/docs/test-coverage.

---

**Fim do documento.** Próxima etapa: **Fase 4 — Módulo Produtos & Preços** (`passos_dev.md`),
que estreia os Services/API/Livewire e o importador CSV (`FontePrecos`).
