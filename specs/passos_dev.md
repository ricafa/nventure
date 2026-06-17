# Passos de desenvolvimento — MVP Risco de Mercado (NeverVenture)

> **Propósito.** Roteiro de execução fim-a-fim para implementar o MVP a partir das
> especificações. Cada **fase** é uma etapa de desenvolvimento com objetivo, entregáveis,
> tarefas, dependências e **Definition of Done (DoD)**. Uma fase só inicia quando a
> anterior está "verde" (DoD atendido), salvo dependências explicitamente paralelizáveis.
>
> **Fontes da verdade:**
> - Negócio/contratos: `specs/requisitos.md` (v1.5) — §/RN.
> - Arquitetura alvo: **MVC nativo + *fat model*** (Alternativa A) — registrada em `requisitos.md` §2.2 e §4.
> - Decisões: rótulos `D-xxx` / `D-MVC-x` citados inline (o `decisions.md` legado, da
>   tentativa DDD, foi arquivado em `historic-plans/` e está desatualizado).
>
> **Convenção de status por tarefa:** `[ ]` pendente · `[~]` em andamento · `[x]` concluído.
> Todos os comandos rodam no container (`docker compose exec app ...`), conforme `CLAUDE.md`.

---

## Visão geral das fases

| Fase | Etapa | Foco | Depende de |
|---|---|---|---|
| 0 | Fundação | Docker, Laravel 13, Postgres, Sanctum, CI, qualidade | — |
| 1 | Esqueleto MVC + Banco | Estrutura de pastas + migrations/índices/constraints (§3) | 0 |
| 2 | Models + cálculo (M) | Fat models, `newFromBuilder`, traits puros | 1 |
| **3** | **Testes unitários** | Cálculo de MtM/PM/P&L sem banco (≥ 90%) | 2 |
| 4 | Produtos & Preços (Parte 6) | Service + API + Livewire + CSV (`FontePrecos`) | 2 |
| 5 | Posições (Parte 7) | Service + movimentações + transação/lock | 2, 4 |
| 6 | Motor MtM (Parte 8) | Service + Command + agendamento + idempotência | 4, 5 |
| 7 | Relatórios (Parte 9) | 4 visões consolidadas | 6 |
| 8 | Seed & dados de demonstração | Factories, seeders e dataset de demo (fluxo completo) | 6 |
| **9** | **Testes de integração** | Fluxo ponta a ponta com banco real | 4–8 |
| 10 | RBAC & Autenticação | Sanctum + Policies por perfil (Parte 10) | 4–7 |
| **11** | **Segurança (gate)** | Revisão e endurecimento de segurança | 10 |
| 12 | Não-funcionais | Performance, observabilidade, `/health` `/metrics` | 6, 7, 8 |
| **13** | **Testes de regressão** | Suíte consolidada + gates de CI + baseline | 3, 9, 11, 12 |
| 14 | Hardening & Entrega | Cron, docs/ADRs, UAT, release | todas |

> Fases **em negrito** são as de teste/segurança pedidas explicitamente. Unitários (3),
> integração (9) e regressão (13) ficam em fases distintas; segurança tem fase de
> verificação dedicada (11), além de tarefas de segurança embutidas nas demais fases.

---

## Fase 0 — Fundação do projeto

**Objetivo.** Ambiente reprodutível e pipeline de qualidade antes de escrever regra de negócio.

> **Especificação detalhada:** `specs/spec_parte_0.md` (comandos, arquivos e DoD completos).

**Entregáveis**
- Projeto **Laravel 13 (PHP 8.3)** versionado, subindo via `docker compose up -d`.
- PostgreSQL 15+ de desenvolvimento **e** de teste (`postgres_test`).
- Starter kit **Livewire** (Livewire 4 + Tailwind + Flux UI; auth via Fortify) adaptado à tabela `usuario`;
  Sanctum instalado; Pest configurado; ferramentas de estilo/estática.

