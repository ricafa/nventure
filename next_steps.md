# Próximos passos — NeverVenture

**Estado atual (2026-06-15):** projeto **Laravel 13.15.0** criado na raiz (`APP_KEY`
gerada, `php artisan --version` OK). Documentos de especificação em **`specs/`**
(`requisitos.md` v1.4, `ARCHITECTURE.md`, `SECURITY.md`, `UNIT-TESTS.md` e os
prompts `spec-*.md`). `CLAUDE.md` permanece na raiz; `mock_telas/` é referência
visual. **Partes 1–8 geradas** (config + migrations + domínio puro + testes
unitários + infraestrutura + **Módulo Preços/Produtos** + **Módulo Motor MtM** +
**Módulo Posições/Movimentações**). A Parte 7 entregou `ServicoPosicoes` +
`ServicoMovimentacoes` (RN-001..006, RN-020..025), API REST §5.2.3 (criação dos 4
tipos, movimentações, encerrar, DELETE) e telas Livewire 5 e 6 (cadastro dinâmico +
listagem/detalhe + modal Movimentar com prévia). Ajuste de domínio D-711
(`Movimentacao.sequencia` + desempate no `replay`). **Suíte unitária com 108 testes
verdes**; os testes de integração da API ficam para a Parte 11 (D-507/D-609/D-809 e
agora o `PosicaoApiTest`).

> Legenda: `[ ]` pendente. Referências a seções (§) e regras (RN-xxx) apontam para
> `specs/requisitos.md`. As **decisões de implementação** (que vão além/interpretam
> a spec) ficam em **`decisions.md`** na raiz, com IDs rastreáveis (`D-1xx`…).

---

## Ordem de geração

> **Vocabulário de trabalho:** vamos nos comunicar por estas **Partes (1–13)** —
> ex.: "gere a Parte 3" ou "Parte Posições". Cada parte é gerável separadamente,
> a partir dos `specs/` correspondentes.

| # | Parte | O que inclui | Depende de | Independência |
|---|---|---|---|---|
| 1 | **Fundação / config** | `.env` PostgreSQL, Sail/`docker-compose`, Sanctum, Pest, Pint/Larastan, CI (GitHub Actions) | — | Alta |
| 2 | **Banco / migrations** | Migrations das tabelas do §3, constraints/índices (incl. `uq_mov_abertura`), casts `decimal:`, factories/seeders | 1 | Alta |
| 3 | **Domínio puro (§4)** | `Posicao`/`Futuro`+`Movimentacao`/`NDF`/`Opcao`+`Perna`/`OTC`, `MotorMtm`, contratos (portas) | — (PHP puro) | **Total** |
| 4 | **Testes unitários do domínio** | Pest, casos do §8.1, meta ≥ 90% (§8.4) | 3 | Alta |
| 5 | **Infraestrutura** | Models Eloquent + `RepositorioPosicoesEloquent` (factory/`match`, §4.5) + binds no Service Provider | 2, 3 | Média |
| 6 | **Módulo Preços** | `ServicoPrecos` + import CSV (§5.2.2/RN-010) + API + telas | 5 | Média |
| 7 | **Módulo Posições** ✅ | `ServicoPosicoes` + `ServicoMovimentacoes` (RN-020..025) + API + telas | 5 | Média |
| 8 | **Módulo Motor MtM** | `ServicoMotor` (idempotência RN-013) + scheduler + API | 5 (+6/7 p/ dados) | Média |
| 9 | **Módulo Relatórios** | `ServicoRelatorios` (posição aberta, P&L, exposição — RN-016..019) + API + telas | 5, 8 | Média |
| 10 | **Autenticação & RBAC** | `usuario`, Sanctum, Gates/Policies (matriz perfil × operação, §9.2) | 1, 5 | Transversal |
| 11 | **Testes de integração** | Feature por módulo (PostgreSQL + `RefreshDatabase`) — `spec-integration-tests.md` | módulos | Média |
| 12 | **Segurança / hardening** | controles do `specs/SECURITY.md` (`$fillable`, headers/CORS, throttle, scanners no CI) | vários | Transversal |
| 13 | **Observabilidade / deploy** | logs JSON (`request_id`/`execucao_id`), scheduler, Docker, backup | vários | Transversal |

