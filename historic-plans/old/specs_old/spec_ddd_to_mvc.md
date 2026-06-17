# Spec — Migração de DDD em camadas → MVC (Laravel) com Service/Interface/Facade

> **Status:** plano (não implementado). Este documento **apenas planeja** a
> re-arquitetura; **nenhum** arquivo de código ou spec existente é alterado por ele.
> **Fonte da verdade de negócio:** `specs/requisitos.md` (v1.4). **Arquitetura
> vigente:** `specs/ARCHITECTURE.md` (v2.0). Convenção de referências: §/RN do
> `requisitos.md` e decisões `D-xxx` de `decisions.md`.
>
> **Decisão de arquitetura desta migração:** **Alternativa A — *fat model*
> (ActiveRecord)**. O cálculo de MtM vive nos **Models Eloquent**; não há domínio em
> PHP puro separado. A Alternativa B (domínio puro + hidratação) foi considerada e
> **descartada** — registro em **§1.2**.

---

## 0. Contexto e natureza desta migração

O projeto **NeverVenture** hoje é composto por **especificação + mocks**: não há
código PHP/Laravel materializado no repositório (apenas `specs/` e `mock_telas/`).
A "estrutura DDD" a migrar é, portanto, a **arquitetura planejada** descrita em
`specs/ARCHITECTURE.md` §3–§4 e detalhada nos recortes por parte (`spec_parte_6/7/9`,
`specs_parte_8`).

Consequência prática: esta migração é uma **re-arquitetura do alvo de
implementação** — reescreve-se o *blueprint* (camadas hexagonais → MVC Laravel),
não um código existente. As Partes 1–13 ainda **não escritas** passam a seguir a
estrutura MVC deste documento; as Partes já especificadas (6–9) ganham o de-para da
§5 para quando forem implementadas.

### Arquitetura de origem (DDD / hexagonal — `ARCHITECTURE.md` §3)

```
app/
├── Dominio/          # PHP puro, sem Laravel/Eloquent (Posicao, MotorMtm, VOs)
├── Aplicacao/        # casos de uso (Servico*) + Contratos/ (portas) + Excecoes/
├── Infraestrutura/   # Models Eloquent + Repositorios (impl. dos contratos) + Csv/
├── Http/             # Controllers/Api, Requests, Resources, Middleware
├── Livewire/         # componentes de UI
├── Policies/  Providers/
```

Princípios de origem: dependências apontam **para dentro**; domínio não conhece
Eloquent; persistência atrás de **contratos** (inversão de dependência ligada num
Service Provider); polimorfismo do motor sem `if` por tipo (ADR-001/§4.4);
Repository + Factory na hidratação (ADR-003/§4.5).

---

## 1. Decisões que orientam o alvo

| # | Decisão | Escolha | Efeito |
|---|---|---|---|
| **DM-1** | Domínio polimórfico × Models | **Alternativa A — *fat model*.** `calcularMtm()`/`plRealizado()`/`replay()` vivem nos **Models Eloquent**; o motor itera Models direto (sem domínio em PHP puro separado). | **Revoga o ADR-003** (domínio puro separado do Eloquent) e **elimina a camada de tradução ORM⇄domínio** (§4.5). B descartada (§1.2). |
| **DM-2** | Repositórios + Contratos | **Serviços usam Eloquent direto** — sem `Contratos/` nem `Repositorios*`. | Remove a inversão de dependência de persistência; menos camadas. |
| **DM-3** | Exposição dos serviços | **Facade + Injeção de Dependência** — `Posicoes::criarFuturo(...)` e também DI nos controllers/Livewire. | Atende ao "interface/facade/service" pedido. |

> **Sobre o pedido original ("manter o domínio em PHP puro dentro de um service").**
> A escolha **DM-1 (fat model)** o atende **parcialmente**: o cálculo passa a residir
> nos Models, não em classes PHP puras isoladas. Para **não perder** o que o pedido
> protegia (testabilidade e polimorfismo), o plano impõe duas salvaguardas:
> 1. Métodos de cálculo (`calcularMtm`, `plRealizado`, `precoMedio`, `sinal`,
>    `replay`) permanecem **puros** — operam sobre atributos/relações já carregados,
>    **sem** montar query nem tocar no banco (§6.2); aritmética extraível para **traits
>    puros** em `app/Models/Concerns/`.
> 2. O polimorfismo do motor (ADR-001) é preservado por uma **fábrica de hidratação**
>    no próprio Eloquent (`newFromBuilder` no Model base, §6.1), **não** por
>    `if/switch` no motor.

