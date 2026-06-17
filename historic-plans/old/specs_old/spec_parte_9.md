# Parte 9 — Módulo Relatórios

Requisitos executáveis da **Parte 9** do `next_steps.md`. A fonte da verdade continua
sendo `specs/requisitos.md` (v1.4); este documento é o **recorte da Parte 9** —
referencia as §/RN de origem para rastreabilidade.

> **Decisões de escopo:**
> 1. A Parte 9 cobre o **Módulo de relatórios** (§1.3 item 4, §2.1): as quatro visões
>    consolidadas para a mesa de risco — **posição aberta** (RN-016), **P&L
>    diário/acumulado** (RN-017/018), **exposição líquida** (RN-019) e **histórico de
>    MtM** por posição (§5.2.5) — com `ServicoRelatorios` + API REST `/api/v1/relatorios`
>    + telas Livewire.
> 2. Os relatórios são **somente leitura** sobre dados já produzidos pelas Partes 6–8
>    (`mtm_diario`, `posicao`, `produto`). A Parte 9 **não recalcula** MtM nem replica
>    a lógica do motor; **consome** o que já está persistido.
> 3. Corte **vertical, ponta a ponta** (recomendado): serviço + API + telas Livewire
>    2 (Dashboard), 8, 9 e 10. Se o corte for por camada, a UI pode ser adiada
>    (registrar em `decisions.md`).
> 4. **Autenticação/RBAC adiada para a Parte 10** — endpoints abertos nesta parte
>    (`disparado_por`/usuário entram quando o Sanctum ligar). RBAC: `GESTOR` é
>    *read-only* de relatórios (§9.2) — fica para a Parte 10.
> 5. **Exportação `formato=`:** JSON (default) + **CSV** nesta parte; **PDF adiado**
>    (precisa de lib externa) — ver §5.3 e D-905.

---

## 1. Escopo

**Inclui**
- `ServicoRelatorios` (`app/Aplicacao/Relatorios/`) com as quatro consultas do §5.2.5
  (RN-016..019) + a série de evolução do P&L (gráfico da tela 9).
- **Porta de leitura** `RepositorioRelatorios` (consulta agregada) + implementação
  Eloquent (SQL com agregação/`DISTINCT ON`, §4 deste doc).
- **API REST** `/api/v1/relatorios/*` (§5.2.5) com envelope de erro (§5.1), `data`
  validada e `formato=json|csv` (PDF adiado).
- **Telas Livewire** (§6.1): **2 — Dashboard**, **8 — Posição aberta**, **9 — P&L**
  (gráfico + tabela) e **10 — Exposição líquida**.

**Não inclui (fora da Parte 9)**
- Autenticação/Sanctum e RBAC/Policies (§9.2) → **Parte 10** (matriz perfil × operação;
  `GESTOR` vê relatórios mas não opera).
- Testes de integração contra PostgreSQL com `RefreshDatabase` → **Parte 11**
  (os casos são definidos aqui; a execução é da Parte 11 — ver D-507/D-609/D-809).
- **PDF** (`formato=pdf`) → adiado (D-905); JSON e CSV entram agora.
- Recalcular MtM / mexer no motor (Parte 8) ou no domínio (Partes 3/4).
- Decomposição preço × câmbio da `variacao_dia` (Fase 2, §3.2.8) e métricas de risco
  avançadas (VaR/gregas — §11 "fora de escopo").

**Dependências:**
- **Parte 8** (Motor MtM) — concluída: popula `mtm_diario` (`variacao_dia`,
  `pl_acumulado`, `execucao_id`) e `motor_execucao` (para o status no dashboard).
- **Parte 7** (Posições) — concluída: hidratação do domínio (`buscarAbertas()`,
  §4.5) usada para o **preço médio do FUTURO** (derivado por replay, RN-021) e o
  `sinal` da exposição.