**Estratégias de corte:**
- **Horizontal (por camada):** #3 → #2 → #5 → serviços → API → UI.
- **Vertical (por módulo, ponta a ponta):** um módulo inteiro de uma vez — ex.:
  **Posições** = domínio + infra + serviço + API + Livewire + testes.

**Início recomendado:** **Parte 3 (Domínio puro)** + **Parte 4 (testes unitários)** —
onde mora o risco de cálculo — em paralelo com **Parte 1 + Parte 2** (config + schema).
Depois **Parte 5** liga o domínio ao banco e os módulos 6–9 fluem.

---

## 0. Ajustes de documentação (rápidos)

- [ ] Atualizar **"Laravel 11" → "Laravel 13"** nos specs (`specs/ARCHITECTURE.md`,
  `specs/requisitos.md` §2.2, `specs/spec-architecture.md`, `specs/spec-security.md`).
- [ ] Nos prompts `specs/spec-*.md`, trocar *"crie X na raiz do projeto"* por
  *"na pasta `specs/`"* (os documentos agora vivem em `specs/`).
- [ ] (Opcional) Atualizar `CLAUDE.md` para refletir a nova organização
  (`specs/`, stack Laravel) — hoje ainda cita nomes/itens antigos.
- [ ] Gerar o **`INTEGRATION-TESTS.md`** a partir de `specs/spec-integration-tests.md`
  (só o prompt existe; o documento ainda não foi gerado).
- [ ] **Revisar o PHPStan/Larastan (nível 8) juntos** — `php -d memory_limit=512M
  vendor/bin/phpstan analyse` acusa **42 erros pré-existentes** (anteriores à Parte 6)
  a decidir como tratar:
  - `missingType.generics` nos Models (relações `BelongsTo`/`HasOne`/`HasMany` sem
    os genéricos `TRelatedModel, TDeclaringModel`) — Partes 3/5;
  - `RepositorioPosicoesEloquent` (§4.5): `match.unhandled` (faltam casos do `match`
    por instrumento) e `property.nonObject` (acesso a `->futuro/->ndf` em
    `Model|null`) — a **Parte 7** adicionou mais ocorrências do **mesmo padrão** nos
    novos métodos de leitura (`detalhar`/`dadosTipo`/`movimentacoesDetalhe`); tratar tudo
    junto aqui (ver decisão em `decisions.md` → "Nota de evolução — PHPStan na hidratação");
  - `MotorMtm`: `nullsafe.neverNull` em `?->mtmValor`.
  Decidir: anotar os genéricos nas relações, ajustar a hidratação e/ou avaliar
  `treatPhpDocTypesAsCertain: false`. A Parte 6 já passa **limpa** no escopo dela
  (precisa rodar com `memory_limit` elevado — o default de 128M estoura).

## 1. Configuração base

- [ ] Trocar o banco para **PostgreSQL 15+** no `.env` (`DB_CONNECTION=pgsql`, host/
  porta/credenciais) — o default veio SQLite. Necessário para índice único parcial,
  `JSONB` e `NUMERIC` (§3, ADR-005).
- [ ] Subir Postgres local (Laravel **Sail** ou `docker-compose`).
- [ ] Instalar/configurar **Laravel Sanctum** (sessão p/ Livewire + tokens p/ API, §9.2).
- [ ] Adicionar qualidade: **Pint** (já vem), **Larastan/PHPStan**, **pre-commit**.
- [ ] Adicionar **Pest** (`composer require pestphp/pest --dev` + `pest:install`).
- [ ] Pipeline **GitHub Actions**: Pint → PHPStan → Pest → build de assets.

## 2. Banco de dados (§3)

- [ ] Migrations das tabelas do §3.2: `produto`, `preco_referencia`, `posicao` +
  filhas (`posicao_futuro`, `posicao_ndf`, `posicao_opcao`, `posicao_opcao_perna`,
  `posicao_otc`), `posicao_movimentacao`, `mtm_diario`, `motor_execucao`, `usuario`.
- [ ] **Tabelas no singular** (`$table` nos Models) e colunas `snake_case`.
- [ ] Constraints/índices do §3.3 — incluir o **índice único parcial**
  `uq_mov_abertura` via SQL bruto (`DB::statement`, específico do PostgreSQL) e os
  CHECKs (`quantidade >= 0` na mãe; `> 0` na movimentação).
- [ ] Casts `decimal:` para todos os valores financeiros (nunca `float`).
- [ ] Factories + seeders mínimos para dev/testes.

## 3. Domínio em PHP puro (§4 — ADR-003)