### 1.2 Alternativa considerada e descartada — B (domínio puro + hidratação)

Para registro (estilo ADR). **B** manteria duas famílias de objetos: **Models finos**
(só persistência) + **classes de domínio em PHP puro** (`app/Services/Dominio/`), com
um **`HidratadorPosicao`** traduzindo Model→domínio (preservando o ADR-003).

- **A favor de B:** domínio naturalmente testável sem banco; aderência total ao pedido
  "domínio em PHP puro"; conversão decimal string→float concentrada na hidratação.
- **Contra B (motivos do descarte):** mais classes e o **código repetitivo de
  tradução** ida/volta (Model⇄domínio), que precisa ficar em sincronia com o schema;
  mais camadas para uma ferramenta interna de MVP. Preferiu-se o caminho mais enxuto.
- **Consequência aceita de escolher A:** acoplamento do cálculo ao Eloquent —
  mitigado pelas salvaguardas §6.2 (cálculo puro + traits) e §6.3 (borda de decimais).

> Se no futuro o acoplamento ao ORM pesar (ex.: regras de risco muito mais ricas), a
> migração A→B é localizada: extrair o cálculo dos Models para `Services/Dominio/` e
> introduzir o hidratador, sem tocar em Controllers/Requests/Resources/Services.

### Premissas explícitas (não cobertas pelos requisitos)
- **P-1** — Stack permanece: PHP 8.2+, Laravel 11, PostgreSQL 15+, Blade+Livewire 3,
  Sanctum, Pest (`ARCHITECTURE.md` §2; **não** se rediscute a stack).
- **P-2** — Idioma de domínio em **português** mantido (ADR-006): `Posicao`,
  `calcularMtm`, `precoMedio`. Pastas MVC em inglês padrão do Laravel
  (`Models`, `Services`, `Http`), nomes de classe de negócio em português.
- **P-3** — A UI continua **Livewire** (ADR-004): apresentação reativa que convive com
  a API REST, ambos sobre os mesmos Services (D-808/D-907 mantidos).
- **P-4** — Migrations, esquema do §3, constraints e índices (incl. `uq_mov_abertura`
  parcial) **não mudam** — a migração é de **código de aplicação**, não de banco.

---

## 2. Arquitetura alvo (MVC Laravel — fat model)

```
app/
├── Models/                     # M — Eloquent "gordo" (regra + cálculo de MtM)
│   ├── Posicao.php             #   base; fábrica polimórfica em newFromBuilder (§6.1)
│   ├── Futuro.php Ndf.php Opcao.php Otc.php   # subclasses (extends Posicao)
│   ├── Perna.php  Movimentacao.php            # filhas (OPCAO; FUTURO)
│   ├── Produto.php  PrecoReferencia.php
│   ├── MtmDiario.php  MotorExecucao.php
│   └── Concerns/               # traits de cálculo PURO reutilizável (§6.2) — ex.: CalculaMtmFuturo
│
├── Http/
│   ├── Controllers/Api/        # C — Produto, Preco, Posicao, Movimentacao, Motor, Relatorio
│   ├── Requests/               # Form Requests (validação estrutural; RNs no Service)
│   ├── Resources/              # API Resources (serialização §5.1)
│   └── Middleware/
│
├── Services/                   # regras de negócio (casos de uso) — usam Eloquent direto (DM-2)
│   ├── ServicoProdutos  ServicoPrecos  ServicoPosicoes  ServicoMovimentacoes
│   ├── ServicoMotor  MotorMtm           # MotorMtm = núcleo de orquestração do cálculo
│   ├── ServicoRelatorios
│   └── Dados/                  # DTOs/read models e VOs de saída (§5.6) — PHP puro
│       ├── ResumoExecucao  ResultadoProcessamento  RegistroMtm
│       └── PosicaoResumo  PosicaoDetalhe  EstadoMovimentacao  ResultadoImportacao
│
├── Facades/                    # Produtos, Precos, Posicoes, Motor, Relatorios (§5.5)
├── Support/Csv/                # ImportadorPrecosCsv (ex-Infraestrutura/Csv) — implementa FontePrecos
├── Livewire/                   # V — componentes (Posicoes, Precos, Motor, Relatorios, Dashboard, Produtos)
├── Console/Commands/           # ProcessarMotorCommand (motor:processar)
├── Exceptions/                 # ErroAplicacao, ErroValidacao, ErroConflito, ErroNaoEncontrado
├── Policies/                   # RBAC por perfil (Parte 10)
└── Providers/                  # AppServiceProvider: bind dos serviços p/ as Facades
routes/  database/  resources/views/  tests/  (inalterados na forma; conteúdo ajustado)
```