**Tarefas**
- [ ] Esqueleto Laravel 13 com starter kit Livewire + `docker-compose.yml` (serviços `app`, `postgres`, `postgres_test`).
- [ ] `.env`/`.env.testing` (conexão de teste apontando a `postgres_test`; `APP_LOCALE=pt_BR`).
- [ ] Adaptar auth do starter kit à tabela `usuario` (login por `login`; desabilitar registro/verificação/reset).
- [ ] Instalar Sanctum (sessão web; tokens de API ficam para a Fase 10).
- [ ] Instalar/Configurar Pest, Pint (estilo) e PHPStan/Larastan (nível 8, exclusões de `Http/Livewire/Providers`).
- [ ] Pipeline CI (GitHub Actions): `pint --test`, `phpstan`, `composer test` em cada push/PR, com Postgres service.
- [ ] `composer test` definido conforme `CLAUDE.md` (DB de teste por env vars).

**DoD.** `docker compose up -d` sobe a stack; login do starter kit autentica contra
`usuario` por `login`; `composer test` roda e o CI executa estilo + estática + testes em verde.

---

## Fase 1 — Esqueleto MVC + Banco de dados

**Objetivo.** Materializar a árvore de pastas da arquitetura MVC fat model (`app/Models`,
`app/Services`, `app/Facades`, …) e o esquema §3 **intacto**.

**Entregáveis**
- Pastas: `app/Models` (+ `Concerns/`), `app/Services` (+ `Dados/`), `app/Facades`,
  `app/Support/Csv`, `app/Exceptions`, `app/Policies`.
- Migrations de todas as tabelas §3.2 com constraints e índices §3.3.

**Tarefas**
- [ ] Criar diretórios e registrar *singletons* + Facades no `AppServiceProvider` (esqueleto).
- [ ] Migrations: `produto`, `preco_referencia`, `posicao`, `posicao_futuro`,
      `posicao_movimentacao`, `posicao_ndf`, `posicao_opcao`, `posicao_opcao_perna`,
      `posicao_otc`, `mtm_diario`, `motor_execucao`, `usuario`.
- [ ] CHECKs de domínio (instrumento, mercado, lado, status, tipo de movimentação, perfil).
- [ ] **Índice único parcial** `uq_mov_abertura` (`WHERE tipo = 'ABERTURA'`) — exige Postgres.
- [ ] UNIQUEs: `preco_referencia(produto_id, data_preco)`, `mtm_diario(posicao_id, data_calculo)`,
      `posicao_opcao_perna(posicao_id, sequencia)`.
- [ ] Índices recomendados §3.3 (incl. parciais e `DESC`).
- [ ] Exceções base em `app/Exceptions/`: `ErroAplicacao`, `ErroValidacao` (422),
      `ErroConflito` (409), `ErroNaoEncontrado` (404) + mapeamento ao envelope §5.1 em
      `bootstrap/app.php` (D-605).

**DoD.** `php artisan migrate:fresh` cria todo o esquema em Postgres; um teste de
migração confirma a existência do índice parcial e dos UNIQUEs.

---

## Fase 2 — Models (M) + cálculo de MtM (*fat model*)

**Objetivo.** Concentrar persistência **e** cálculo nos Models Eloquent, com polimorfismo
via `newFromBuilder` e cálculo "puro" (requisitos §4 e §4.5).

**Entregáveis**
- `Posicao` (base) + `Futuro`, `Ndf`, `Opcao`, `Otc`; `Perna`, `Movimentacao`;
  `Produto`, `PrecoReferencia`, `MtmDiario`, `MotorExecucao`, `Usuario`.
- Traits puros em `app/Models/Concerns/` (ex.: aritmética de preço médio/MtM).

**Tarefas**
- [ ] `Posicao::newFromBuilder` com `match($instrumento)` → subclasse (D-MVC-1: relação-filha).
- [ ] Relações `hasOne`/`hasMany`/`belongsTo` (futuro, ndf, opcao.pernas, otc, movimentacoes, produto, precos).
- [ ] Métodos de cálculo nas subclasses: `calcularMtm`, `sinal`, `plRealizado`,
      `Futuro::replay/precoMedio/quantidadeAtual` — **sem query** (operam sobre relações carregadas).
- [ ] Casts `decimal:` e **borda string⇄float** num helper único, arredondando à escala
      da coluna (4 casas — D-712, D-MVC-2).
- [ ] `Movimentacao` imutável por design (sem update/delete expostos — RN-025).

**DoD.** Models instanciáveis via `make()`/`setRawAttributes`; `Posicao::query()->get()`
devolve a subclasse correta (teste de hidratação por tipo). Sem regressão de estilo/estática.

---

## Fase 3 — Testes unitários (cálculo, sem banco)

