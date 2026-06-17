# Parte 6 — Módulo Preços (e Produtos)

Requisitos executáveis da **Parte 6** do `next_steps.md`. A fonte da verdade continua
sendo `specs/requisitos.md` (v1.4); este documento é o **recorte da Parte 6** —
referencia as §/RN de origem para rastreabilidade.

> **Decisões de escopo (confirmadas com o usuário):**
> 1. A Parte 6 cobre **Produtos (§5.2.1) + Preços (§5.2.2)** — o "Módulo de preços"
>    completo do §1.3 (produto é pré-requisito de preço via FK `produto_id`).
> 2. Corte **vertical, ponta a ponta**: `ServicoPrecos`/`ServicoProdutos` + API REST +
>    import CSV + **telas Livewire** (tela 3 Cadastro de produtos, tela 4 Lançamento
>    de preços com upload CSV).
> 3. **Autenticação/RBAC adiada para a Parte 10** — endpoints abertos nesta parte.

---

## 1. Escopo

**Inclui**
- CRUD de **produtos** (§3.2.1, §5.2.1) — cadastro mestre de commodities.
- Lançamento, listagem, remoção e **upload CSV em lote** de **preços de referência**
  (§3.2.2, §5.2.2).
- Serviços de aplicação (`ServicoProdutos`, `ServicoPrecos`), API REST `/api/v1`,
  importador CSV e telas Livewire 3 e 4 (§6.1).

**Não inclui (fora da Parte 6)**
- Autenticação/Sanctum e RBAC/Policies (§9.2) → **Parte 10**.
- Testes de integração contra PostgreSQL com `RefreshDatabase` → **Parte 11**
  (os casos são definidos aqui; a execução é da Parte 11 — ver D-507).
- Fontes externas de preço (Bloomberg/Refinitiv/B3) → Fase 4 (o contrato
  `FontePrecos` já prevê a extensão).
- OpenAPI/Scribe → opcional, pode ficar para a Parte 6 §6 do `next_steps`.

**Dependências:** Parte 5 (Infraestrutura) — concluída.

**Regras de negócio cobertas:** RN-007, RN-008, RN-009, RN-010, RN-010a
(contexto correlato: RN-006, RN-012, RN-015).

---

## 2. Estado já existente (reutilizar — não recriar)

| Camada | Artefato | Observação |
|---|---|---|
| Domínio | `app/Dominio/Precos/PrecoReferencia.php` | VO pronto (`id, produtoId, dataPreco, precoFechamento, cambioBrl`). |
| Contrato | `app/Aplicacao/Contratos/RepositorioPrecos.php` | Hoje só `buscar()` (leitura p/ motor). **A estender** (§3). |
| Contrato | `app/Aplicacao/Contratos/FontePrecos.php` | `obterPrecosDoDia()` — porta de ingestão; será implementada pelo CSV. |
| Infra | `app/Infraestrutura/Repositorios/RepositorioPrecosEloquent.php` | Tradução Model→domínio; **ampliar** com escrita/consulta. |
| Infra | `app/Infraestrutura/Models/PrecoReferenciaModel.php` | `$fillable`/`casts decimal:` prontos; relação `produto()`. |
| Infra | `app/Infraestrutura/Models/ProdutoModel.php` | `$fillable`/`casts` prontos; relações `precos()`, `posicoes()`. |
| Bind | `app/Providers/RepositorioServiceProvider.php` | Binds dos contratos; **falta** `FontePrecos → ImportadorPrecosCsv` (D-506). |
| Padrões | `ResultadoProcessamento`/`falhasFormatadas()` (D-304); `tests/Doubles/` (D-402); `mock_telas/` (UI). | Referências de estilo. |

---

## 3. Camada de aplicação — `app/Aplicacao/`

### 3.1 `ServicoProdutos` (`app/Aplicacao/Produtos/`)
- `listar(filtros, paginacao)` — lista com paginação (§9.1, 50/pág).
- `detalhar(id)` — detalhe; 404 se não existir.
- `criar(dados)` — cria produto (§3.2.1). Valida `nome` **único** e campos
  obrigatórios (`nome, unidade, bolsa_ref, moeda_cotacao`).