- **Parte 5** (Infra) — concluída: Models/Repos, binds no provider.
- **Parte 6** (Produtos) — concluída: `produto.nome`/`unidade` para rotular a
  exposição (RN-019).

**Regras de negócio cobertas:** RN-016, RN-017, RN-018, RN-019
(contexto correlato: RN-021/023 — origem de preço médio e `pl_acumulado`; RN-024 —
`posicao.quantidade` em sincronia).

---

## 2. Estado já existente (reutilizar — não recriar)

| Camada | Artefato | Observação |
|---|---|---|
| Infra | `app/Infraestrutura/Models/MtmDiarioModel.php` | `mtm_diario` (§3.2.8): `mtm_valor`, `variacao_dia`, `pl_acumulado`, `preco_mercado`, `data_calculo`, `execucao_id`; relações `posicao()`, `precoReferencia()`, `execucao()`. Fonte primária dos relatórios. |
| Infra | Índices `idx_mtm_data`, `idx_mtm_posicao_data` (§3.3) | Suportam o filtro por `data_calculo` e o "último ≤ data" por posição. |
| Infra | `app/Infraestrutura/Models/{PosicaoModel,ProdutoModel}.php` | `posicao.quantidade`/`lado`/`status`/`produto_id`; `produto.nome`/`unidade`. Para JOIN/GROUP BY da exposição (RN-019). |
| Contrato | `app/Aplicacao/Contratos/RepositorioPosicoes.php` | `buscarAbertas(): Posicao[]` (domínio hidratado, §4.5) — usado para **preço médio**/`quantidadeAtual()` do FUTURO e `sinal()` (§4.2). `detalhar(int)` devolve `PosicaoDetalhe` + `EstadoMovimentacao` (preço médio já pronto). |
| Contrato | `app/Aplicacao/Contratos/RepositorioExecucoes.php` | `listar()`/`buscarPorId()` → `ResumoExecucao`. Dashboard precisa da **última** execução (§3.5: reusar `listar(1)` ou somar `ultima()`). |
| Domínio | `app/Dominio/Posicoes/Posicao.php` (+ `Futuro`) | `sinal()` (+1 COMPRADO / −1 VENDIDO, §4.2), `Futuro::precoMedio()`/`quantidadeAtual()`/`plRealizado()` (RN-021/023). **Canônico** do `sinal` da exposição. |
| Aplicação | `app/Aplicacao/Posicoes/{PosicaoResumo,PosicaoDetalhe,EstadoMovimentacao}.php` | Padrão de **read model/DTO** (D-704) a espelhar nos VOs de relatório. `EstadoMovimentacao.precoMedio` já resolve o preço médio do FUTURO. |
| Aplicação | `app/Aplicacao/Motor/ResumoExecucao.php` | VO desacoplado do Model (D-801) — molde dos VOs desta parte. |
| HTTP | `routes/api.php` (grupo `/api/v1`, D-608) + `bootstrap/app.php` (handler central, D-605) | Adicionar o subgrupo `relatorios/*`. Envelope `{ "erro", "mensagem" }` (§5.1). |
| HTTP | `app/Http/Resources/*`, `app/Http/Requests/*` | Padrão de serialização (**decimais sem aspas**, datas ISO) e validação a reusar. |
| UI | `resources/views/components/layouts/app.blade.php` | Layout base + `<nav>`. Adicionar links **Dashboard** e **Relatórios**; `/` passa a apontar para o Dashboard (hoje redireciona a `produtos.index`). |
| UI | `app/Livewire/Motor/ExecutarMotor.php` + `mock_telas/` | Estilo Livewire e referência visual (telas de relatório nos `screens*.jsx`). |
| Segurança | `app/Infraestrutura/Csv/` (importador, Parte 6) + `specs/SECURITY.md` §C10 | Neutralização **anti formula-injection (CWE-1236)** a reaplicar na **exportação** CSV (§5.3). |
| Padrões | Exceções de aplicação → envelope (D-605); `tests/Doubles/` (D-402); read models (D-704). | Reusar. |

