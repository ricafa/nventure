# Especificação dos testes unitários — NeverVenture

**Versão:** 2.0 · **Data:** 2026-06-13 · **Bases:** `requisitos.md` v1.4 (§4, §7, §8) e `ARCHITECTURE.md` v2.0
**Meta de cobertura do domínio:** ≥ 90% (§8.4) · **Runner:** Pest (sobre PHPUnit) + cobertura (Xdebug/PCOV)

> Plano e checklist da suíte **unitária**. Todo valor esperado é calculado à mão e
> comentado no teste. Cita-se a seção (§) e a regra (RN-xxx) que cada conjunto de
> casos cobre. Itens além do que os requisitos exigem aparecem como **Recomendação**
> ou **Premissa**.

---

## 1. Objetivo e escopo

**Objetivo.** Validar, de forma isolada e determinística, a **camada de domínio**
(§4) — regras de cálculo (MtM polimórfico, preço médio por *replay*, P&L
realizado). É a camada de maior risco e de meta de cobertura mais alta (≥ 90%, §8.4).

**Dentro do escopo (unitário):**
- `Posicao` (base) e subclasses `Futuro`, `NDF`, `Opcao`/`Perna`, `OTC` (§4.2–§4.3) —
  PHP puro, sem Laravel.
- `MotorMtm` (§4.4) com **test doubles** (fakes dos contratos de repositório).

**Fora do escopo (vai para Feature/integração, §8.2):** Eloquent/PostgreSQL,
rotas HTTP, Livewire, Sanctum/Policies, upload de CSV, migrations. A hidratação do
repositório (§4.5) é testada em Feature, pois depende do banco. Testes de domínio
**não** usam `RefreshDatabase` nem o framework.

**Definição de "pronto".** Cada regra de cálculo (RN-021/022/023/024 e as fórmulas
de MtM por tipo) com ≥ 1 teste; cobertura de domínio ≥ 90% no CI; suíte verde e
determinística.

---

## 2. Organização e convenções

- **Estrutura** (espelha `app/Dominio/`, conforme `ARCHITECTURE.md` §3):

```
tests/
├── Unit/
│   └── Dominio/
│       ├── PosicaoTest.php          # sinal(), plRealizado() padrão
│       ├── FuturoTest.php           # replay/precoMedio/quantidadeAtual/plRealizado/calcularMtm
│       ├── NdfTest.php
│       ├── OpcaoTest.php            # Perna + Opcao (estruturas multi-perna)
│       ├── OtcTest.php
│       └── MotorMtmTest.php         # com fakes dos contratos
├── Helpers.php                      # criaFuturo(), criaOpcao(), ...
└── Pest.php                         # bootstrap; carrega Helpers.php
```

- **Sintaxe Pest** com `it('...')`/`test('...')` em **português**
  (ex.: `it('redução mantém o preço médio e gera realizado')`).
- **Padrão AAA**; **um conceito por teste**.
- **Datasets do Pest** (`->with([...])`) para varrer comprado/vendido × a favor/contra.
- **Helpers/builders** para reduzir o boilerplate dos exemplos do §8.1.
- **Sem I/O, sem framework, sem banco**; determinístico (datas fixas via
  `DateTimeImmutable`).

---

## 3. Estratégia de dados de teste

**Valores de referência** (calculados à mão; reutilizados):

| Cenário | Entradas | Resultado esperado |
|---|---|---|
| Futuro comprado simples | 100 @ 1400; mercado 1450 | (1450−1400)×100 = **5.000** |
| Preço médio após aumento | ABERTURA 100@1400 + AUMENTO 50@1430 | pm **1410**; qtd **150** |
| MtM com pm | pm 1410, qtd 150, mercado 1450 | (1450−1410)×150 = **6.000** |
| Redução mantém pm + realizado | + REDUCAO 50@1440 | pm **1410**; qtd **100**; realizado **1.500** |
| Redução em vendido | ABERTURA 120@320 (VENDIDO) + REDUCAO 30@305 | qtd **90**; realizado **450** |
| Straddle | CALL 1450(p30) + PUT 1450(p28), 100 cada; 1500 | (50−30)×100 + (0−28)×100 = **−800** |
| Bull call spread | CALL 1400(p60) comprada + CALL 1450(p30) vendida, 100; 1500 | **2.000** |

**Cuidados:** `expect(...)->toBe(...)` é estrito (`===`); para resultados com
possível erro de ponto flutuante use `->toEqualWithDelta($v, 0.001)`. Atenção ao
**sinal** em posições vendidas; comentar a conta ao lado de cada `expect`.
**Premissa:** arredondamento monetário a 2 casas para BRL na borda (serviço/API),
não no domínio.

---

## 4. Especificação por unidade