### O que **sai** (camadas DDD eliminadas)
- `app/Dominio/` — **dissolvido**: cálculo migra para `app/Models/` (+ `Concerns/`);
  VOs de saída para `app/Services/Dados/`.
- `app/Aplicacao/Contratos/` — **removido** (DM-2). Sem portas de persistência.
- `app/Infraestrutura/Repositorios/` — **removido** (DM-2). Consultas nos Services
  (Eloquent) ou em *query scopes* dos Models.
- `app/Infraestrutura/Models/` — **promovido** a `app/Models/` (e os Models passam a
  conter a regra de cálculo).
- `app/Infraestrutura/Csv/` — vira `app/Support/Csv/`.

### O que **fica** (com novo papel/local)
- `Servico*` saem de `app/Aplicacao/...` para `app/Services/` (D-607: **validação de
  negócio mora no serviço**, reusada por API e Livewire).
- Exceções → `app/Exceptions/` (mapeamento ao envelope §5.1 no handler central de
  `bootstrap/app.php` — D-605 mantido).
- `Http/`, `Livewire/`, `Policies/`, `Console/Commands/`, `Providers/`: mesmos papéis,
  ajustando *imports*.

---

## 3. Fluxo de dependências (origem → alvo)

**Origem (hexagonal):**
```
Http / Livewire → Aplicação(Servico) → Contrato(porta) ←impl— Infraestrutura(Repositorio→Eloquent)
                                     → Domínio (PHP puro)
```

**Alvo (MVC — fat model):**
```
Http(Controller) / Livewire → Service (regra de negócio) → Model Eloquent (M, cálculo + persistência)
Facade ─(resolve)→ Service                                  ↑ newFromBuilder polimórfico (§6.1)
```

- Some o nível "Contrato/porta": o Service fala **diretamente** com os Models.
- Some a "tradução ORM⇄domínio": o objeto que o motor usa **é** o Model.
- A **Facade** é açúcar sobre o Service registrado no container (DI continua
  disponível).

---

## 4. Como cada padrão obrigatório sobrevive à migração

`ARCHITECTURE.md` §4 fixa cinco padrões vindos dos requisitos:

| Padrão (origem) | Como fica no MVC fat model |
|---|---|
| **(a) Polimorfismo sobre condicionais** (ADR-001, §2.3/§4.4) | `MotorMtm` (em `app/Services/`) itera `Posicao` (Model base) e chama `$posicao->calcularMtm($preco)`; cada subclasse-Model (`Futuro`/`Ndf`/`Opcao`/`Otc`) implementa o seu. O "switch" único vai para o `newFromBuilder` do Model (§6.1), **não** para o motor. |
| **(b) Aberto/fechado** | Novo instrumento = novo Model `extends Posicao` + um `case` no `match` do `newFromBuilder`. Motor e Services não mudam. |
| **(c) Repository + Factory** (ADR-003, §4.5) | *Repository* sai (DM-2); o *Factory* de hidratação por `instrumento` **permanece**, agora dentro do Eloquent (`newFromBuilder`). Consultas viram *query scopes*/métodos de Service. |
| **(d) Idempotência** (RN-013) — UPSERT por `(posicao_id, data_calculo)` | `MtmDiario::updateOrCreate([...])` no `ServicoMotor`. Proveniência (D-803: só sobrescrever `execucao_id`/`processado_em` quando valor financeiro muda) via `isDirty()`. |
| **(e) Auditoria por design** (RN-025) | `MotorExecucao` registra cada execução; `Movimentacao` imutável (sem update/delete expostos); colunas `criado_por`/`criado_em`. |