---

## 3. Camada de aplicação — `app/Aplicacao/Relatorios/`

### 3.1 `ServicoRelatorios`
Orquestra as quatro visões + a série do gráfico. **Não** recalcula MtM: lê de
`mtm_diario`/`posicao`/`produto` via `RepositorioRelatorios` (§4) e, para o preço médio
do FUTURO, reusa o domínio hidratado (`RepositorioPosicoes`).

- `posicaoAberta(\DateTimeImmutable $data): array<LinhaPosicaoAberta>` — **RN-016**:
  todas as posições **`ABERTA`** com o **último MtM disponível** (`data_calculo <= data`,
  §3.3 / D-903). Para `instrumento = FUTURO`, inclui o **preço médio** (replay RN-021,
  reusando `buscarAbertas()`/domínio — §3.4). NDF/OPCAO/OTC: `precoMedio = null`.
- `plDiario(\DateTimeImmutable $data): ResumoPL` — **RN-017 + RN-018**:
  - `totalVariacaoDia` = Σ `variacao_dia` **na data exata** (`data_calculo = data`) — RN-017;
  - `totalPlAcumulado` = Σ `pl_acumulado` das posições **abertas**, pelo último MtM
    `<= data` — RN-018 (inclui o realizado das reduções, RN-023);
  - `linhas: array<LinhaPL>` — por posição (para a tabela da tela 9).
- `exposicaoLiquida(\DateTimeImmutable $data): array<LinhaExposicao>` — **RN-019**:
  agrupa por **produto**, com `comprado`, `vendido` e `liquida` = Σ `quantidade × sinal`
  (§3.4). Considera posições **`ABERTA`** com MtM `<= data` (mesma janela do RN-016).
- `historicoMtm(int $posicaoId): array<PontoHistoricoMtm>` — série temporal de
  `mtm_diario` da posição (asc por `data_calculo`); 404 se a posição não existir.
- `evolucaoPl(\DateTimeImmutable $inicio, \DateTimeImmutable $fim): array<PontoEvolucaoPl>`
  — série **da carteira** (Σ por `data_calculo`) para o **gráfico** da tela 9. Método de
  serviço consumido pela UI; **não** é endpoint REST (o §5.2.5 fixa 4 rotas) — expor via
  REST seria extensão (flag, estilo D-719).
- (Dashboard, §6) `resumoDashboard(\DateTimeImmutable $data): ResumoDashboard` —
  P&L do dia/acumulado (de `plDiario`), nº de posições abertas e a **última execução**
  do motor (via `RepositorioExecucoes`, §3.5).

### 3.2 Read models / VOs (`app/Aplicacao/Relatorios/`) — padrão D-704
Imutáveis, desacoplam API/UI do Eloquent. Decimais como `float` (a serialização cuida
da escala). Sugestão de shape:

- `LinhaPosicaoAberta`: `posicaoId, produtoId, produtoNome, instrumento, lado,
  quantidade, dataVencimento, precoMercado, mtmValor, plAcumulado, dataUltimoMtm,
  ?precoMedio (FUTURO), status`.
- `ResumoPL`: `data, totalVariacaoDia, totalPlAcumulado, linhas[]`.
  - `LinhaPL`: `posicaoId, produtoNome, instrumento, variacaoDia, plAcumulado`.
- `LinhaExposicao`: `produtoId, produtoNome, unidade, comprado, vendido, liquida,
  numPosicoes`.
- `PontoHistoricoMtm`: `dataCalculo, precoMercado, mtmValor, variacaoDia, plAcumulado`.
- `PontoEvolucaoPl`: `dataCalculo, plAcumulado, variacaoDia`.
- `ResumoDashboard`: `data, plDia, plAcumulado, posicoesAbertas, ?ultimaExecucao
  (ResumoExecucao)`.