**Objetivo.** Travar as fórmulas antes da orquestração. Meta de cobertura de cálculo **≥ 90%** (§8.4).

**Escopo (requisitos §8.1)**
- `calcularMtm` de cada subclasse: comprado/vendido × mercado a favor/contra (≥ 4 cenários).
- `sinal` da base e de `Perna`.
- Estruturas multi-perna: straddle, strangle, collar, bull call spread, bear put spread, butterfly.
- `Futuro` com movimentações: preço médio após aumento; redução mantém PM e gera realizado;
  redução total zera/encerra; redução excedente rejeitada (regra exercida no Service, mas
  o cálculo de `replay` é testado aqui); sinal invertido em posição vendida.
- Conversão decimal (borda string⇄float) e arredondamento a 4 casas.

**Tarefas**
- [ ] Testes Pest dos exemplos do §8.1 (valores esperados idênticos).
- [ ] Testes dos *traits* de `Concerns/` com valores primitivos (sem instanciar Eloquent).
- [ ] Teste dedicado do polimorfismo de `newFromBuilder` (um por instrumento).
- [ ] Relatório de cobertura do "núcleo de cálculo".

**DoD.** Suíte unitária verde; cobertura do cálculo **≥ 90%**; nenhum teste de cálculo toca o banco.

---

## Fase 4 — Módulo Produtos & Preços (Parte 6)

**Objetivo.** CRUD de produtos e ingestão de preços (manual + CSV), com RNs no Service (D-607).

**Entregáveis**
- `ServicoProdutos`, `ServicoPrecos`; `app/Support/Csv/ImportadorPrecosCsv` implementando
  a interface **`FontePrecos`** (único ponto de extensão de ingestão que sobrevive como interface).
- API REST §5.2.1/§5.2.2; Livewire de cadastro de produto e lançamento/upload de preço.
- DTO `ResultadoImportacao` em `app/Services/Dados/`.

**Tarefas**
- [ ] RN-007 (unicidade produto+data), RN-008 (preço > 0), RN-009 (câmbio > 0).
- [ ] RN-010: upload CSV processa linhas válidas e reporta rejeitadas (relatório aceitas/rejeitadas).
- [ ] RN-010a: bloquear exclusão de preço referenciado por `mtm_diario` → **409**.
- [ ] **Segurança CSV (embutida):** anti-formula-injection (CWE-1236) no importador;
      validar tipos/escala; limitar tamanho/linhas do upload.
- [ ] Form Requests (estrutura) + Resources (serialização §5.1).

**DoD.** Endpoints e telas operando; CSV com linhas mistas retorna relatório correto;
exclusão de preço referenciado retorna 409; testes de feature do módulo verdes.

---

## Fase 5 — Módulo Posições (Parte 7)

**Objetivo.** Cadastro dos 4 instrumentos + movimentações de FUTURO com integridade transacional.

**Entregáveis**
- `ServicoPosicoes`, `ServicoMovimentacoes`; DTOs `PosicaoResumo`/`PosicaoDetalhe`/`EstadoMovimentacao` (D-704).
- API §5.2.3; Livewire `/posicoes` (listagem+detalhe+modal Movimentar) e `/posicoes/nova`.

**Tarefas**
- [ ] Cadastro: RN-001..006 (incl. RN-004a..e para opção; RN-003 contraparte BALCAO; RN-006 indexador OTC).
- [ ] `criarFuturo`: insere mãe + filha + `ABERTURA` na **mesma transação** (RN-020);
      `uq_mov_abertura` garante exatamente uma abertura.
- [ ] Movimentações: RN-021 (PM ponderado), RN-022 (redução > saldo → 422; redução total encerra),
      RN-023 (realizado), RN-024 (invariante de quantidade), RN-025 (data ≥ entrada; imutável).
- [ ] **Transação + lock pessimista** `lockForUpdate` no Service (D-713);
      recompute via `replay()`; atualizar `quantidade`/`status`.
- [ ] `encerrar` (ação) e `DELETE` (somente sem MtM).

**DoD.** Todos os fluxos da §5.2.3 cobertos por feature tests; teste de **redução concorrente**
demonstra o lock; respostas de erro com status corretos (404/409/422).

---

## Fase 6 — Motor MtM (Parte 8)

**Objetivo.** Processamento diário idempotente, polimórfico e auditável.