### 4.1 `Posicao` (base, §4.2)

| Caso | Entrada | Esperado |
|---|---|---|
| sinal de comprado | lado=COMPRADO | `sinal() === 1` |
| sinal de vendido | lado=VENDIDO | `sinal() === -1` |
| pl realizado padrão | NDF/Opcao/OTC | `plRealizado() === 0.0` |

### 4.2 `Futuro` + `Movimentacao` (§4.3.1; RN-020..024)

| Caso | Entrada | Esperado |
|---|---|---|
| sem movimentações usa preço de entrada | sem movs | `precoMedio()===precoEntrada`; `quantidadeAtual()===quantidade`; `plRealizado()===0` |
| abertura define preço médio | ABERTURA 100@1400 | pm 1400; qtd 100 |
| preço médio após aumento | + AUMENTO 50@1430 | pm **1410**; qtd 150; realizado 0 (RN-021) |
| redução mantém pm e gera realizado | + REDUCAO 50@1440 | pm 1410; qtd 100; realizado **1.500** (RN-023) |
| redução em vendido inverte sinal | VENDIDO; ABERTURA 120@320 + REDUCAO 30@305 | qtd 90; realizado **450** |
| redução total zera quantidade | reduzir toda a quantidade | `quantidadeAtual()===0` (RN-022) |
| movimentações fora de ordem | datas desordenadas (ABERTURA primeiro no empate) | mesmo pm/qtd/realizado da ordem correta |
| MtM usa pm e quantidade atual | pm 1410, qtd 150, mercado 1450 | **6.000** |

### 4.3 `NDF` (§4.3.2)

Dataset comprado/vendido × a favor/contra. `MtM = (preco − taxaContratada) ×
valorNocional × sinal`. Incluir caso da convenção cambial (resultado já em BRL
quando `cambio_brl = 1`, §1.4/RN-015 — conversão é do motor, não da classe).

### 4.4 `Opcao` + `Perna` (§4.3.3; RN-004*)

| Caso | Esperado |
|---|---|
| valor intrínseco CALL | `max(spot − strike, 0)` |
| valor intrínseco PUT | `max(strike − spot, 0)` |
| prêmio e sinal da perna | `(VI − premioPago) × qtd × sinal` |
| soma das pernas | MtM da estrutura = Σ pernas |
| straddle acima do strike | **−800** (§8.1) |
| bull call spread | **2.000** (§8.1) |
| perna comprada e vendida | sinais por perna respeitados |

### 4.5 `OTC` (§4.3.4)

`MtM = (precoMercado + premioOtc − precoEntrada) × quantidade × sinal`. Dataset
comprado/vendido e prêmio positivo/negativo; conferir o preço efetivo.

### 4.6 `MotorMtm` (§4.4) — com *test doubles*

Fakes que implementam os contratos (`RepositorioPosicoes/Precos/Mtm`):

| Caso | Esperado |
|---|---|
| calcula e persiste MtM em BRL | `mtmValor = mtmOrig × cambioBrl` (RN-015) |
| pl acumulado inclui realizado | `plAcumulado === mtmBrl + plRealizado × cambioBrl` (RN-023) |
| sem preço marca falha e continua | falha registrada; demais processam (RN-012) |
| idempotente faz upsert | rodar 2× na mesma data não duplica (RN-013) |
| processa só ABERTA | posições não-ABERTA ignoradas (RN-011) |
| sem `if` por tipo | mistura de tipos via `calcularMtm()` polimórfico |

---

## 5. Casos de borda

- Quantidade/preço nos limites; movimentações em datas iguais (ABERTURA primeiro).
- Posição encerrada/vencida não processada (RN-011).
- Arredondamento (somas com dízimas) → `toEqualWithDelta`.
- **Recomendação (property-based, biblioteca Eris):** para qualquer sequência válida
  de movimentações, `quantidadeAtual() === Σ entradas − Σ saídas` (invariante RN-024)
  e `quantidadeAtual() >= 0`.

---

## 6. Cobertura e critérios de aceite

- **Domínio ≥ 90%**, aplicação ≥ 70%, total ≥ 75% (§8.4) — Pest `--coverage`
  (Xdebug/PCOV) no CI; **Recomendação:** `--coverage --min=90` para quebrar o build
  abaixo da meta no pacote de domínio.
- Cada RN de cálculo (RN-021/022/023/024) e cada fórmula de MtM por tipo com ≥ 1
  teste.
- **Recomendação:** **Infection** (mutation testing) sobre `app/Dominio/` para
  validar a força dos `expect`.

---

## 7. Rastreabilidade RN/fórmula → testes

