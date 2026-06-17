# Prompt — Especificação dos testes de integração

Você é um(a) engenheiro(a) de qualidade/testes. Leia **integralmente** o
`requisitos.md` (v1.4, com foco em §3 — modelo de dados/constraints, §4.5 —
repositório, §5 — APIs, §7 — regras, §8.2/§8.4 — testes/cobertura e §9.2 — RBAC) e o
`ARCHITECTURE.md` (stack Laravel, camadas e estrutura de testes) e crie um arquivo
`INTEGRATION-TESTS.md` na raiz do projeto: a **especificação detalhada dos testes de
integração (Feature)** do **NeverVenture**, servindo de plano e checklist da suíte.

**Contexto** (confirme lendo os documentos-base): os testes de integração validam as
**camadas funcionando juntas** — HTTP/Livewire → serviços → Eloquent → **PostgreSQL
real** — exercitando o que os testes unitários deixam de fora: persistência,
**constraints e índices** (§3), **hidratação polimórfica** (§4.5), contratos da API
(§5), **autenticação Sanctum e RBAC por Policies** (§9.2), **upload de CSV**
(§5.2.2) e a **idempotência do motor** ponta a ponta. A meta de cobertura da camada
de aplicação é **≥ 70%** (§8.4).

## Ferramentas e ambiente (obrigatórios)

- **Runner:** **Pest** + **Laravel Testing** (Feature).
- **Banco efêmero:** **PostgreSQL real** com **`RefreshDatabase`** (migrations a cada
  teste, em transação). **Não usar SQLite** — é preciso validar o índice único
  **parcial** `uq_mov_abertura`, os índices parciais (§3.3), `JSONB`
  (`motor_execucao.falhas`), `NUMERIC` exato e o UPSERT. Configurar um *connection*
  de teste (`pgsql`) no `phpunit.xml`/`.env.testing`.
- **HTTP:** helpers do Laravel (`$this->getJson`, `postJson`, `putJson`,
  `deleteJson`) batendo na API `/api/v1`.
- **Autenticação:** **`Sanctum::actingAs($usuario, [...])`** (ou `actingAs` no guard
  web) para cada perfil OPERADOR/GESTOR/ADMIN.
- **Livewire:** `Livewire::test(Componente::class)` para as telas (§6).
- **Dados:** **Eloquent factories** e **seeders**; estado conhecido por teste.
- **Determinismo:** `travelTo()`/`Carbon::setTestNow()` para datas fixas.

## Conteúdo do `INTEGRATION-TESTS.md` (nesta ordem)

1. **Objetivo e escopo** — validação **cross-layer**; o que cobre (persistência,
   constraints/índices, hidratação §4.5, API, Livewire, Sanctum/Policies, CSV, motor
   idempotente, relatórios) e o que **fica fora** (cálculo puro de domínio, já em
   `UNIT-TESTS.md`; performance do §9.1). Meta de aplicação ≥ 70% (§8.4).

2. **Ambiente e infraestrutura de teste** — PostgreSQL de teste e **por que não
   SQLite**; `RefreshDatabase` + migrations; `phpunit.xml`/`.env.testing`; factories/
   seeders; helpers de autenticação por perfil (`Sanctum::actingAs`).

3. **Organização e convenções** — estrutura `tests/Feature/` (`Api/`, `Livewire/`,
   `Motor/`, `Fluxos/`); nomes em **português**; factories de produto/preço/posição;
   limpeza por transação (`RefreshDatabase`).