---

## 5. De-para detalhado por módulo

Métodos de cálculo de domínio **não** são reescritos; apenas mudam de hospedeiro
(classe PHP pura → Model Eloquent), preservando as fórmulas e os testes já cobertos.

### 5.1 Posições (`spec_parte_7`)

| Origem (DDD) | Alvo (MVC fat model) | Observação |
|---|---|---|
| `app/Dominio/Posicoes/Posicao.php` (+ `Futuro,Ndf,Opcao,Otc`) | `app/Models/{Posicao,Futuro,Ndf,Opcao,Otc}.php` | Vira fat model; **mantém** `calcularMtm()`, `plRealizado()`, `sinal()`, `Futuro::replay/precoMedio/quantidadeAtual`. Cálculo permanece "puro" (§6.2). |
| `app/Dominio/Posicoes/{Movimentacao,Perna}.php` (VOs) | `app/Models/{Movimentacao,Perna}.php` | Tornam-se Models (filhas de FUTURO/OPCAO). `Movimentacao` imutável (RN-025). |
| `app/Infraestrutura/Models/PosicaoFuturoModel`, `PosicaoNdfModel`, `PosicaoOpcaoModel`, `PosicaoOpcaoPernaModel`, `PosicaoOtcModel`, `MovimentacaoModel` | **Fundidos** nos Models de `app/Models/` | Com `newFromBuilder` (§6.1) e relação-filha, cada tipo é **um único** Model; as classes "*Model" duplicadas deixam de existir. |
| `app/Aplicacao/Contratos/RepositorioPosicoes.php` | **removido** (DM-2) | `listar/detalhar/salvar*/registrarMovimentacao/encerrar/remover` migram para o Service usando Eloquent. |
| `app/Infraestrutura/Repositorios/RepositorioPosicoesEloquent.php` | **removido** (DM-2) | A hidratação `match` (§4.5) vira `newFromBuilder` do Model `Posicao`. |
| `app/Aplicacao/Posicoes/ServicoPosicoes.php`, `ServicoMovimentacoes.php` | `app/Services/ServicoPosicoes.php`, `ServicoMovimentacoes.php` | Mesmas RNs (RN-001..006, RN-020..025). Transação + lock `FOR UPDATE` (D-713) com `DB::transaction` + `Model::lockForUpdate()` no Service (§6.4). |
| `app/Aplicacao/Posicoes/{PosicaoResumo,PosicaoDetalhe,EstadoMovimentacao}.php` | `app/Services/Dados/{...}.php` | DTOs/read models de saída (D-704). |
| `Http/Controllers/Api/PosicaoController`, `MovimentacaoController`, `Requests/*`, `Resources/*` | **mesmos**, só *imports* | Form Requests só estrutura (D-607/D-715); RNs no Service. |
| `app/Livewire/Posicoes/*` | **mesmos** | Injetam `ServicoPosicoes`/`ServicoMovimentacoes` (ou Facade `Posicoes`). |

### 5.2 Motor MtM (`specs_parte_8`)

| Origem | Alvo (MVC fat model) | Observação |
|---|---|---|
| `app/Dominio/Motor/MotorMtm.php` | `app/Services/MotorMtm.php` | `processarDia()` itera Models `Posicao` (via query do `ServicoMotor`) e chama `calcularMtm()` polimórfico. **Núcleo de cálculo não reescrito** — só troca o tipo iterado (domínio puro → Model). |
| `app/Dominio/Motor/{ResultadoProcessamento,RegistroMtm}.php` | `app/Services/Dados/{...}.php` | VOs de saída/intermediários (PHP puro). |
| `app/Aplicacao/Contratos/{RepositorioMtm,RepositorioExecucoes}.php` | **removidos** (DM-2) | `upsert`/`buscarUltimoAnterior`/abrir/fechar/listar/detalhar viram Eloquent no `ServicoMotor`. |
| `app/Infraestrutura/Repositorios/{RepositorioMtmEloquent,RepositorioExecucoesEloquent}.php` | **removidos** | `MtmDiario::updateOrCreate(...)` (RN-013) e `MotorExecucao::create/save`. |
| `app/Infraestrutura/Models/{MtmDiarioModel,MotorExecucaoModel}.php` | `app/Models/{MtmDiario,MotorExecucao}.php` | `falhas` JSONB, casts `decimal:`, FKs mantidos. |
| `app/Aplicacao/Motor/{ServicoMotor,ResumoExecucao}.php` | `app/Services/ServicoMotor.php` + `app/Services/Dados/ResumoExecucao.php` | Orquestração + auditoria + RN-014 (marca vencidas só quem teve sucesso, D-804) — lógica idêntica, sem repositórios. |
| `app/Console/Commands/ProcessarMotorCommand.php` + `routes/console.php` | **mantidos** | `motor:processar` chama `ServicoMotor::processar()`. Agendamento `weekdays()` (D-806). |
| `Http/.../MotorController`, Request, Resources; `Livewire/Motor/*` | **mantidos** | Livewire injeta `ServicoMotor` direto (sem self-call HTTP — D-808). |

