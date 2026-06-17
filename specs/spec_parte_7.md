# Parte 7 — Módulo Posições (e Movimentações)

Requisitos executáveis da **Parte 7** do `next_steps.md`. A fonte da verdade continua
sendo `specs/requisitos.md` (v1.4); este documento é o **recorte da Parte 7** —
referencia as §/RN de origem para rastreabilidade. As decisões de implementação que
forem além da spec entram em `decisions.md` na seção "Parte 7" (IDs `D-7xx`).

> **Decisões de escopo:**
> 1. A Parte 7 cobre o **"Módulo de posições"** completo do §2.1.2: cadastro dos
>    **quatro** tipos de instrumento (FUTURO, NDF, OPCAO, OTC) **+ movimentações de
>    FUTURO** (RN-020..025).
> 2. Corte **vertical, ponta a ponta**: `ServicoPosicoes` + `ServicoMovimentacoes` +
>    API REST §5.2.3 + **telas Livewire** (tela 5 Cadastro de posições, tela 6
>    Listagem/detalhe + modal Movimentar §6.4).
> 3. **Autenticação/RBAC adiada para a Parte 10** — endpoints abertos nesta parte
>    (`criado_por` recebe um placeholder, ex.: `"ui"`/`"api"`, como nas Partes 6 e 8).
> 4. **Testes de integração contra PostgreSQL** escritos aqui, **executados na
>    Parte 11** (D-507/D-609/D-809). A suíte **unitária** (serviços com *fakes*) roda
>    sem banco e deve ficar verde nesta parte.

---

## 1. Escopo

**Inclui**
- Criação de posições nos quatro instrumentos (§5.2.3): `POST /posicoes/{futuro,ndf,opcao,otc}`.
- Listagem, detalhe, encerramento e exclusão de posições (§5.2.3).
- **Movimentações de FUTURO** (§3.2.4a, §7.1a): `ABERTURA` automática no cadastro,
  `GET`/`POST /posicoes/{id}/movimentacoes` (AUMENTO/REDUCAO), preço médio ponderado,
  P&L realizado, encerramento por redução total, invariante de quantidade.
- Serviços de aplicação (`ServicoPosicoes`, `ServicoMovimentacoes`), API REST `/api/v1`,
  telas Livewire 5 e 6 (§6.1).