**Entregáveis**
- `MotorMtm` (laço de cálculo) + `ServicoMotor` (orquestração/auditoria); DTOs
  `ResultadoProcessamento`/`RegistroMtm`/`ResumoExecucao`.
- `ProcessarMotorCommand` (`motor:processar`) + agendamento `routes/console.php`.
- API §5.2.4 + Livewire `/motor`.

**Tarefas**
- [ ] Itera `Posicao` ABERTA (eager loading) e chama `calcularMtm()` — **sem `if` por tipo**.
- [ ] RN-011 (só ABERTA), RN-012 (preço ausente → falha e continua), RN-015 (conversão BRL; NDF cambial neutra).
- [ ] **Idempotência (RN-013):** `MtmDiario::updateOrCreate([posicao_id,data_calculo],...)`.
- [ ] **Proveniência (D-803):** só sobrescrever `execucao_id`/`processado_em` quando valor
      financeiro muda (`isDirty([...])`).
- [ ] RN-014 (marca VENCIDA só quem teve sucesso ∩ venceu — D-804).
- [ ] `motor_execucao` registra cada execução (auditoria por design); falhas em JSONB.
- [ ] Agendamento `weekdays()` 19:00 (D-806); Livewire injeta `ServicoMotor` (sem self-call HTTP — D-808).

**DoD.** Reprocessar a mesma data **atualiza** (não duplica); posição que vence muda de
status; falhas isoladas não derrubam o lote; feature tests verdes.

---

## Fase 7 — Relatórios (Parte 9)

**Objetivo.** As 4 visões consolidadas da mesa de risco (§5.2.5).

**Entregáveis**
- `ServicoRelatorios` (consultas agregadas via query builder) + read models em `Dados/`.
- API §5.2.5 + Livewire de Relatórios e Dashboard.

**Tarefas**
- [ ] RN-016 (posição aberta + último MtM), RN-017 (P&L diário = Σ `variacao_dia`),
      RN-018 (P&L acumulado = Σ `pl_acumulado`, inclui realizado), RN-019 (exposição = Σ `quantidade × sinal`).
- [ ] Preço médio do FUTURO no relatório reusa o cálculo do Model `Futuro`.
- [ ] Parâmetro `formato=json|csv|pdf` (CSV/PDF podem ser exportadores; CSV reaproveita endurecimento da Fase 4).

**DoD.** Cada relatório bate com cálculo manual em planilha (cenário de aceite); feature tests verdes.

---

## Fase 8 — Seed & dados de demonstração

**Objetivo.** Prover dados realistas e reproduzíveis para desenvolvimento, demonstração e
UAT, exercitando o fluxo completo (produto → preço → posição → movimentação → motor → MtM).
Separa claramente **factories** (apoio a teste) de **seeders de demonstração** (dataset rico).

**Entregáveis**
- Factories Eloquent de todas as entidades (reutilizáveis pelos testes da Fase 9).
- `DemoSeeder` idempotente que monta um portfólio de demonstração com histórico de MtM.
- Comando/atalho de carga: `php artisan migrate:fresh --seed` e/ou `db:seed --class=DemoSeeder`.

**Tarefas**
- [ ] Factories: `Produto`, `PrecoReferencia`, `Posicao` (+ filhas por instrumento),
      `Movimentacao`, `Perna`, `Usuario` — com estados (ex.: `futuroComprado`, `straddle`).
- [ ] **Produtos de demo:** ≥ 5 commodities (ex.: Soja CBOT, Milho B3, Café ICE, Boi B3,
      Açúcar ICE) + o "produto câmbio" Dólar USD/BRL (`moeda_cotacao = 'BRL'`, `cambio_brl = 1`)
      para NDF cambial (§1.4 / RN-015).
- [ ] **Preços de demo:** série de fechamento + câmbio para **vários dias úteis** (ex.: ~30
      pregões), permitindo histórico de MtM e gráficos de P&L.
- [ ] **Posições de demo (mistas — cobrir todos os tipos e regras):**
  - [ ] FUTURO comprado e FUTURO vendido, com `ABERTURA` automática (RN-020).
  - [ ] FUTURO com **aumento** e **redução** (exercita PM ponderado RN-021, realizado RN-023);
        um FUTURO com **redução total** que encerra a posição (RN-022).
  - [ ] NDF cambial usando o produto Dólar (resultado já em BRL — RN-015).
  - [ ] OPÇÃO simples (1 perna) e **estruturas multi-perna**: straddle e bull call spread
        (RN-004a..e), com perna comprada e vendida.
  - [ ] OTC com indexador correspondente a um produto cadastrado (RN-006).
  - [ ] Ao menos uma posição **VENCIDA** e uma **ENCERRADA** para popular relatórios/estados.