- [ ] Criar a estrutura `app/Dominio/{Posicoes,Precos,Motor}` (sem Eloquent).
- [ ] Implementar as classes do §4 (código de referência já em PHP no `requisitos.md`):
  `Posicao` (abstrata, `sinal()`, `plRealizado()`), `Futuro` + `Movimentacao`
  (`replay()`, `precoMedio()`, `quantidadeAtual()`, `plRealizado()`, `calcularMtm()`),
  `NDF`, `Opcao` + `Perna`, `OTC`.
- [ ] `MotorMtm::processarDia()` + `ResultadoProcessamento` (§4.4) — sem `if` por tipo.
- [ ] Contratos/portas em `app/Aplicacao/Contratos`: `RepositorioPosicoes`,
  `RepositorioPrecos`, `RepositorioMtm`, `FontePrecos`.

## 4. Infraestrutura

- [ ] Models Eloquent em `app/Infraestrutura/Models` (mapeando §3).
- [ ] `RepositorioPosicoesEloquent` com **factory/`match`** e eager loading,
  hidratando o domínio (§4.5); demais repositórios.
- [ ] Bind dos contratos → implementações num **Service Provider**.
- [ ] `ImportadorPrecosCsv` (upload, §5.2.2/RN-010).

## 5. Aplicação (serviços)

- [ ] `ServicoPosicoes` e `ServicoMovimentacoes` (RN-020..025: ABERTURA automática,
  aumento/redução, redução total encerra, validações 409/422).
- [ ] `ServicoPrecos` (lançamento + upload CSV), `ServicoMotor` (idempotência RN-013),
  `ServicoRelatorios` (posição aberta, P&L diário/acumulado, exposição líquida).

## 6. API REST (§5)

- [ ] Rotas `/api/v1` (`routes/api.php`) dos endpoints do §5.2 (produtos, preços,
  posições + `movimentacoes`, motor, relatórios).
- [ ] **Form Requests** (validação RNs) + **API Resources** (serialização).
- [ ] Handler central → envelope de erro `{ "erro", "mensagem" }` (§5.1) e status
  `400/401/403/404/409/422`.
- [ ] Paginação (`paginate`, 50/página §9.1) e doc OpenAPI (Scribe/L5-Swagger).

## 7. UI (Blade + Livewire — §6)

- [ ] Telas do inventário §6.1 (login, dashboard, cadastros, listagem, motor,
  relatórios) como componentes Livewire.
- [ ] Modal **Movimentar posição** (§6.4) para futuros.

## 8. Segurança (ver `specs/SECURITY.md`)

- [ ] `APP_DEBUG=false`/`APP_ENV=production` em prod; `APP_KEY` (já setada);
  `SESSION_SECURE_COOKIE`/`SAME_SITE`.
- [ ] **Mass assignment**: `$fillable` explícito (CWE-915).
- [ ] **RBAC** por **Gates/Policies** (matriz perfil × operação, §9.2) + checagem
  BOLA/IDOR.
- [ ] **Throttle** no login; **CORS** (`config/cors.php`) + security headers.
- [ ] Bindings no SQL bruto (§4.5/migrations); validação/anti-formula no upload CSV.
- [ ] No CI: **Larastan/PHPStan**, **Enlightn**, **`composer audit`**, **gitleaks**.

## 9. Testes (§8 / `specs/UNIT-TESTS.md`)

- [ ] **Unidade (Pest)** do domínio — meta **≥ 90%** (§8.4); reusar os exemplos do
  §8.1 (preço médio/realizado RN-021/023, MtM por tipo, motor com *fakes*).
- [ ] **Feature/Integração (Pest + PostgreSQL + `RefreshDatabase`)** — meta **≥ 70%**:
  fluxo completo, hidratação §4.5, constraints, idempotência, movimentações, CSV,
  RBAC/Sanctum, relatórios (ver `specs/spec-integration-tests.md`).
- [ ] (Opcional) **Infection** (mutation) e **Eris** (property-based, RN-024).

## 10. Observabilidade e deploy

- [ ] Logs estruturados JSON (Monolog) com `request_id`/`execucao_id` (§9.4);
  nunca logar segredos.
- [ ] **Scheduler** do motor (`routes/console.php` + `schedule:run`).
- [ ] Docker/Sail para dev/homolog/prod; `migrate --force` no deploy; backup diário
  (§9.3).