4. **Cenários por área** (entradas → resultado):
   - **Fluxo completo (§8.2):** criar produto → lançar preço → criar posição → rodar
     motor → consultar relatório, conferindo o MtM persistido.
   - **Repositório e hidratação polimórfica (§4.5):** `buscarAbertas()` hidrata a
     **subclasse correta** por `instrumento`; `Opcao` com `pernas`; `Futuro` com
     `movimentacoes` na ordem certa (eager loading).
   - **Constraints e integridade (§3):** `UNIQUE(produto_id, data_preco)` (RN-007);
     `UNIQUE(posicao_id, data_calculo)` no MtM; **`uq_mov_abertura`** (RN-020); CHECKs
     (`quantidade >= 0` na mãe, `> 0` na movimentação); preço referenciado por MtM
     **não removível** → `409` (RN-010a); posição com MtM **não deletável**.
   - **Motor MtM (§4.4):** **idempotência** — reprocessar a mesma data faz
     `updateOrCreate`, não duplica (RN-013); ausência de preço marca **falha** e
     continua, registrando em `motor_execucao` (RN-012, §3.2.9); vencimento →
     `VENCIDA` (RN-014); processa **somente ABERTA** (RN-011); `pl_acumulado =
     mtm_valor + realizado` (RN-023).
   - **Movimentações de futuro (§5.2.3, RN-020..025):** `POST /posicoes/futuro` cria
     a **ABERTURA automática** (RN-020); `POST /posicoes/{id}/movimentacoes` retorna o
     **estado recalculado**; **redução total** → `ENCERRADA` e a posição **some do
     motor** (RN-022/RN-011); redução **excedente** → `422` (RN-022);
     `data_movimentacao < data_entrada` → `422` (RN-025); movimentação em
     **não-FUTURO**/posição **não-ABERTA** → `409`.
   - **Upload de CSV (§5.2.2, RN-010):** lote válido/inválido → aceita válidas e
     retorna **relatório de aceitas/rejeitadas**; validação de `mimes`/tamanho.
   - **Contrato da API (§5.1):** envelope de erro `{ "erro", "mensagem" }` e status
     (`400/401/403/404/409/422`); paginação (`paginate`).
   - **AuthN/AuthZ (§9.2):** login e tokens Sanctum; **matriz RBAC perfil × rota**
     via Policies — `401` sem token, `403` sem permissão (ex.: OPERADOR em
     `DELETE /posicoes/{id}`; não-ADMIN cadastrando produto/usuário).
   - **Telas (Livewire, §6):** componentes de cadastro/listagem/detalhe e o modal
     **Movimentar** (§6.4) — render, validação e persistência via `Livewire::test`.
   - **Relatórios (§5.2.5, §7.4):** posição aberta (RN-016), P&L diário soma
     `variacao_dia` (RN-017), P&L acumulado soma **`pl_acumulado`** (RN-018),
     exposição líquida = Σ `quantidade × sinal` (RN-019).

5. **Estratégia de dados e isolamento** — factories/seeders mínimos por cenário,
   datas fixas (`travelTo`), `RefreshDatabase` por teste e (se houver paralelização)
   **um banco por processo**.

6. **Cobertura e critérios de aceite** — meta de aplicação ≥ 70% (§8.4); **cada
   fluxo do §8.2** e cada RN de **processo/persistência** (RN-007, RN-010, RN-010a,
   RN-011..014, RN-020, RN-022, RN-023, RN-025) com ≥ 1 teste; suíte determinística e
   idempotente.

7. **Rastreabilidade RN → testes** — tabela mapeando cada regra de processo/
   persistência e cada contrato de API ao(s) teste(s).

8. **Exemplo de teste** — ao menos um teste de **fluxo** em Pest/Feature com
   `Sanctum::actingAs` + `postJson` (criar futuro → aumentar → reduzir → rodar motor →
   conferir `pl_acumulado`), e um teste de **constraint** (remover preço usado →
   `409`).

## Diretrizes finais

- Idioma do documento: **português**. Tom: técnico, objetivo, direto.
- Usar **PostgreSQL real** (não SQLite). Cobrir caminhos **felizes e de erro**.
- **Não duplicar** o que o `UNIT-TESTS.md` já cobre (cálculo puro de domínio); aqui o
  foco é integração entre camadas e persistência.
- Cite § e RN; para lacunas reais nos requisitos, registre **premissas explícitas**.