- [ ] **Usuários de demo:** um por perfil (OPERADOR/GESTOR/ADMIN) com senha conhecida de
      demonstração (apenas ambiente não-produtivo).
- [ ] **Gerar MtM histórico:** após semear posições/preços, rodar o motor para a faixa de
      datas (loop de `Motor::processar` por pregão) para preencher `mtm_diario` e
      `motor_execucao` — relatórios e dashboard já abrem com dados.
- [ ] **Idempotência do seeder:** reexecutar não duplica (usar `updateOrCreate`/chaves
      naturais), espelhando a idempotência do próprio motor (RN-013).
- [ ] **Guarda de ambiente:** `DemoSeeder` recusa rodar com `APP_ENV=production` (evita
      vazar credenciais/dados fictícios em produção — reforço da Fase 11).

**DoD.** `php artisan migrate:fresh --seed` em base limpa produz um portfólio navegável:
todas as telas (posições, motor, relatórios, dashboard) abrem com dados coerentes; rodar o
seeder duas vezes não duplica registros; os números do dataset conferem com cálculo manual
de pelo menos um caso por instrumento.

---

## Fase 9 — Testes de integração (ponta a ponta)

**Objetivo.** Validar os módulos juntos com **banco real** (substitui os *fakes* de
contrato, que deixam de existir no fat model — serviços usam Eloquent direto).

**Escopo (requisitos §8.2)**
- Fluxo completo: produto → preço → posição → motor → relatório.
- Upload CSV com linhas válidas e inválidas.
- Reprocessamento do motor (atualiza, não duplica).
- Posição que vence no dia muda de status.
- Movimentação de futuro: aumento+redução; conferir `pl_acumulado = mtm_valor + realizado`.
- Redução total encerra e o motor deixa de processá-la; redução excedente → 422.

**Tarefas**
- [ ] `RefreshDatabase` com **SQLite in-memory** para o rápido e **PostgreSQL** para
      `NUMERIC`/índice parcial/JSONB (D-MVC-3).
- [ ] Reaproveitar as **factories da Fase 8** (testes montam seu próprio estado mínimo, sem depender do `DemoSeeder`).
- [ ] Teste de fluxo diário completo (cenário §6.2).
- [ ] Cobertura da camada de aplicação **≥ 70%** (§8.4).

**DoD.** Suíte de integração verde nos dois bancos relevantes; cobertura de aplicação ≥ 70%;
total do projeto ≥ 75%.

---

## Fase 10 — RBAC & Autenticação (Parte 10)

**Objetivo.** Controle de acesso por perfil sobre a estrutura já pronta.

**Entregáveis**
- Sanctum: sessão (web Livewire) + tokens (API). Policies/Gates por perfil.

**Tarefas**
- [ ] Login + hashing bcrypt/argon2id; usuários e perfis (OPERADOR/GESTOR/ADMIN).
- [ ] Policies (§9.2): OPERADOR (preços, posições, motor, relatórios); GESTOR (+ remoção de posições);
      ADMIN (+ usuários e produtos).
- [ ] Cookies de sessão `HttpOnly`/`Secure`/`SameSite` + CSRF; tokens de API curtos e revogáveis.
- [ ] Logs de auditoria para operações de escrita.

**DoD.** Cada endpoint/tela exige o perfil correto (testes de autorização cobrindo allow/deny por perfil).

---

## Fase 11 — Segurança (fase de verificação e endurecimento)

**Objetivo.** Checar e tomar medidas de segurança antes de considerar o produto pronto —
gate obrigatório. Consolida o que foi embutido nas fases anteriores e fecha lacunas.