### 5.3 Preços e Produtos (`spec_parte_6`)

| Origem | Alvo (MVC fat model) | Observação |
|---|---|---|
| `app/Dominio/Precos/PrecoReferencia.php` (VO) | `app/Models/PrecoReferencia.php` | VO simples (sem cálculo de MtM) → vira Model; funde-se com `PrecoReferenciaModel`. |
| `app/Infraestrutura/Models/{PrecoReferenciaModel,ProdutoModel}.php` | `app/Models/{PrecoReferencia,Produto}.php` | Relações `produto()`, `precos()`, `posicoes()` mantidas. |
| `app/Aplicacao/Contratos/{RepositorioPrecos,FontePrecos}.php` | `FontePrecos` **mantido como interface** (§7); `RepositorioPrecos` **removido** | `FontePrecos` é a porta de ingestão que sobrevive (evolução Fase 4). |
| `app/Infraestrutura/Repositorios/RepositorioPrecosEloquent.php` | **removido** (DM-2) | Consultas no `ServicoPrecos`. |
| `app/Infraestrutura/Csv/ImportadorPrecosCsv.php` | `app/Support/Csv/ImportadorPrecosCsv.php` | **Implementa `FontePrecos`** (mantém anti-formula-injection CWE-1236, §SECURITY). |
| `app/Aplicacao/{Precos/ServicoPrecos,Produtos/ServicoProdutos}.php` | `app/Services/{ServicoPrecos,ServicoProdutos}.php` | RN-007..010 + RN-010a (bloqueio de exclusão de preço referenciado → 409) no Service. |
| `app/Aplicacao/Precos/ResultadoImportacao.php` | `app/Services/Dados/ResultadoImportacao.php` | VO de saída do upload. |

### 5.4 Relatórios (`spec_parte_9`)

| Origem | Alvo (MVC fat model) | Observação |
|---|---|---|
| `app/Aplicacao/Contratos/RepositorioRelatorios.php` | **removido** (DM-2) | Consultas agregadas (`mtm_diario × posicao × produto`, RN-016..019) no `ServicoRelatorios` via *query builder* Eloquent. |
| `app/Infraestrutura/Repositorios/RepositorioRelatoriosEloquent.php` | **removido** | Idem. |
| `app/Aplicacao/Relatorios/ServicoRelatorios.php` + read models/VOs | `app/Services/ServicoRelatorios.php` + `app/Services/Dados/*` | 4 visões §5.2.5; preço médio do FUTURO reusa o cálculo no Model `Futuro`. |
| `Http/.../RelatorioController`, Request, Resources; `Livewire/{Relatorios,Dashboard}/*` | **mantidos** | Injeção direta do Service (D-907). |

### 5.5 Facades (DM-3 — novo)

Uma Facade por serviço, registrando o *singleton* no `AppServiceProvider`:

| Facade (`app/Facades/`) | Resolve para | Exemplo de uso |
|---|---|---|
| `Produtos` | `ServicoProdutos` | `Produtos::criar($dados)` |
| `Precos` | `ServicoPrecos` | `Precos::importarCsv($arquivo)` |
| `Posicoes` | `ServicoPosicoes` | `Posicoes::criarFuturo($dados)` |
| `Movimentacoes` *(opcional)* | `ServicoMovimentacoes` | `Movimentacoes::registrar($id, $dados)` |
| `Motor` | `ServicoMotor` | `Motor::processar($data, 'agendador')` |
| `Relatorios` | `ServicoRelatorios` | `Relatorios::exposicao($data)` |