- `atualizar(id, dados)` — atualiza; mantém unicidade de `nome`.
- `inativar(id)` — **soft delete** via `ativo = false` (não apaga; §5.2.1 "Inativa").

### 3.2 `ServicoPrecos` (`app/Aplicacao/Precos/`)
- `lancar(dados)` — cria **1** preço (§5.2.2 `POST /precos`). Valida:
  - **RN-007** — não pode haver dois preços para o mesmo `(produto_id, data_preco)`
    → **409 Conflict**.
  - **RN-008** — `preco_fechamento > 0` → **422**.
  - **RN-009** — `cambio_brl > 0` → **422**.
  - `produto_id` deve existir (404/422).
- `importarCsv(arquivo)` — **RN-010**: processa o lote; **linhas com erro não
  bloqueiam as válidas**; retorna relatório de aceitas/rejeitadas. Aplica RN-007/008/009
  por linha. Delega o parsing ao `ImportadorPrecosCsv` (§4).
- `listar(filtros)` — filtros `produto_id`, `data_inicio`, `data_fim`; paginação
  50/pág (§5.2.2, §9.1).
- `remover(id)` — **RN-010a**: se o preço estiver referenciado por
  `mtm_diario.preco_ref_id`, **rejeita com 409**; senão deleta.

### 3.3 Relatório de importação — VO `ResultadoImportacao`
Espelhar o padrão de `ResultadoProcessamento` (D-304):
- `aceitas: int` (ou lista das linhas persistidas),
- `rejeitadas: array<{linha:int, motivo:string}>`,
- contadores `total`, `totalAceitas`, `totalRejeitadas`,
- método de formatação para o JSON da API (shape `{linha, motivo}`).

### 3.4 Contratos — extensão (decisão a registrar em `decisions.md`)
O `RepositorioPrecos` atual é só leitura. A Parte 6 precisa de **escrita/consulta**.
**Recomendação:** estender o **mesmo** contrato (um repositório por agregado), com:
- `salvar(PrecoReferencia|dados): PrecoReferencia`
- `remover(int $id): void`
- `existePorProdutoData(int $produtoId, \DateTimeImmutable $data): bool` (RN-007)
- `estaReferenciadoEmMtm(int $id): bool` (RN-010a)
- `listar(filtros, paginacao)` (paginado)

Para produtos: avaliar `RepositorioProdutos` (contrato) **vs.** uso direto do
`ProdutoModel` no serviço. Registrar a escolha em `decisions.md` (D-6xx).
Ampliar `RepositorioPrecosEloquent` para implementar os novos métodos.

---

## 4. Infraestrutura — Importador CSV (`app/Infraestrutura/Csv/`)

`ImportadorPrecosCsv` — implementa `FontePrecos` e expõe o parsing do upload.

**Formato (§5.2.2):**
```csv
produto_id,data_preco,preco_fechamento,cambio_brl
1,2026-05-23,1450.50,5.12
2,2026-05-23,72.30,5.12
```

**Validação e segurança (SECURITY §C10 / RN-010):**
- `file|mimes:csv,txt`; **limite de tamanho** e **de nº de linhas** (mitiga DoS).
- **Anti formula-injection (CWE-1236):** sanitizar/rejeitar células iniciadas por
  `= + - @`.
- Validação por linha (RN-007/008/009); linha inválida vai para `rejeitadas` com o
  motivo, **sem abortar** o lote (RN-010).
- Acesso a dados sempre com **bindings** (sem interpolação de SQL).

**Bind:** registrar `FontePrecos → ImportadorPrecosCsv` no
`RepositorioServiceProvider` (fecha a pendência do D-506).

---

## 5. API REST — `app/Http/` (§5)

Rotas sob `/api/v1` em **`routes/api.php`** (criar o arquivo — hoje existem só
`web.php` e `console.php`; registrar o grupo de API no `bootstrap/app.php` se preciso).