| RN / fórmula | Teste(s) |
|---|---|
| `sinal()` (§4.2) | sinal de comprado / sinal de vendido |
| `plRealizado()` padrão (§4.2) | pl realizado padrão |
| MtM Futuro (§4.3.1) | MtM por lado e direção (dataset) |
| **RN-021** (média ponderada) | preço médio após aumento; abertura define preço médio |
| **RN-022** (redução total zera) | redução total zera quantidade |
| **RN-023** (P&L realizado) | redução mantém pm e gera realizado; redução em vendido; pl acumulado inclui realizado |
| **RN-024** (invariante) | property-based (Eris, §5) |
| MtM NDF / RN-015 (§4.3.2) | NDF (dataset); motor calcula e persiste |
| MtM Opção / RN-004* (§4.3.3) | valor intrínseco; straddle; bull call spread |
| MtM OTC (§4.3.4) | OTC (dataset) |
| RN-011 / RN-012 / RN-013 (motor) | processa só ABERTA; sem preço marca falha; idempotente faz upsert |

> RN-001..006 (validações de **cadastro**) são exercitadas na camada de aplicação/
> Form Requests (Feature, §8.2), fora do escopo unitário de domínio.

---

## 8. Exemplo de teste

**Helper** (`tests/Helpers.php`, carregado pelo `Pest.php`):

```php
<?php

use App\Dominio\Posicoes\{Futuro, Movimentacao};

function criaFuturo(
    string $lado = 'COMPRADO',
    float $quantidade = 100,
    float $precoEntrada = 1400.00,
    array $movimentacoes = [],
): Futuro {
    return new Futuro(
        id: 1, produtoId: 1, lado: $lado, quantidade: $quantidade,
        dataEntrada: new DateTimeImmutable('2026-01-10'),
        dataVencimento: new DateTimeImmutable('2026-09-15'),
        status: 'ABERTA', precoEntrada: $precoEntrada, codigoContrato: 'ZSU24',
        movimentacoes: $movimentacoes,
    );
}
```

**Teste parametrizado** (dataset comprado/vendido × a favor/contra):

```php
<?php // tests/Unit/Dominio/FuturoTest.php

it('calcula o MtM do futuro por lado e direção', function (string $lado, float $precoMercado, float $esperado) {
    // 100 contratos @ 1400; MtM = (mercado − 1400) × 100 × sinal
    expect(criaFuturo(lado: $lado)->calcularMtm($precoMercado))->toBe($esperado);
})->with([
    'comprado a favor' => ['COMPRADO', 1450.00,  5000.00],
    'comprado contra'  => ['COMPRADO', 1350.00, -5000.00],
    'vendido a favor'  => ['VENDIDO',  1350.00,  5000.00],
    'vendido contra'   => ['VENDIDO',  1450.00, -5000.00],
]);
```

**Teste de regra com movimentações** (RN-021/023):

```php
use App\Dominio\Posicoes\Movimentacao;

it('redução mantém o preço médio e gera realizado', function () {
    $futuro = criaFuturo(quantidade: 100, movimentacoes: [
        new Movimentacao('ABERTURA', new DateTimeImmutable('2026-01-10'), 100, 1400.00),
        new Movimentacao('AUMENTO',  new DateTimeImmutable('2026-02-10'),  50, 1430.00), // pm → 1410
        new Movimentacao('REDUCAO',  new DateTimeImmutable('2026-03-10'),  50, 1440.00),
    ]);

    expect($futuro->precoMedio())->toBe(1410.00)          // redução não muda o pm (RN-021)
        ->and($futuro->quantidadeAtual())->toBe(100.0)
        ->and($futuro->plRealizado())->toBe(1500.00);     // (1440 − 1410) × 50 (RN-023)
});
```

**Motor com fakes** (esboço):

```php
use App\Aplicacao\Contratos\{RepositorioPrecos, RepositorioMtm};

$repoPrecos = new class implements RepositorioPrecos {
    public array $precos = [];                                  // [ "produtoId|data" => PrecoReferencia ]
    public function buscar(int $produtoId, \DateTimeImmutable $data): ?object {
        return $this->precos["{$produtoId}|{$data->format('Y-m-d')}"] ?? null;
    }
};

$repoMtm = new class implements RepositorioMtm {
    public array $registros = [];                               // upsert por (posicaoId, data)
    public function buscarUltimoAnterior(int $posicaoId, \DateTimeImmutable $data): ?object { return null; }
    public function upsert(int $posicaoId, int $precoRefId, \DateTimeImmutable $dataCalculo,
                           float $precoMercado, float $mtmValor, float $variacaoDia, float $plAcumulado): void {
        $this->registros["{$posicaoId}|{$dataCalculo->format('Y-m-d')}"] = compact(
            'mtmValor', 'variacaoDia', 'plAcumulado',
        );
    }
};
```

---

**Fim do documento.**