**Não inclui (fora da Parte 7)**
- Autenticação/Sanctum e RBAC/Policies (§9.2) → **Parte 10** (inclui a regra "GESTOR
  remove posição"; nesta parte o DELETE existe mas sem gate de perfil).
- Cálculo de MtM / motor → já entregue na **Parte 8** (consome as posições daqui).
- Relatórios (§5.2.5) → **Parte 9**.
- Testes de integração contra PostgreSQL com `RefreshDatabase` → **Parte 11**.
- Edição/remoção de movimentações — **imutáveis no MVP** (RN-025); só somem em cascata
  com o DELETE da posição.

**Dependências:** Parte 5 (Infraestrutura) e Parte 3 (Domínio) — concluídas. Integra com
a Parte 8 (motor) pelos mesmos contratos.

**Regras de negócio cobertas:** RN-001, RN-002, RN-003, RN-004, RN-004a..e, RN-005,
RN-006, RN-020, RN-021, RN-022, RN-023, RN-024, RN-025 (contexto correlato: RN-011,
RN-018, RN-010a como análogo do bloqueio por MtM).

---

## 2. Estado já existente (reutilizar — não recriar)

| Camada | Artefato | Observação |
|---|---|---|
| Domínio | `app/Dominio/Posicoes/{Posicao,Futuro,Movimentacao,NDF,Opcao,Perna,OTC}.php` | Classes prontas (§4). `Futuro::replay/precoMedio/quantidadeAtual/plRealizado` já implementam RN-021/023. **Não reescrever** o cálculo. |
| Contrato | `app/Aplicacao/Contratos/RepositorioPosicoes.php` | Hoje: `buscarAbertas`, `buscarPorId`, `idsAbertasVencendoEm`, `marcarVencidas`. **A estender** com escrita/consulta paginada (§3). |
| Infra | `app/Infraestrutura/Repositorios/RepositorioPosicoesEloquent.php` | Hidratação Model→domínio via `match` (§4.5). **Ampliar** com persistência. |
| Infra | Models `PosicaoModel`, `PosicaoFuturoModel` (+`movimentacoes()` `hasMany`, D-501), `MovimentacaoModel`, `PosicaoNdfModel`, `PosicaoOpcaoModel`, `PosicaoOpcaoPernaModel`, `PosicaoOtcModel` | `$fillable`/`casts decimal:` prontos; constraints/CHECKs e `uq_mov_abertura` na migration (D-202/D-203). |
| Aplicação | `app/Aplicacao/Excecoes/{ErroAplicacao,ErroValidacao,ErroConflito,ErroNaoEncontrado}.php` | Mapeadas ao envelope em `bootstrap/app.php` (D-605). **Reusar** (sem novas exceções específicas). |
| Padrões | `ServicoPrecos` (D-607: validação no serviço), `ResultadoImportacao`/`ResumoExecucao` (VOs), API Resources/Form Requests da Parte 6/8, `tests/Doubles/` (D-402), `mock_telas/` (UI). | Referências de estilo. |
| Repos correlatos | `RepositorioMtm` (existe `mtm_diario` p/ checar bloqueio do DELETE) | Análogo a `estaReferenciadoEmMtm` (RN-010a) — ver §3.3. |

---

## 3. Camada de aplicação — `app/Aplicacao/Posicoes/`

A validação de negócio mora nos **serviços** (padrão D-607): a mesma regra é reusada
pela API e pelo Livewire. Os Form Requests cobrem só estrutura/tipos.

### 3.1 `ServicoPosicoes`
- `listar(filtros, porPagina = 50)` — filtros `status`, `produto_id`, `instrumento`
  (§5.2.3, §9.1). Devolve `LengthAwarePaginator` (padrão D-603).
- `detalhar(id)` — detalhe com dados do tipo; para FUTURO inclui `preco_medio`,
  `quantidade_atual`, `pl_realizado` e o histórico de movimentações. **404** se não
  existir.
- `criarFuturo(dados)` — cria a mãe `posicao` (instrumento `FUTURO`) + filha
  `posicao_futuro` **e** a movimentação `ABERTURA` (quantidade e `preco_entrada` do
  payload, `data_movimentacao = data_entrada`) **na mesma transação** (RN-020). A
  coluna `posicao.quantidade` recebe a quantidade inicial (invariante RN-024).
- `criarNdf(dados)` — mãe + `posicao_ndf` (RN-005: `valor_nocional > 0`).
- `criarOpcao(dados)` — mãe (`quantidade = 1`, `lado` informativo — RN-004e) +
  `posicao_opcao` + `posicao_opcao_perna[]` (≥ 1 perna — RN-004a; RN-004: `strike > 0`,
  `premio_pago >= 0`).
- `criarOtc(dados)` — mãe + `posicao_otc` (RN-006: `indexador` corresponde a produto
  cadastrado).
- `encerrar(id)` — ação `POST /posicoes/{id}/encerrar`: `status = ENCERRADA` (guard
  `status = ABERTA`; **409** se já encerrada/vencida).
- `remover(id)` — `DELETE /posicoes/{id}`: só se a posição **não tiver MtM** (ver §3.3);
  **409** caso contrário; **404** se não existir. Apaga em cascata (FKs `ON DELETE
  CASCADE`).

**Validações comuns a todos os `criar*` (§7.1):**
- **RN-001** — `quantidade > 0` (na mãe; para OPCAO a mãe é `1` por RN-004e) → 422.
- **RN-002** — `data_vencimento > data_entrada` → 422.
- **RN-003** — `mercado = BALCAO` exige `contraparte`; `BOLSA` dispensa → 422.
- Existência de `produto_id` (reusar `RepositorioProdutos::existe`, Parte 6) → 422.

### 3.2 `ServicoMovimentacoes`
- `listar(posicaoId)` — `GET /posicoes/{id}/movimentacoes` (somente FUTURO). 404 se a
  posição não existe; 409 se `instrumento != FUTURO`.
- `registrar(posicaoId, dados)` — `POST /posicoes/{id}/movimentacoes` (AUMENTO/REDUCAO).
  Em **uma transação** (RN-024):
  1. Carrega a posição; **404** se não existe; **409** se `instrumento != FUTURO` ou
     `status != ABERTA` (RN-020).
  2. Valida: `tipo ∈ {AUMENTO, REDUCAO}`, `quantidade > 0`, `preco > 0`,
     `data_movimentacao >= data_entrada` (RN-025) → **422**.
  3. **RN-022** — REDUCAO com `quantidade > quantidade_atual` → **422** (sem inversão
     de lado).
  4. Insere a movimentação (imutável — RN-025), recomputa o estado via domínio
     (`Futuro::replay`, RN-021/023) e **atualiza `posicao.quantidade`** (invariante
     RN-024).
  5. **RN-022** — se a quantidade chega a **zero**, `status = ENCERRADA` na mesma
     transação.
  6. Retorna o **estado recalculado** (§5.2.3): `quantidade_atual`, `preco_medio`,
     `pl_realizado`, `status` + `movimentacao_id`.

> **Reuso do domínio:** o serviço **não** recalcula preço médio à mão — após inserir a
> movimentação, hidrata o `Futuro` (com as movimentações) e lê `precoMedio()`,
> `quantidadeAtual()`, `plRealizado()`. Assim a fórmula vive num único lugar (§4.3.1)
> e já está coberta pelos testes de domínio (Parte 4).

### 3.3 Bloqueio do DELETE por MtM (análogo à RN-010a)
A spec permite `DELETE /posicoes/{id}` **somente sem MtM** (§5.2.3). Adicionar ao
contrato de posições (ou consultar `RepositorioMtm`) um `temMtm(int $posicaoId): bool`
(existe linha em `mtm_diario` para a posição). Decisão a registrar (`D-7xx`):
preferir um método no `RepositorioPosicoes` por coesão do agregado.

---

## 4. Contratos — extensão (decisão a registrar em `decisions.md`)

O `RepositorioPosicoes` atual é **só leitura** (motor). A Parte 7 precisa de
**escrita/consulta**. **Recomendação:** estender o **mesmo** contrato (um repositório
por agregado, como a Parte 6 fez com `RepositorioPrecos` — D-601), com:

- `listar(array $filtros, int $porPagina): LengthAwarePaginator` (paginado, VOs/DTOs).
- `salvarFuturo(...)`, `salvarNdf(...)`, `salvarOpcao(...)`, `salvarOtc(...)` — ou um
  `salvar(Posicao $p, array $dadosFilha)` — persistem mãe + filha + (FUTURO) `ABERTURA`,
  retornando o id. **Decidir a forma** (`D-7xx`).
- `registrarMovimentacao(int $posicaoId, Movimentacao $mov): int` — insere a
  movimentação e retorna o id (a atualização de `quantidade`/`status` é do serviço,
  transacional).
- `atualizarQuantidadeStatus(int $posicaoId, float $quantidade, string $status): void`
  — invariante RN-024 + encerramento RN-022.
- `encerrar(int $posicaoId): int` — guard `status = ABERTA`.
- `temMtm(int $posicaoId): bool` (§3.3) e `remover(int $posicaoId): void`.

> **Transações:** os métodos compostos (criar futuro, registrar movimentação) devem
> rodar dentro de `DB::transaction` no **repositório** ou no **serviço** — registrar
> onde (`D-7xx`). A ABERTURA automática e o `uq_mov_abertura` garantem "exatamente uma
> ABERTURA" no nível do banco (D-203).

Ampliar `RepositorioPosicoesEloquent` para implementar os novos métodos, **sem** mexer
na hidratação `match` existente (§4.5).

---

## 5. API REST — `app/Http/` (§5.2.3)

Rotas sob `/api/v1` em `routes/api.php` (já registrado, D-608). Reusar o handler de
erros central (D-605) e o padrão de Resources/Form Requests das Partes 6/8.

### 5.1 Endpoints (§5.2.3)
```
GET    /api/v1/posicoes?status=ABERTA&produto_id=X&instrumento=FUTURO   Lista (paginado)
GET    /api/v1/posicoes/{id}                          Detalhe (com dados do tipo)
POST   /api/v1/posicoes/futuro                        Cria FUTURO (+ ABERTURA automática)
POST   /api/v1/posicoes/ndf                           Cria NDF
POST   /api/v1/posicoes/opcao                         Cria OPCAO (1..N pernas)
POST   /api/v1/posicoes/otc                           Cria OTC
POST   /api/v1/posicoes/{id}/encerrar                 Encerra (status = ENCERRADA)
DELETE /api/v1/posicoes/{id}                          Remove (409 se tiver MtM)
GET    /api/v1/posicoes/{id}/movimentacoes            Lista movimentações (FUTURO)
POST   /api/v1/posicoes/{id}/movimentacoes            Registra AUMENTO/REDUCAO (FUTURO)
```

### 5.2 Form Requests (`app/Http/Requests/`)
Um por tipo de criação + um para movimentação (estrutura/tipos; RNs no serviço, D-607):
- `PosicaoFuturoStoreRequest` — `produto_id, lado, quantidade, data_entrada,
  data_vencimento, preco_entrada, codigo_contrato, mercado?, contraparte?, observacoes?`.
- `PosicaoNdfStoreRequest` — `+ taxa_contratada, valor_nocional, moeda_nocional`.
- `PosicaoOpcaoStoreRequest` — `+ nome_estrutura?, pernas: [{tipo_opcao, estilo, strike,
  premio_pago, quantidade, lado}]` (≥ 1; validação aninhada `pernas.*`).
- `PosicaoOtcStoreRequest` — `+ preco_entrada, indexador, premio_otc?`.
- `MovimentacaoStoreRequest` — `tipo ∈ {AUMENTO,REDUCAO}, data_movimentacao, quantidade,
  preco`.

### 5.3 API Resources (`app/Http/Resources/`)
- `PosicaoResource` — saída comum + bloco do tipo; **decimais sem aspas**, datas ISO
  (§5.1). Para FUTURO, embute `preco_medio`, `quantidade_atual`, `pl_realizado`.
- `MovimentacaoResource` — item do histórico.
- Resposta de `POST .../movimentacoes` (200) no shape do §5.2.3:
  ```json
  { "posicao_id": 1001, "movimentacao_id": 7, "quantidade_atual": 150,
    "preco_medio": 1410.00, "pl_realizado": 0.00, "status": "ABERTA" }
  ```

### 5.4 Erros (envelope §5.1, via handler D-605)
- **404** posição inexistente; **409** `instrumento != FUTURO`, `status != ABERTA`,
  encerrar já encerrada, ou DELETE com MtM; **422** validações (RN-001/002/003/004/005/
  006/022/025). Códigos estáveis: ex. `posicao_nao_encontrada`, `movimentacao_invalida`,
  `instrumento_nao_movimentavel`, `posicao_encerrada`, `reducao_excede_quantidade`,
  `posicao_com_mtm`.

---

## 6. UI — Blade + Livewire (§6) — `app/Livewire/Posicoes/`

- **Tela 5 — Cadastro de posições (§6.1.5, wireframe §6.3):** formulário **dinâmico**
  que troca os campos conforme o `instrumento` selecionado; para OPCAO, o editor de
  **pernas** (adicionar/remover, mín. 1 — RN-004a/b). Reusa os mesmos serviços
  `criar*`.
- **Tela 6 — Listagem de posições (§6.1.6):** tabela com filtros (status, produto,
  tipo). O **detalhe de FUTURO** mostra preço médio, quantidade atual, P&L realizado e
  o histórico de movimentações, com a ação **Movimentar**.
- **Modal Movimentar (§6.4):** aumento/redução com **prévia ao vivo** (qtd e preço
  médio antes→depois; P&L realizado da operação em reduções; aviso de que redução total
  encerra — RN-022). A prévia pode reusar o domínio (`Futuro` hidratado com a
  movimentação hipotética) para não duplicar a fórmula.
- Rotas Livewire em `routes/web.php`; views em `resources/views/`. Estética: `mock_telas/`.

---

## 7. Testes (§8)

- **Unidade (Pest, sem banco):** `ServicoPosicoes`/`ServicoMovimentacoes` com *fakes*
  dos contratos (padrão D-402). Casos mínimos (§8.1/§8.2):
  - Criação: quantidade ≤ 0 (RN-001), datas invertidas (RN-002), BALCAO sem contraparte
    (RN-003), opção sem pernas (RN-004a), strike 0 (RN-004), NDF nocional ≤ 0 (RN-005),
    OTC com indexador sem produto (RN-006).
  - FUTURO: cadastro gera **ABERTURA** automática (RN-020); AUMENTO desloca preço médio
    (RN-021); REDUCAO mantém preço médio e gera realizado (RN-023), inclusive sinal em
    posição **vendida**; redução total **encerra** (RN-022) e zera quantidade (RN-024);
    redução excedente **422** (RN-022); `data_movimentacao < data_entrada` **422**
    (RN-025); movimentação em não-FUTURO ou posição encerrada **409** (RN-020).
  - `quantidade` da mãe acompanha as movimentações (invariante RN-024).
- **Feature/Integração (definir aqui, executar na Parte 11 — D-507):** endpoints de
  criação dos 4 tipos, detalhe, encerrar, DELETE bloqueado por MtM, fluxo
  abertura→aumento→redução com o motor conferindo `pl_acumulado = mtm_valor + realizado`
  (§8.2), `uq_mov_abertura` e CHECKs no banco; contra PostgreSQL com `RefreshDatabase`.

---

## 7-bis. Resoluções da crítica técnica (incorporar à implementação)

Validadas contra o código existente (`app/Dominio`, contratos, migrations §3), as
pendências abaixo **devem ser tratadas nesta parte**. Cada uma vira decisão em
`decisions.md` (§8) e, quando muda comportamento observável, critério de aceite (§9).

### 7b.1 RN-006 — `indexador` × produto: validar `produto_id`, `indexador` é rótulo (D-710)
**Constatação.** `produto` só tem `nome, unidade, bolsa_ref, moeda_cotacao, ativo`
(migration `..._000001`); **não há coluna de código/indexador**. `OTC::calcularMtm`
precifica pelo `produto_id` da mãe e **nunca usa `indexador`** (`app/Dominio/Posicoes/OTC.php`).
Logo, "casar `indexador` com um produto via `existe(int)`" (§3.1) é inimplementável.
**Resolução.** Reinterpretar a RN-006: o `criarOtc` valida a **existência de
`produto_id`** (reusa `RepositorioProdutos::existe`, Parte 6) → 422 se ausente;
`indexador` é **texto livre** (rótulo informativo, `VARCHAR(30)`), sem lookup. Não
introduzir coluna nova em `produto` (fora do MVP). Atualizar §3.1 e os testes do §7
para refletir "produto_id existente" no lugar de "indexador ↔ produto".

### 7b.2 Desempate de movimentações de mesma data no `replay` (D-711) — **estrutural**
**Constatação.** `Futuro::replay()` ordena só por `[data, ABERTURA-primeiro]`
(`Futuro.php:43`) e `usort` **não é estável**; o VO `Movimentacao` não carrega `id`.
O índice `idx_mov_posicao_data` é `(posicao_id, data_movimentacao, id)`, sinalizando
desempate por `id`. Com AUMENTO+REDUCAO na **mesma data**, o estado **re-derivado**
pode divergir do **validado na inserção** (e a quantidade pode ficar negativa no meio
do replay).
**Resolução.** A hidratação de `Movimentacao` passa a carregar a **ordem de inserção**
(o `id`, ou um campo `sequencia`/ordinal); `Futuro::replay` desempata por esse campo
**após a data** — alinhando o domínio ao índice. Ajuste de contrato de hidratação do
domínio (originalmente Parte 3), feito aqui por ser pré-requisito da movimentação. O
repositório ordena `ORDER BY data_movimentacao, id` ao hidratar. Cobrir com teste de
domínio (mesma data: AUMENTO antes da REDUCAO ⇒ resultado determinístico, nunca
negativo).

### 7b.3 Comparação de quantidade a zero por escala (D-712)
**Constatação.** Domínio em `float` (D-305) e `quantidade NUMERIC(18,4)`. Testar
`quantidadeAtual() === 0.0` ou `> quantidade_atual` (RN-022) com frações é frágil
(resíduo de `float`).
**Resolução.** Todas as comparações de quantidade da Parte 7 — guard da RN-022 e
decisão de encerrar (RN-022/024) — operam **arredondadas a 4 casas** (escala da
coluna), via helper único (ex.: `round($q, 4)`), no mesmo espírito do short-circuit
por escala da Parte 8 (D-803). "Redução total" = saldo arredondado a 4 casas igual a
zero; "excede" = `round(reducao,4) > round(saldo,4)`.

### 7b.4 Lock pessimista no registro de movimentação (D-713) — **estrutural**
**Constatação.** §3.2 valida RN-022 "fora de lock"; `DB::transaction` em `READ
COMMITTED` não impede duas reduções concorrentes de superreduzir — violando RN-024 e
caindo no CHECK `quantidade >= 0` como **500** em vez de 409/422. `uq_mov_abertura`
protege só a ABERTURA.
**Resolução.** `ServicoMovimentacoes::registrar` abre a transação com
`SELECT ... FOR UPDATE` na linha de `posicao` (lock pessimista) **antes** de ler o
saldo e validar RN-022. O repositório expõe a leitura travada (ex.:
`buscarPorIdParaAtualizar(int): ?Posicao` ou `lockForUpdate()` no `salvar`/`registrar`).
Mesma proteção vale para o encerramento por redução total.

### 7b.5 Semântica de `pl_realizado` na API vs. prévia da UI (D-714)
**Constatação.** §5.3 e a tela §6.4 usam o mesmo nome para conceitos distintos quando
há reduções anteriores: `plRealizado()` do domínio é **acumulado**; a prévia do modal
quer o **incremento** da operação.
**Resolução.** O campo `pl_realizado` da **API** (resposta de `POST .../movimentacoes`
e detalhe) é **acumulado** (= `Futuro::plRealizado()`, coerente com `pl_acumulado` do
MtM). A **prévia da UI** calcula o **delta** por diferença
(`plRealizado(com movimentação hipotética) − plRealizado(atual)`), já que `replay()` é
privado e só expõe o agregado. Documentar o rótulo "P&L realizado desta operação" na
tela como esse delta.

### 7b.6 `mercado` obrigatório no payload (D-715)
**Constatação.** `posicao.mercado` é NOT NULL + CHECK `IN ('BOLSA','BALCAO')`
(migration `..._000003`), mas §5.2 marca `mercado?` opcional e a RN-003 depende dele.
**Resolução.** `mercado` é **obrigatório** no `PosicaoFuturoStoreRequest` (e demais
`criar*` que persistem a mãe), com `required|in:BOLSA,BALCAO`; `lado` idem
(`required|in:COMPRADO,VENDIDO`). A RN-003 (`BALCAO` exige `contraparte`) é avaliada no
serviço com `mercado` já garantido. Sem default implícito.

### 7b.7 Teto de `data_movimentacao` (D-716)
**Constatação.** RN-025 só exige `data_movimentacao >= data_entrada`; sem teto,
aceita-se data futura/pós-vencimento, distorcendo `replay`/`variacao_dia`.
**Resolução.** `data_movimentacao` deve respeitar `<= data_vencimento` **e**
`<= hoje` → 422 (`movimentacao_invalida`) caso contrário. Mesma validação para
`data_entrada <= hoje` nas criações? (não — mantém-se só a RN-002; a entrada pode ser
retroativa). Aplicar o teto apenas às movimentações.

### 7b.8 Encerramento manual de FUTURO com quantidade em aberto (D-717)
**Constatação.** `POST /posicoes/{id}/encerrar` congela o status sem realizar P&L do
saldo; para FUTURO com `quantidade_atual > 0` a spec não define a semântica.
**Resolução.** **Bloquear** `encerrar` de FUTURO com `quantidade_atual > 0` (arredondada
a 4 casas) → **409** (`posicao_com_saldo`), forçando o usuário a fazer a **redução
total** (RN-022), que realiza o resultado. NDF/OPCAO/OTC seguem encerrando direto pelo
guard `status = ABERTA`.

### 7b.9 `temMtm` no `RepositorioMtm` (D-718)
**Constatação.** O dado vive em `mtm_diario` (agregado do MtM); `RepositorioMtm` hoje
só tem `buscarUltimoAnterior`/`upsert`.
**Resolução.** Adicionar `RepositorioMtm::existePorPosicao(int $posicaoId): bool` (mais
aderente à propriedade do dado) e o `ServicoPosicoes::remover` consulta esse método
para o bloqueio do DELETE (409 `posicao_com_mtm`). Substitui o `temMtm()` em
`RepositorioPosicoes` proposto no §3.3/§4 — atualizar essas seções.

### 7b.10 Extensões além da `requisitos.md` (D-719)
- **Filtro `instrumento`** na listagem (§3.1/§5.1) e o filtro "tipo" da tela 6 vão além
  do §5.2.3 (`?status=&produto_id=`). Manter como extensão registrada.
- **OPCAO mãe `quantidade`**: o serviço **força `1`** e **ignora** qualquer
  `quantidade` enviado pelo cliente (não rejeita) — RN-004e.
- **`quantidade > 0` por perna**: além do CHECK no banco, validar no serviço e cobrir
  com teste (`pernas.*.quantidade`), para não depender só do banco.

---

## 8. Decisões a registrar em `decisions.md` (seção "Parte 7")

- **D-7xx** — Extensão do contrato `RepositorioPosicoes` (escrita/consulta) vs. novo
  contrato. Recomendação: estender o existente (simetria com D-601).
- **D-7xx** — Forma da persistência: `salvar*` por tipo vs. `salvar(Posicao, dadosFilha)`.
- **D-7xx** — Onde fica a transação (serviço vs. repositório) na criação de FUTURO e no
  registro de movimentação (RN-020/024).
- **D-7xx** — Reuso do domínio (`Futuro::replay`) no serviço/prévia, em vez de recálculo
  manual de preço médio.
- **D-7xx** — Placeholder de `criado_por`/`criado_por` da movimentação enquanto a Parte 10
  não traz autenticação.

**Decisões da crítica técnica (§7-bis):**
- **D-710** — RN-006 reinterpretada: validar `produto_id` existente; `indexador` é rótulo livre.
- **D-711** — `Movimentacao` hidratada com ordem de inserção; `replay` desempata por `id`/sequência (alinha ao índice `idx_mov_posicao_data`). *(Ajuste de domínio.)*
- **D-712** — Comparação de quantidade arredondada a 4 casas (escala da coluna) em RN-022/024.
- **D-713** — `SELECT ... FOR UPDATE` na posição no início da transação de movimentação (lock pessimista).
- **D-714** — `pl_realizado` da API = **acumulado**; prévia da UI = **delta** por diferença.
- **D-715** — `mercado` (e `lado`) **obrigatórios** com `in:` no Form Request; sem default.
- **D-716** — `data_movimentacao` com teto `<= data_vencimento` e `<= hoje` → 422.
- **D-717** — `encerrar` de FUTURO com saldo > 0 → 409 (`posicao_com_saldo`); usar redução total.
- **D-718** — `RepositorioMtm::existePorPosicao` (substitui `temMtm` em `RepositorioPosicoes`).
- **D-719** — Extensões: filtro `instrumento`; OPCAO força `quantidade = 1` (ignora cliente); `quantidade > 0` por perna validada no serviço.

---

## 9. Critérios de aceite

- [ ] Endpoints §5.2.3 implementados sob `/api/v1`, com envelope de erro §5.1.
- [ ] Criação dos 4 tipos com as RN-001..006 verificadas por teste; OPCAO multi-perna
      (RN-004a..e).
- [ ] `POST /posicoes/futuro` cria a `ABERTURA` automática (RN-020) na mesma transação.
- [ ] `POST /posicoes/{id}/movimentacoes`: AUMENTO/REDUCAO atualizam preço médio
      (RN-021), realizado (RN-023), quantidade (RN-024) e encerram na redução total
      (RN-022); redução excedente e data anterior à entrada → 422 (RN-022/025).
- [ ] Movimentação em não-FUTURO ou posição não-ABERTA → 409 (RN-020).
- [ ] `POST /posicoes/{id}/encerrar` e `DELETE` (409 com MtM) funcionais.
- [ ] Telas Livewire 5 e 6 funcionais (cadastro dinâmico + listagem/detalhe + modal
      Movimentar com prévia §6.4).
- [ ] Decisões registradas em `decisions.md`; suíte unitária verde; casos de integração
      escritos (execução na Parte 11).
- [ ] **Resoluções da crítica (§7-bis):** `criarOtc` valida `produto_id` existente
      (D-710); `replay` determinístico em movimentações de mesma data, sem quantidade
      negativa (D-711); comparações de quantidade arredondadas a 4 casas (D-712);
      `registrarMovimentacao` com lock `FOR UPDATE` (D-713); `pl_realizado` da API
      acumulado e prévia da UI por delta (D-714); `mercado`/`lado` obrigatórios com
      `in:` (D-715); `data_movimentacao <= data_vencimento` e `<= hoje` → 422 (D-716);
      `encerrar` FUTURO com saldo → 409 (D-717); DELETE bloqueado via
      `RepositorioMtm::existePorPosicao` (D-718); OPCAO força `quantidade = 1` e valida
      `quantidade > 0` por perna (D-719).