**Tarefas — verificação**
- [ ] Revisão de segurança do diff (ex.: `/security-review`) e checklist OWASP Top 10.
- [ ] Confirmar anti-formula-injection no CSV (CWE-1236) e validação de uploads (tipo/tamanho/linhas).
- [ ] AuthZ: varrer rotas/endpoints sem Policy; testar escalonamento de perfil.
- [ ] Sanctum: expiração/revogação de token; flags de cookie; proteção CSRF nas rotas web.
- [ ] Injeção: garantir uso de query builder/bindings (sem SQL cru); validar entradas.
- [ ] Segredos fora do versionamento; `APP_DEBUG=false` em produção; CORS restrito.
- [ ] Dependências: `composer audit` sem vulnerabilidades conhecidas.
- [ ] Mensagens de erro não vazam stack/segredos (envelope §5.1).
- [ ] Rate limiting nas rotas sensíveis (login, motor, upload).

**Tarefas — medidas**
- [ ] Cabeçalhos de segurança (HSTS, X-Content-Type-Options, etc.).
- [ ] Política de senha + lockout/throttle de login.
- [ ] Registrar achados e correções; reteste após correção.

**DoD.** Revisão de segurança sem itens **altos/críticos** em aberto; testes de autorização
e de injeção de CSV verdes; relatório de segurança anexado.

---

## Fase 12 — Não-funcionais (performance & observabilidade)

**Objetivo.** Atender os requisitos §9.1/§9.4.

**Tarefas**
- [ ] Performance do motor: **1.000 posições < 30 s** (eager loading, evitar N+1, índices),
      usando um dataset volumoso gerado pelas factories da Fase 8.
- [ ] Listagem paginada (50/página) < 500 ms; relatórios de 1 ano < 5 s.
- [ ] Logs estruturados JSON com `request_id`; níveis INFO/WARN/ERROR.
- [ ] Endpoints `/health` e `/metrics` (tempo médio do motor, posições/ciclo, latência).

**DoD.** Teste de carga do motor dentro do alvo; endpoints de observabilidade respondendo;
métricas básicas visíveis.

---

## Fase 13 — Testes de regressão (suíte consolidada + gates)

**Objetivo.** Garantir que mudanças futuras não quebrem comportamento já validado.

**Tarefas**
- [ ] Consolidar unit (Fase 3) + integração (Fase 9) + autorização (Fase 10) numa suíte única.
- [ ] **Baseline de regressão:** fixar resultados de MtM/PM/P&L de um conjunto canônico de
      posições (cenários da §8.3) como *golden values*.
- [ ] Testes de regressão para cada bug corrigido (anexar teste ao fix).
- [ ] **Gates de CI:** suíte completa + cobertura mínima (domínio ≥ 90%, aplicação ≥ 70%,
      total ≥ 75%) bloqueiam merge; PHPStan/Pint obrigatórios.
- [ ] Verificação de idempotência como teste de regressão fixo (rodar motor 2× = mesmo estado).

**DoD.** CI executa a suíte completa em cada PR; quebra de *golden values* falha o build;
cobertura abaixo do alvo bloqueia merge.

---

## Fase 14 — Hardening final & Entrega

**Objetivo.** Operação em produção e documentação alinhada.

**Tarefas**
- [ ] Cron do servidor chamando `php artisan schedule:run` (agendador do motor em produção).
- [ ] Backup diário do banco (retenção ≥ 30 dias) e procedimento de restore testado.
- [ ] Consolidar decisões de arquitetura num doc de decisões novo (fat model; D-MVC-1..3),
      mantendo `requisitos.md` e `CLAUDE.md` coerentes.
- [ ] **UAT** com a mesa de risco (roteiros §8.3): 10 posições mistas conferidas em planilha,
      reprocessamento, validações, histórico de 30 dias, aumentos/reduções com atenção ao sinal.
- [ ] Checklist de release; tag de versão.

**DoD.** UAT aprovado; agendamento e backup operando; documentação coerente com o código; release marcado.

---

## Apêndice — Rastreabilidade RN × Fase

| Grupo de RN | Tema | Fase principal |
|---|---|---|
| RN-001..006 | Cadastro de posições | 5 |
| RN-004a..e | Opções multi-perna | 3 (cálculo) / 5 (cadastro) |
| RN-007..010a | Preços / CSV / exclusão | 4 |
| RN-011..015 | Processamento MtM | 6 |
| RN-016..019 | Relatórios | 7 |
| RN-020..025 | Movimentações de FUTURO | 5 (regra) / 3 (cálculo `replay`) |

> **Nota.** Este documento é o **plano de execução**; não altera regras de negócio,
> modelo de dados nem contratos de API (definidos em `requisitos.md`). Em divergência,
> `requisitos.md` prevalece.