### 3.3 Semântica de data — "último disponível" vs. "data exata" (D-903)
- **RN-017** (`pl-diario`, parcela do dia) usa **`data_calculo = data`** — variação do
  pregão. Em dia sem marcação para a posição (sem preço/feriado), ela simplesmente não
  entra na soma do dia.
- **RN-016 / RN-018 / RN-019** (snapshot da posição) usam o **último MtM `<= data`** —
  idioma PostgreSQL `DISTINCT ON (posicao_id) … ORDER BY posicao_id, data_calculo DESC`
  (§4). Robusto a fins de semana/feriados sem cadastro.
- **"posição aberta na data" (D-909 — flag MVP):** não há histórico de `status`; "aberta
  na data" é aproximado por **`status` atual = `ABERTA`** ∩ existência de MtM `<= data`.
  Um relatório retroativo pode, portanto, divergir do estado real daquela data (posição
  encerrada depois some; aberta depois não aparece). Aceito no MVP (relatórios são
  snapshot corrente/prospectivo); registrar como simplificação, não bloqueante.

### 3.4 Preço médio e `sinal` — reuso do domínio (não reimplementar)
- **Preço médio do FUTURO (RN-021):** `posicao_futuro` guarda só `preco_entrada` (o da
  `ABERTURA`); o preço médio ponderado é **derivado** por replay das movimentações.
  **Reusar** o domínio hidratado: `RepositorioPosicoes::buscarAbertas()` já faz eager
  loading (§4.5) e devolve `Futuro` com `precoMedio()`/`quantidadeAtual()`. O serviço
  cruza, em memória, por `posicao_id`, as posições abertas (domínio) com o "último MtM
  `<= data`" (uma query agregada). Evita N+1; ver nota de custo em §4.
- **`sinal` da exposição (RN-019):** o canônico é `Posicao::sinal()` (§4.2). Na agregação
  SQL (§4) ele é **espelhado** por `CASE WHEN lado='COMPRADO' THEN quantidade ELSE
  -quantidade END`. Manter as duas formas coerentes (flag de consistência, D-904).
- **Mistura de unidades na exposição (D-908 — flag MVP):** RN-019 soma
  `posicao.quantidade × sinal` literalmente. No MVP isso mistura **nocional** (NDF),
  **contratos** (FUTURO) e **`quantidade = 1`** (OPCAO, RN-004e) sob o mesmo produto.
  Aceito como a spec define; decomposição/normalização por instrumento fica para fase
  futura — não bloqueante.

### 3.5 Dashboard — última execução do motor
`resumoDashboard` precisa do **status da última execução** (§6.1.2). Reusar
`RepositorioExecucoes::listar(1)` (primeiro item) **ou** somar um
`ultima(): ?ResumoExecucao` ao contrato (mais explícito) — escolher e registrar (D-906).
Tolerar `finalizado_em = NULL` (execução em andamento/"zumbi", §3.3 da Parte 8).

---

## 4. Infraestrutura — `RepositorioRelatorios` (`app/Infraestrutura/Repositorios/`)

**Porta de leitura dedicada** (não inflar `RepositorioMtm`): as consultas cruzam
`mtm_diario × posicao × produto` e são *reporting queries* agregadas. Espelha a decisão
de criar `RepositorioExecucoes` na Parte 8 (D-805). Alternativa considerada: estender
`RepositorioMtm` — descartada por misturar escrita do motor com leitura analítica
(registrar em D-901).

Contrato em `app/Aplicacao/Contratos/RepositorioRelatorios.php`, impl. Eloquent +
**bind** no `RepositorioServiceProvider` (`$bindings`, D-506). Métodos sugeridos:

- `ultimoMtmPorPosicaoAteData(\DateTimeImmutable $data): array<int, …>` — "greatest-n-per-
  group" via **`DISTINCT ON (posicao_id)`** (PostgreSQL, ADR-005), `data_calculo <= :data`,
  `ORDER BY posicao_id, data_calculo DESC`. Chaveado por `posicao_id`.