> **Convenção:** Facades para chamadas convenientes/estáticas (Commands, Livewire);
> **Controllers** preferem **DI por construtor** (mais testável). Ambos resolvem o
> mesmo *singleton* — sem duplicação de estado.

### 5.6 DTOs/VOs de saída

Todos os *value objects* de saída e read models (`ResumoExecucao`,
`ResultadoProcessamento`, `RegistroMtm`, `PosicaoResumo`, `PosicaoDetalhe`,
`EstadoMovimentacao`, `ResultadoImportacao`) consolidam em **`app/Services/Dados/`**,
em **PHP puro** (sem Eloquent) — contratos de dados entre Service e apresentação
(desacoplam a API do Model, D-801).

---

## 6. Pontos delicados da migração (a fusão "fat model" exige cuidado)

### 6.1 Polimorfismo com Eloquent — fábrica de hidratação (crux)

O esquema tem `posicao` (mãe) + uma filha por instrumento (`posicao_futuro`, etc.).
Para que `Posicao::query()->get()` devolva a **subclasse certa** (e o motor chame
`calcularMtm()` polimórfico sem `if`), o Model base sobrescreve a hidratação por
`instrumento` — réplica do `match` do §4.5, agora **dentro** do ORM:

```php
// app/Models/Posicao.php
public function newFromBuilder($attributes = [], $connection = null)
{
    $classe = match ($attributes->instrumento ?? null) {
        'FUTURO' => Futuro::class, 'NDF' => Ndf::class,
        'OPCAO'  => Opcao::class,  'OTC' => Otc::class,
        default  => static::class,
    };
    $model = (new $classe)->newInstance([], true);
    $model->setRawAttributes((array) $attributes, true);
    $model->setConnection($connection ?: $this->getConnectionName());
    return $model;
}
```

- Subclasses (`Futuro extends Posicao`) apontam `$table = 'posicao'` e acessam a filha
  por relação (`hasOne`) com eager loading. **Decisão de implementação** (`D-MVC-1`):
  (a) STI sobre a view/JOIN `posicao`+filha, ou (b) subclasse acessa a filha por
  relação. **Recomendado (b)** por menor acoplamento ao SQL.
- **Aberto/fechado preservado:** novo instrumento = novo `case` aqui + novo Model.

### 6.2 Manter o cálculo "puro" dentro do Model (salvaguarda do pedido original)

Para preservar a testabilidade que o domínio PHP puro dava, os métodos de cálculo
**não** consultam o banco:
- `calcularMtm()`, `plRealizado()`, `sinal()`, `Futuro::replay/precoMedio/
  quantidadeAtual` operam **só** sobre atributos/relações **já carregados** (eager
  loading garantido pelo Service). Nada de `->query()` lá dentro.
- Extrair a aritmética para **traits puros** em `app/Models/Concerns/`
  (ex.: `CalculaMtmFuturo`) que recebem valores primitivos — testáveis sem instanciar
  Eloquent. Recupera a maior parte do benefício do "domínio puro" pedido.

### 6.3 Decimais financeiros (NUMERIC ⇄ PHP)

O domínio puro usava `float` (D-305); os casts `decimal:` do Eloquent retornam
**string**. Ao fundir no Model:
- Padronizar a borda: o cálculo recebe/devolve `float` (ou `BcMath`), com
  arredondamento à escala da coluna (4 casas — D-712) num **helper único**. Documentar
  onde converte string→float (`D-MVC-2`).
- Comparações de quantidade a zero (encerramento RN-022) seguem arredondadas a 4 casas
  (D-712).

### 6.4 Transações e lock pessimista (RN-024 / D-713)