### 5.1 Endpoints — Produtos (§5.2.1)
```
GET    /api/v1/produtos          Lista (paginado)
GET    /api/v1/produtos/{id}     Detalhe
POST   /api/v1/produtos          Cria
PUT    /api/v1/produtos/{id}     Atualiza
DELETE /api/v1/produtos/{id}     Inativa (ativo=false)
```

### 5.2 Endpoints — Preços (§5.2.2)
```
GET    /api/v1/precos?produto_id=X&data_inicio=Y&data_fim=Z   Lista
POST   /api/v1/precos                                          Cadastra (1 preço)
POST   /api/v1/precos/upload                                   Upload CSV em lote
DELETE /api/v1/precos/{id}                                     Remove (409 se em MtM)
```

### 5.3 Validação, serialização e erros
- **Form Requests** (`app/Http/Requests/`) validam entrada e RNs (007/008/009 +
  produto).
- **API Resources** (`app/Http/Resources/`) serializam a saída (§5.1): **decimais
  sem aspas**, datas ISO, etc.
- **Envelope de erro** `{ "erro": "código", "mensagem": "descrição" }` (§5.1), com
  status `400/404/409/422`, via **handler central** (`bootstrap/app.php` →
  `withExceptions`).
- **Exceções de aplicação** (definir e mapear): ex. `PrecoDuplicadoException` (RN-007)
  → 409, `PrecoReferenciadoException` (RN-010a) → 409. Alinhar com o padrão a reusar
  nas Partes 7–9.
- `POST /precos/upload` responde com o **relatório aceitas/rejeitadas** (RN-010).
- Paginação 50/pág (§9.1).

---

## 6. UI — Blade + Livewire (§6) — `app/Livewire/`

- **Tela 3 — Cadastro de produtos (§6.1.3):** CRUD com listagem em tabela
  (`app/Livewire/Produtos/`).
- **Tela 4 — Lançamento de preços (§6.1.4):** formulário de entrada manual **+ área
  de upload CSV** que exibe o relatório de aceitas/rejeitadas (`app/Livewire/Precos/`).
- Rotas Livewire em `routes/web.php`; views em `resources/views/`. Layout base e
  estética: usar `mock_telas/` como referência visual.

---

## 7. Testes (§8)

- **Unidade (Pest, sem banco):** `ServicoPrecos`/`ServicoProdutos` e importador —
  RN-007..010a — com *fakes* dos contratos (padrão `tests/Doubles/`, D-402).
  Casos: duplicidade (RN-007), preço/câmbio inválidos (RN-008/009), CSV com linhas
  boas e ruins (RN-010), remoção bloqueada por MtM (RN-010a), sanitização anti-fórmula.
- **Feature/Integração (definir aqui, executar na Parte 11 — D-507):** endpoints,
  upload multipart, 409 da RN-010a, paginação, envelope de erro; contra PostgreSQL com
  `RefreshDatabase`.

---

## 8. Decisões a registrar em `decisions.md` (seção "Parte 6")

- **D-6xx** — Extensão do contrato `RepositorioPrecos` (escrita/consulta) vs. novo
  contrato. Recomendação: estender o existente.
- **D-6xx** — Contrato/repositório de produtos vs. uso direto do Model.
- **D-6xx** — VO `ResultadoImportacao` (shape do relatório RN-010).
- **D-6xx** — Bind `FontePrecos → ImportadorPrecosCsv` (fecha D-506).
- **D-6xx** — Estratégia de exceções de aplicação → status HTTP (handler §5.1).

---

## 9. Critérios de aceite

- [ ] Endpoints §5.2.1 e §5.2.2 implementados sob `/api/v1`, com envelope de erro §5.1.
- [ ] RN-007 (409), RN-008/009 (422), RN-010 (relatório sem abortar), RN-010a (409)
      verificadas por teste.
- [ ] Upload CSV valida tipo/tamanho/linhas e sanitiza formula-injection.
- [ ] Telas Livewire 3 e 4 funcionais (CRUD produtos; lançar + upload preços).
- [ ] Decisões registradas em `decisions.md`; bind do CSV ativo.
- [ ] Suíte unitária verde; casos de integração escritos (execução na Parte 11).