- `somaVariacaoDia(\DateTimeImmutable $data): float` — `WHERE data_calculo = :data` (RN-017).
- `exposicaoPorProduto(\DateTimeImmutable $data): array<LinhaExposicao>` — `JOIN posicao/
  produto`, `WHERE posicao.status='ABERTA'` + MtM `<= data`, `GROUP BY produto`,
  `SUM(CASE … )` para comprado/vendido/líquida (RN-019).
- `historicoMtm(int $posicaoId): array<PontoHistoricoMtm>` — série asc por `data_calculo`.
- `evolucaoCarteira(\DateTimeImmutable $inicio, \DateTimeImmutable $fim)` — `GROUP BY
  data_calculo`, `SUM(pl_acumulado)`/`SUM(variacao_dia)` (gráfico tela 9).

**Regras de implementação:**
- **Sempre com bindings** (sem interpolar SQL) — §4.5/SECURITY. `DISTINCT ON`/`CASE`
  exigem SQL parcialmente bruto: usar `query bindings`, nunca concatenar a `data`.
- **Performance (§9.1):** os totais e a exposição saem em **1 query agregada cada** (sem
  N+1). O **preço médio do FUTURO** vem do `buscarAbertas()` (eager loading, §4.5); para
  a meta de 1.000 posições é adequado. Se o volume de FUTUROs com longas trilhas de
  movimentação crescer, avaliar materializar preço médio — evolução futura, não agora.
- Decimais: ler como `string`/`decimal` e converter na fronteira; **nunca `float`** no
  caminho do banco (cast `decimal:` dos Models já cobre).

---

## 5. API REST — `app/Http/` (§5.2.5)

Subgrupo sob `/api/v1/relatorios` em **`routes/api.php`** (grupo já existente, D-608),
servido por um `RelatorioController` (`app/Http/Controllers/Api/`).

### 5.1 Endpoints
```
GET /api/v1/relatorios/posicao-aberta?data=YYYY-MM-DD[&formato=json|csv]
GET /api/v1/relatorios/pl-diario?data=YYYY-MM-DD[&formato=json|csv]
GET /api/v1/relatorios/exposicao-liquida?data=YYYY-MM-DD[&formato=json|csv]
GET /api/v1/relatorios/historico-mtm?posicao_id=X[&formato=json|csv]
```

**Exemplo — `GET /relatorios/exposicao-liquida?data=2026-05-23` (200):**
```json
{
  "data": "2026-05-23",
  "itens": [
    { "produto_id": 1, "produto": "Soja CME", "unidade": "bushel",
      "comprado": 150.0, "vendido": -50.0, "liquida": 100.0, "num_posicoes": 3 }
  ]
}
```

### 5.2 Validação e serialização
- **Form Request** (`app/Http/Requests/RelatorioRequest.php` ou dois): `data`
  **obrigatória**, `date` ISO 8601 (posição-aberta/pl-diario/exposição); `posicao_id`
  obrigatório `integer` (historico-mtm); `formato` `in:json,csv` (PDF rejeitado por ora,
  ver D-905). Inválido → **422** com envelope (§5.1).
- **API Resources** (`app/Http/Resources/`): serializam os VOs — **decimais sem aspas**,
  datas ISO, `null` no `preco_medio` de não-FUTURO. Coleções com `data`/`itens`.
- **Envelope de erro** `{ "erro", "mensagem" }` (§5.1) via handler central (D-605):
  `400/404/422` (e `401/403` quando a Parte 10 ligar o RBAC). `historico-mtm` de posição
  inexistente → **404**.
- Relatórios **sem paginação** por padrão (snapshot do dia; volume da carteira, §9.1) —
  diferente da listagem operacional (50/pág). Se necessário, paginar a posição-aberta no
  futuro (flag).