Sem repositório, a transação fica **no Service**:
```php
DB::transaction(function () use ($id, $dados) {
    $posicao = Posicao::whereKey($id)->lockForUpdate()->firstOrFail(); // D-713
    // valida RN-022 sobre saldo travado; insere Movimentacao (imutável);
    // recomputa via $posicao->replay(); atualiza posicao.quantidade/status (RN-024).
});
```
- `criarFuturo` insere mãe + filha + `ABERTURA` na **mesma transação** (RN-020); o
  índice parcial `uq_mov_abertura` garante "exatamente uma ABERTURA".

### 6.5 Idempotência e proveniência do MtM (RN-013 / D-803)

`ServicoMotor` faz `MtmDiario::updateOrCreate(['posicao_id'=>…,'data_calculo'=>…],
[valores])`. O refinamento D-803 (não tocar `execucao_id`/`processado_em` se os valores
financeiros não mudaram) usa `isDirty(['mtm_valor','variacao_dia','pl_acumulado',
'preco_mercado'])` antes do `save()`.

### 6.6 Testes — perda dos *fakes* de contrato (impacto de DM-2)

A suíte unitária original usava **fakes dos contratos** (`tests/Doubles/`, D-402) para
rodar Services **sem banco**. Sem contratos (DM-2):
- **Cálculo** (motor, preço médio, P&L, multi-perna): testável **sem banco** via os
  traits puros (§6.2) ou instanciando Models com `setRawAttributes`/`make()` (sem
  `save`) — mantém a meta de cobertura de domínio **≥ 90%** (§8.4).
- **Services** (orquestração/RN com persistência): passam a depender de banco →
  `RefreshDatabase` + **SQLite in-memory** (rápidos) e **PostgreSQL** para `NUMERIC`/
  índice parcial/JSONB (Parte 11). Parte da cobertura "aplicação ≥ 70%" migra para
  feature.
- **Decisão a registrar** (`D-MVC-3`): estratégia de teste de Service (factory+SQLite
  vs. Postgres) por caso. O `MotorMtm` mantém cobertura dedicada.

---

## 7. Interfaces que sobrevivem (e por quê)

DM-2 remove os **contratos de persistência**, mas o pedido cita "interface". Mantêm-se
apenas as interfaces que representam **pontos de extensão reais** (não persistência):

- **`FontePrecos`** (`app/Services/Contratos/FontePrecos.php`) — porta de **ingestão
  de preços**. Hoje: `ImportadorPrecosCsv`. Fase 4 (§10/§11): Bloomberg/Refinitiv/B3
  implementam a mesma interface.
- **(Opcional) `ExportadorRelatorio`** — se a exportação CSV (§5.3) ganhar formatos.
- Demais contratos de repositório: **descartados** (Services usam Eloquent direto).

> "Interface" no MVC alvo = **portas de integração externa** (Strategy), não
> abstração de persistência.

---

## 8. Impacto em documentação e decisões (a fazer na implementação — não agora)

Quando a migração for executada, atualizar (fora do escopo *deste* arquivo):
- **`specs/ARCHITECTURE.md`** — §3 (árvore), §4 (padrões), §11 (dependências) e ADRs:
  - **ADR-003** ("domínio PHP puro separado do Eloquent") → **revogado**, substituído
    por uma ADR nova "**fat model Eloquent + fábrica polimórfica `newFromBuilder`**"
    (Contexto/Decisão/Consequências, incl. o trade-off de testabilidade e a opção de
    reverter para B — §1.2).
  - Nova ADR "Services sobre Eloquent direto (sem Repository/Contratos)".
  - Nova ADR "Facades como fachada dos Services".
  - ADR-001/002/004/005/006 permanecem.
- **`decisions.md`** — abrir seção "Migração MVC" com `D-MVC-1..3` (§11) e referenciar
  D-7xx/D-8xx/D-9xx que mudam de hospedeiro.
- **`CLAUDE.md`** — ajustar as menções a `app/Aplicacao/Posicoes/` e
  `app/Dominio/Motor/MotorMtm` para os novos caminhos (`app/Services/...`,
  `app/Models/...`).
- Recortes `spec_parte_6/7/9`, `specs_parte_8` — nota de "estrutura MVC" apontando
  para este documento; **RNs e critérios de aceite não mudam**.

---

## 9. Plano de execução por fases (quando for implementar)

> Sequência sugerida; cada fase fica verde antes da próxima. Nada aqui é executado por
> este documento.