### 5.3 Exportação `formato=` (D-905)
- `json` (**default**) → API Resource acima.
- `csv` → resposta `text/csv` com `Content-Disposition: attachment`. Colunas = as do VO.
  **Segurança (CWE-1236 / SECURITY §C10):** **neutralizar fórmula** em células texto
  (ex.: `produto` iniciado por `= + - @ \t \r`) prefixando `'` — a exportação também é
  vetor de *CSV injection* ao abrir no Excel. Reaproveitar o sanitizador da Parte 6.
- `pdf` → **adiado**. Exige lib (ex.: `barryvdh/laravel-dompdf`); manter Parte 9 enxuta.
  Por ora `formato=pdf` retorna **422** ("formato não suportado") — registrar D-905;
  quando entrar, renderiza Blade → PDF reusando os mesmos VOs.

---

## 6. UI — Blade + Livewire (§6) — `app/Livewire/Relatorios/` e `Dashboard/`
*(corte vertical recomendado)*

A UI injeta `ServicoRelatorios` **diretamente** — **sem** *self-call* HTTP à própria API
(simétrico a D-808/D-907). A API REST §5.2.5 existe em paralelo para consumidores
externos, compartilhando o mesmo serviço.

- **Tela 2 — Dashboard (§6.1.2):** cartões com **P&L do dia** e **P&L acumulado**, **nº de
  posições abertas** e **status da última execução do motor** (data, sucessos/falhas,
  em andamento). `/` passa a renderizar o Dashboard (hoje redireciona a `produtos.index`).
- **Tela 8 — Relatório de posição aberta (§6.1.8):** tabela consolidada com último MtM;
  coluna **preço médio** preenchida só para FUTURO. Date picker (default: hoje). Botão
  **Exportar CSV**.
- **Tela 9 — Relatório de P&L (§6.1.9):** **gráfico de evolução** (série
  `evolucaoPl`/`ServicoRelatorios`) + **tabela por posição** (`plDiario`). Gráfico: lib
  JS leve (ex.: Chart.js via Vite) **ou** SVG/sparkline server-side — escolher e
  registrar (D-907); manter pragmático. *Drill-down* por posição usa `historicoMtm`.
- **Tela 10 — Relatório de exposição líquida (§6.1.10):** agrupado por produto,
  **comprado vs. vendido** (e líquida), com destaque visual de sinal.
- Rotas Livewire em `routes/web.php` (`dashboard.index`, `relatorios.*`); links no
  `<nav>` do layout. Estética: `mock_telas/` (telas de relatório em `screens*.jsx`).
- Se o corte for **por camada**, esta seção pode ser adiada — registrar em `decisions.md`.

---

## 7. Testes (§8)

- **Unidade (Pest, sem banco):** `ServicoRelatorios` com *fakes* dos contratos
  (`RepositorioRelatorios`, `RepositorioPosicoes`, `RepositorioExecucoes` —
  `tests/Doubles/`, D-402):
  - **RN-016** — posição-aberta lista só `ABERTA` com o **último MtM `<= data`**; FUTURO
    traz preço médio (replay), não-FUTURO traz `precoMedio = null`.
  - **RN-017** — `totalVariacaoDia` soma **só** `data_calculo = data` (posição sem
    marcação no dia não entra).
  - **RN-018** — `totalPlAcumulado` soma `pl_acumulado` das abertas pelo último `<= data`
    (inclui realizado das reduções — montar *fake* com FUTURO reduzido).
  - **RN-019** — exposição agrupa por produto; comprado/vendido/líquida com **sinal**
    correto (incl. posição vendida → negativa); coerência com `Posicao::sinal()`.
  - **historico-mtm** — série asc por data; posição inexistente → erro de não encontrado.
  - **Exportação CSV** — colunas corretas e **neutralização anti-fórmula** (produto
    `=CMD()` vira `'=CMD()`).
  - **D-909** — relatório retroativo usa `status` atual + MtM `<= data` (documentar o
    limite no teste).
- **Feature/Integração (definir aqui, executar na Parte 11 — D-507/D-609/D-809):** fluxo
  ponta a ponta (produto → preço → posição → motor → **relatórios**) contra PostgreSQL
  com `RefreshDatabase`; valida o **`DISTINCT ON`** (greatest-n-per-group), a agregação da
  exposição (`GROUP BY`/`SUM CASE`), o envelope/validação `422/404`, e a exportação CSV
  (headers `text/csv`, sanitização).

---

## 8. Decisões a registrar em `decisions.md` (seção "Parte 9")

- **D-901** — Porta de leitura dedicada `RepositorioRelatorios` (consulta agregada
  `mtm_diario × posicao × produto`), em vez de inflar `RepositorioMtm` (simetria com
  `RepositorioExecucoes`/D-805). `ServicoRelatorios` só lê — não recalcula MtM.
- **D-902** — Read models/VOs por relatório (`LinhaPosicaoAberta`, `ResumoPL`/`LinhaPL`,
  `LinhaExposicao`, `PontoHistoricoMtm`, `PontoEvolucaoPl`, `ResumoDashboard`) — padrão
  D-704.
- **D-903** — Semântica de data: RN-017 usa `data_calculo = data` (parcela do dia);
  RN-016/018/019 usam o **último MtM `<= data`** via `DISTINCT ON` (PostgreSQL).
- **D-904** — `sinal` da exposição espelhado em SQL (`CASE lado`) mantendo coerência com
  `Posicao::sinal()` (§4.2) — flag de consistência.
- **D-905** — `formato`: JSON + CSV na Parte 9 (CSV com neutralização anti-fórmula,
  CWE-1236); **PDF adiado** (`422` por ora) por exigir lib externa.
- **D-906** — Dashboard (tela 2) no corte vertical; `/` passa a renderizar o Dashboard;
  obter a **última execução** via `RepositorioExecucoes::listar(1)` ou novo `ultima()`.
- **D-907** — Livewire injeta `ServicoRelatorios` direto (sem *self-call* HTTP, simétrico
  a D-808); escolha do gráfico da tela 9 (Chart.js via Vite vs. SVG server-side).
- **Flags MVP (não bloqueantes):** **D-908** — exposição mistura nocional/contratos/qtd=1
  (RN-019 literal); **D-909** — "posição aberta na data" aproximada por `status` atual +
  MtM `<= data` (sem histórico de status). Decomposição/normalização → evolução futura.

---

## 9. Critérios de aceite

- [ ] `ServicoRelatorios` entrega as 4 visões §5.2.5 (RN-016..019) lendo `mtm_diario`/
      `posicao`/`produto` — **sem** recalcular MtM.
- [ ] **RN-016** — posição-aberta = `ABERTA` + último MtM `<= data`; preço médio só p/
      FUTURO (replay RN-021), verificado por teste.
- [ ] **RN-017/018** — P&L do dia (`variacao_dia` na data) e acumulado (`pl_acumulado`,
      incluindo realizado das reduções) corretos.
- [ ] **RN-019** — exposição agrupada por produto com comprado/vendido/líquida e `sinal`
      coerente com o domínio.
- [ ] `historico-mtm` devolve a série da posição; inexistente → **404**.
- [ ] Endpoints §5.2.5 sob `/api/v1` com `data` validada (422), envelope §5.1 e
      `formato=json|csv` (CSV com anti-fórmula; `pdf` → 422 documentado).
- [ ] Telas Livewire 2/8/9/10 funcionais (Dashboard, posição aberta, P&L com gráfico,
      exposição); `/` → Dashboard; links no `<nav>`.
- [ ] Decisões D-901..D-909 registradas em `decisions.md`; bind de
      `RepositorioRelatorios` ativo no provider.
- [ ] Suíte unitária verde; casos de integração escritos (execução na Parte 11).