1. **Fase A — Esqueleto MVC.** Criar `app/Models` (+ `Concerns/`), `app/Services`
   (+ `Dados/`), `app/Facades`, `app/Support/Csv`, `app/Exceptions`. Registrar
   *singletons* + Facades no `AppServiceProvider`. (P-4: migrations/esquema intactos.)
2. **Fase B — Models (M) + cálculo.** Materializar `Posicao` (+ `newFromBuilder`,
   §6.1) e subclasses com `calcularMtm/plRealizado/replay` (traits puros §6.2);
   `MtmDiario`, `MotorExecucao`, `Produto`, `PrecoReferencia`, `Movimentacao`, `Perna`.
   Testes de cálculo **sem banco** (meta ≥ 90%).
3. **Fase C — Services.** `ServicoProdutos/Precos/Posicoes/Movimentacoes/Motor/
   Relatorios` consumindo Eloquent direto; transações + `lockForUpdate` (§6.4);
   idempotência (§6.5); `FontePrecos` + `ImportadorPrecosCsv` (§7).
4. **Fase D — Apresentação.** Controllers/Api + Requests + Resources reapontados aos
   Services/Facades; Livewire idem; `ProcessarMotorCommand` + agendamento.
5. **Fase E — Testes de feature/integração** (Parte 11): `RefreshDatabase` (SQLite/
   Postgres) cobrindo o que saiu dos *fakes* (§6.6); fluxo ponta a ponta
   produto→preço→posição→motor→relatório.
6. **Fase F — Docs/ADRs** (§8) e RBAC/Sanctum (Parte 10) sobre a nova estrutura.

---

## 10. Riscos e trade-offs (da escolha A — fat model)

| Risco / trade-off | Mitigação |
|---|---|
| **Acoplamento domínio↔ORM** — regra de negócio depende do Eloquent (perde teste sem banco e a pureza pedida) | Traits puros §6.2; `make()`/`setRawAttributes` sem `save`; aritmética livre de query. Reversão A→B localizada (§1.2). |
| **Polimorfismo via `newFromBuilder`** — menos explícito que o `match` do repositório | Teste dedicado de hidratação por tipo; `D-MVC-1` documenta a escolha (b). |
| **Decimais string⇄float** — cast `decimal:` retorna string; cálculo financeiro sensível | Helper único de conversão/arredondamento a 4 casas (D-712); `D-MVC-2`. |
| **Fat model "obeso"** — Models acumulam cálculo + persistência + relações | Traits/Concerns para cálculo; Services concentram orquestração/transação. |
| **Concorrência** — garantir `FOR UPDATE` no lugar certo (RN-022/024) | Transação no Service com `lockForUpdate` (§6.4); teste de redução concorrente. |
| **Cobertura de testes** — some a estratégia de *fakes* de contrato | Migrar parte da cobertura para feature com `RefreshDatabase`; `D-MVC-3`. |
| **Extensibilidade de ingestão** — risco de perder a porta `FontePrecos` ao remover contratos | Preservar `FontePrecos` como interface (§7). |

---

## 11. Itens em aberto (decidir na implementação)

- **D-MVC-1** — STI via JOIN/view vs. subclasse com relação-filha no `newFromBuilder`
  (§6.1). *Recomendado:* relação-filha.
- **D-MVC-2** — Borda de conversão decimal string⇄float e biblioteca (float vs.
  BcMath) (§6.3).
- **D-MVC-3** — Estratégia de teste de Service pós-remoção dos *fakes*: SQLite
  in-memory vs. PostgreSQL por caso (§6.6).
- **Facade de `Movimentacoes`** — criar ou expor só via `Posicoes`/DI? (§5.5).
- **Namespace de negócio** — `app/Services/` plano vs. por módulo
  (`app/Services/Posicoes/...`). *Sugerido:* plano com sufixo de módulo no nome.

> A decisão A×B (antes `D-MVC-0`) está **resolvida**: **Alternativa A — fat model**
> (§1, §1.2).

---

**Fim do documento.** Nenhuma alteração de código ou de specs existentes foi feita;
este arquivo é exclusivamente o **plano** da migração DDD→MVC, comprometido com a
**Alternativa A (fat model)**; a Alternativa B fica registrada como descartada (§1.2).
