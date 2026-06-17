# Arquitetura — NeverVenture (MVP de gestão de risco de mercado)

**Versão:** 3.0 · **Base:** `requisitos.md` v1.4 (fonte da verdade) · **Stack:** PHP 8.2+ / Laravel 11 · **Idioma do código de domínio:** português

> Referência arquitetural para todo o desenvolvimento. Quando uma decisão se
> apoia nos requisitos, a seção (§) ou a regra (RN-xxx) correspondente é citada.
> Pontos não cobertos pelos requisitos aparecem marcados como **Premissa**.
>
> **v3.0 — re-arquitetura para MVC Laravel (fat model).** A v2.0 adotava DDD/
> hexagonal com domínio em PHP puro separado do Eloquent. A v3.0 consolida a
> **Alternativa A (fat model)** planejada em `spec_ddd_to_mvc.md`: o cálculo de
> MtM vive nos **Models Eloquent**; os Services usam Eloquent direto (sem
> Repositórios/Contratos de persistência); **Facades** expõem os Services. O
> **ADR-003 foi revogado** (ver ADR-007). A migração é de **código de aplicação** —
> o esquema do banco (§3.2/§3.3, migrations, constraints e índices) **não muda**.

---

## 1. Visão geral do sistema

**Propósito.** Ferramenta **interna** da mesa de risco para **marcação a mercado
(MtM) diária** de posições em derivativos sobre commodities. Ao fim de cada
pregão, o operador obtém valor justo, variação do dia, P&L acumulado e exposição
líquida por produto (§1.1).

**Usuários-alvo** (perfis de `usuario`, §3.2.10 e §9.2): **OPERADOR**, **GESTOR**,
**ADMIN**.

**Escopo (resumo do §1.2).** Dentro: produtos e preços; posições nos quatro
instrumentos (futuro, NDF, opção multi-perna, OTC); movimentações de futuro com
preço médio ponderado e P&L realizado; motor MtM polimórfico; histórico de MtM;
relatórios; interface web. Fora: feeds em tempo real, gregas, VaR/stress, limites
automatizados, integração ETRM/ERP, físico/estoque, multi-tenant, compliance.

**Contexto.** Sistema interno pós-trade, operado em horário comercial; não exposto
a terceiros.

---

## 2. Stack tecnológica

| Camada | Tecnologia | Por quê |
|---|---|---|
| Linguagem | **PHP 8.2+** | Tipos, enums, `readonly`, `match` e promoção de construtor expressam bem o domínio do §4. |
| Framework | **Laravel 11** | Framework maduro, "baterias inclusas" (roteamento, validação, fila, scheduler, auth), grande comunidade e documentação em português. |
| UI | **Blade + Livewire 3** (+ Alpine.js, Vite/Tailwind) | Telas server-rendered reativas sem SPA, alinhadas ao perfil de ferramenta interna (§6). |
| Validação | **Form Requests / Validator** | Validação declarativa dos contratos (§5) e das RNs de cadastro (§7). |
| ORM | **Eloquent (fat model)** | Mapeia o §3 e **hospeda o cálculo de MtM** (`calcularMtm()`/`plRealizado()`/`replay()`); a subclasse certa é hidratada por `newFromBuilder` polimórfico (§4.5). Sem camada de tradução ORM⇄domínio. |
| Migrations | **Migrations do Laravel** (Schema builder) | Versionamento de esquema reprodutível. |
| Banco | **PostgreSQL 15+** | `NUMERIC` exato, índice único **parcial** (`uq_mov_abertura`), índices parciais (§3.3) e `JSONB` (`motor_execucao.falhas`). |
| Agendador | **Laravel Task Scheduler** | Dispara `processarDia` do motor no fechamento (uma entrada de cron → `schedule:run`). |
| Auth | **Laravel Sanctum** | Sessão para a UI Livewire (cookie + CSRF) e tokens para a API REST (§5). Passport se for exigido OAuth2/JWT estrito (§9.2). |
| Autorização | **Gates/Policies** | RBAC por perfil OPERADOR/GESTOR/ADMIN (§9.2). |
| Testes | **Pest** (sobre PHPUnit) + **Laravel Testing** | Unidade (domínio) e Feature (HTTP/Livewire/DB); atende §8 e §8.4. |
| Qualidade | **Laravel Pint** (PSR-12), **Larastan/PHPStan** (nível alto), **Rector** (opcional) | Padroniza estilo e análise estática. |
| Exec/dev | **Docker** + **Laravel Sail**; **Composer** | Ambiente reprodutível (app + PostgreSQL). |
| CI | **GitHub Actions** | Pint → PHPStan → Pest → build de assets. |

**Alternativas documentadas (§2.2):** Django REST, .NET 8, Node/NestJS — não
adotadas; o projeto padronizou em **Laravel**. Ver **ADR-001/ADR-002**.

---

## 3. Estrutura de pastas

Camadas (apresentação → aplicação → domínio ← infraestrutura) sobre a base do
Laravel, com o **domínio puro isolado** de Eloquent/HTTP.

```
neverventure/
├── app/
│   ├── Dominio/                     # DOMÍNIO PURO — PHP sem Laravel/Eloquent
│   │   ├── Posicoes/                # Posicao, Futuro, Movimentacao, NDF, Opcao, Perna, OTC (§4.2–§4.3)
│   │   ├── Precos/                  # PrecoReferencia
│   │   └── Motor/                   # MotorMtm, ResultadoProcessamento (§4.4)
│   │
│   ├── Aplicacao/                   # CASOS DE USO + CONTRATOS (portas)
│   │   ├── Contratos/               # interfaces: RepositorioPosicoes/Precos/Mtm, FontePrecos
│   │   ├── Posicoes/                # ServicoPosicoes, ServicoMovimentacoes (RN-020..025)
│   │   ├── Precos/                  # ServicoPrecos (upload CSV, RN-010)
│   │   ├── Motor/                   # ServicoMotor
│   │   └── Relatorios/              # ServicoRelatorios
│   │
│   ├── Infraestrutura/             # IMPLEMENTA OS CONTRATOS
│   │   ├── Models/                  # Eloquent: PosicaoModel, PosicaoFuturoModel, MovimentacaoModel…
│   │   ├── Repositorios/            # RepositorioPosicoesEloquent (factory/match, §4.5)
│   │   └── Csv/                     # ImportadorPrecosCsv
│   │
│   ├── Http/                       # APRESENTAÇÃO — API REST
│   │   ├── Controllers/Api/         # Produto, Preco, Posicao, Movimentacao, Motor, Relatorio
│   │   ├── Requests/                # Form Requests (validação, §5/§7)
│   │   ├── Resources/               # API Resources (serialização, §5.1)
│   │   └── Middleware/
│   │
│   ├── Livewire/                   # APRESENTAÇÃO — UI (componentes Livewire)
│   │   ├── Posicoes/                # ListaPosicoes, FormPosicao, DetalhePosicao, ModalMovimentar (§6.4)
│   │   ├── Precos/  Motor/  Relatorios/
│   │
│   ├── Policies/                   # autorização por perfil (§9.2)
│   └── Providers/                  # bind Contrato→Implementação; registro do schedule
│
├── routes/
│   ├── api.php                      # API REST /api/v1 (Sanctum)
│   ├── web.php                      # rotas Livewire/Blade
│   └── console.php                  # agendamento do motor
├── database/
│   ├── migrations/                  # esquema do §3 (+ constraints/índices §3.3)
│   ├── factories/                   # dados de teste
│   └── seeders/
├── resources/views/                 # Blade (layouts, componentes, telas Livewire)
├── tests/
│   ├── Unit/Dominio/                # Pest — domínio puro (§8.1)
│   ├── Feature/                     # Pest — HTTP/Livewire/DB (§8.2)
│   └── Pest.php
├── config/
├── docker-compose.yml               # (ou Laravel Sail) app + PostgreSQL
├── composer.json
├── requisitos.md                    # especificação (fonte da verdade)
└── ARCHITECTURE.md                  # este documento
```

O `mock_telas/` existente serve de **referência visual** para as telas Blade e não
faz parte do build.

---

## 4. Padrões arquiteturais

**Camadas + dependências para dentro.** `Dominio` não conhece Laravel. `Aplicacao`
depende de `Dominio` e dos **Contratos** (portas). `Http`/`Livewire` e
`Infraestrutura` dependem de `Aplicacao`; a infraestrutura **implementa** os
contratos (inversão de dependência, ligada num Service Provider).

```
   Http (Controllers/Resources)   Livewire (componentes)      Infraestrutura
                 │ usa                    │ usa                 │ implementa
                 ▼                        ▼                     ▼
              Aplicação (serviços)  ──── define ────►  Contratos/ (portas)
                 │ usa
                 ▼
              Domínio (Posicao, Futuro…, MotorMtm) — PHP puro, sem Eloquent
```

**Padrões obrigatórios (fixados pelos requisitos):**

- **(a) Polimorfismo sobre condicionais** — o `MotorMtm` não tem `if` por tipo;
  cada subclasse de `Posicao` implementa `calcularMtm()` (§2.3, §4.4). `plRealizado()`
  é método na base (retorna 0) e sobrescrito por `Futuro` (§4.2, §4.3.1).
- **(b) Aberto/fechado** — novo instrumento = nova subclasse de `Posicao` + ramo no
  `match` da hidratação; o motor não muda (§4.4).
- **(c) Repository + Factory** — `RepositorioPosicoesEloquent` consulta via Eloquent
  (com eager loading) e **hidrata** o objeto de domínio certo num `match` por
  `instrumento` (§4.5). Único ponto onde o tipo importa.
- **(d) Idempotência** — `ServicoMotor`/`MotorMtm` fazem **UPSERT** (`updateOrCreate`)
  por (`posicao_id`, `data_calculo`) — RN-013, constraint do §3.2.8.
- **(e) Auditoria por design** — `motor_execucao` registra cada execução;
  movimentações são **imutáveis** (RN-025); colunas `criado_por`/`criado_em`.

**Domínio separado do ORM.** As classes do §4 são PHP puro (sem herdar de
`Model`). Isso preserva o polimorfismo e mantém regras testáveis sem banco; os
`Model` Eloquent vivem em `Infraestrutura/Models` e só aparecem nos repositórios.

---

## 5. Convenções de código

- **Idioma.** Identificadores de **domínio em português** (linguagem ubíqua do
  `requisitos.md`): `Posicao`, `calcularMtm()`, `precoMedio()`, `Movimentacao`.
  Comentários, docstrings (PHPDoc) e mensagens ao usuário em português. Termos
  consagrados sem tradução (MtM, strike, JWT).
- **Estilo.** **PSR-12** via **Laravel Pint**; classes `PascalCase`, métodos/
  variáveis `camelCase`, constantes `MAIÚSCULAS`. Colunas do banco em `snake_case`
  (Eloquent faz o mapeamento). Tipagem estrita (`declare(strict_types=1)`),
  validada por **Larastan/PHPStan**.
- **Commits.** **Conventional Commits** (`feat:`, `fix:`, `refactor:`, `test:`…).
- **PHPDoc** nas regras de cálculo, citando a RN/§ (como em `Futuro::replay()`).

---

## 6. Estratégia de testes

| Tipo | Framework | Foco |
|---|---|---|
| Unidade (domínio) | **Pest** | `calcularMtm()` por tipo × comprado/vendido; `sinal()`; estruturas multi-perna; preço médio e P&L realizado de `Futuro` (RN-021/RN-023); idempotência (§8.1). |
| Feature/Integração | **Pest** + `RefreshDatabase` + PostgreSQL | Fluxo via API/Livewire→serviços→Eloquent→banco; upload CSV; reprocessamento; movimentações; encerramento (§8.2). |
| E2E (opcional) | Laravel Dusk | Caminho crítico nas telas Blade/Livewire (§6). |
| Aceitação (UAT) | roteiros manuais (§8.3) | Conferência com a mesa de risco. |

**Metas de cobertura (§8.4):** domínio **≥ 90%**, aplicação **≥ 70%**, total
**≥ 75%** — medidas por Pest/Xdebug (ou PCOV) no CI.

---

## 7. API e contratos

- **Base/estilo.** REST sob `/api/v1` (rotas em `routes/api.php`); JSON; datas
  ISO-8601. Documentação **OpenAPI/Swagger** via **Scribe** (ou L5-Swagger).
- **Autenticação/autorização.** **Sanctum**: sessão (cookie + CSRF) para a UI
  Livewire; tokens para a API. **RBAC** por perfil via **Gates/Policies** e
  middleware nas rotas de escrita (§9.2) — ex.: remover posição exige GESTOR;
  produtos/usuários exigem ADMIN.
- **Validação/serialização.** **Form Requests** validam entrada (RNs de §7);
  **API Resources** serializam a saída.
- **Respostas.** Recurso/coleção em JSON (chaves `snake_case`, como nos exemplos do
  §5); criação `201`; ações sem corpo `204`; registrar movimentação retorna o
  **estado recalculado** da posição (§5.2.3).
- **Erros.** Handler central (`bootstrap/app.php` → `withExceptions`) traduz
  exceções para o envelope `{ "erro", "mensagem" }` (§5.1) com `400/401/403/404/
  409/422` (incl. RN-010a `409`, RN-022/RN-025 `422`).
- **Paginação.** `paginate()` do Eloquent (50/página, §9.1) com metadados.

---

## 8. Banco de dados

- **Modelo.** Tabelas do §3.2 e relacionamentos do §3.1 (cada `posicao` com uma
  filha por `instrumento`).
- **Migrations.** Laravel Schema builder; a migração inicial materializa §3.2 +
  constraints + índices do §3.3 (inclui o índice único **parcial**
  `uq_mov_abertura` via `whereRaw`/SQL bruto na migration, pois é específico do
  PostgreSQL).
- **Nomenclatura.** `snake_case`; **tabelas no singular** (`posicao`,
  `preco_referencia`…) — definir `$table` nos Models, já que o default do Eloquent
  é plural. PK `id`; FKs `<tabela>_id`; `NUMERIC`/`decimal` (nunca `float`) para
  valores financeiros (casts `decimal:` nos Models).
- **Soft vs hard delete.**
  - `produto`: **soft** via flag `ativo` (§5.2.1).
  - `preco_referencia`: **bloqueio** se referenciado por `mtm_diario` → `409`
    (RN-010a).
  - `posicao`: `DELETE` só sem MtM; encerramento é mudança de `status`.
  - `posicao_movimentacao`: **imutável** (RN-025); some apenas por cascata.
  - Cascatas via `onDelete('cascade')` nas filhas.

---

## 9. Tratamento de erros e logs

- **Exceções de domínio/aplicação** mapeadas para HTTP no handler central:
  `ErroValidacao` (`422`), `ErroConflito` (`409`), `ErroNaoEncontrado` (`404`),
  `ErroAutorizacao` (`403`); não previstas → `500` genérico (sem stack trace ao
  cliente; detalhe no log).
- **Logs estruturados em JSON** (Monolog, canal configurado) com `nivel`,
  `request_id` (middleware) e `execucao_id` nas operações do motor (correlaciona
  com `motor_execucao.falhas`, §9.4).
- **Auditoria.** Escrita registra autor/momento (`criado_por`/`criado_em`,
  `disparado_por`).
- **Nunca logar:** `senha_hash`, tokens Sanctum, cookies de sessão, cabeçalhos de
  `Authorization`.

---

## 10. Build, deploy e ambientes

- **Ambientes.** `dev`/`homolog`/`prod`; configuração por **`.env`** (12-factor);
  segredos fora do versionamento.
- **Local.** **Laravel Sail** (`./vendor/bin/sail up`) sobe app + PostgreSQL;
  `php artisan migrate --seed` aplica esquema e seeds; `npm run dev` (Vite) para
  assets Blade/Livewire.
- **Build.** Imagem Docker (PHP-FPM + Nginx); `composer install --no-dev`,
  `php artisan config:cache route:cache view:cache`, `npm run build`.
- **Deploy.** Containers dev→homolog→prod; `php artisan migrate --force` no deploy.
- **Agendamento/filas.** `schedule:run` (cron) dispara o motor no fechamento;
  worker de fila opcional para processamento assíncrono.
- **Metas operacionais (§9.1/§9.3).** Motor: 1.000 posições < 30 s; listagem
  paginada < 500 ms; relatórios de até 1 ano < 5 s. SLA 99% em horário comercial;
  **backup diário** com retenção ≥ 30 dias.

---

## 11. Dependências externas

No MVP o sistema é praticamente **autocontido** (feeds e integrações estão fora do
escopo, §1.2). Integrações futuras ficam atrás de **contratos** (portas) ligados
num Service Provider:

- **Ingestão de preços** — contrato `FontePrecos`. Adaptador único hoje: **upload
  CSV** (§5.2.2). Na **Fase 4** (§10), adaptadores Bloomberg/Refinitiv/B3
  implementam o mesmo contrato.
- **Notificações (e-mail)** — `Mail` do Laravel (porta opcional para avisos de
  conclusão/falha do motor). **Premissa:** sem provedor definido no MVP.
- Persistência (PostgreSQL) e autenticação (Sanctum) são infraestrutura, não
  integrações externas. Sem PNCP/ERP/ETRM no MVP.

---

## 12. Decisões registradas (ADRs)

### ADR-001 — Motor de MtM polimórfico, sem condicional por tipo
- **Contexto.** O cálculo varia por instrumento e novos tipos surgirão (§4, §10).
- **Decisão.** Cada subclasse de `Posicao` implementa `calcularMtm()`; o motor
  itera sem `if` por tipo (§2.3, §4.4). `plRealizado()` segue o mesmo princípio.
- **Consequências.** + Aberto/fechado; motor estável. − Concentra o "switch" no
  `match` da hidratação (§4.5).

### ADR-002 — Laravel 11 como framework
- **Contexto.** O §2.2 admite vários frameworks.
- **Decisão.** PHP 8.2+ / Laravel 11 (com Eloquent, Sanctum, scheduler, validação).
- **Consequências.** + Produtividade e ecossistema; docs em português. − Convenções
  "plural/ActiveRecord" do Eloquent exigem ajustes (tabelas no singular; domínio
  separado do ORM — ADR-003).

### ADR-003 — Domínio em PHP puro, separado do Eloquent
- **Contexto.** O §4 define um domínio polimórfico; o Eloquent é ActiveRecord.
- **Decisão.** Classes de domínio puras (sem herdar `Model`); Models Eloquent na
  infraestrutura, traduzidos nos repositórios (§4.5).
- **Consequências.** + Domínio testável sem banco; polimorfismo preservado.
  − Camada de tradução ORM ⇄ domínio (custo assumido).

### ADR-004 — Frontend Blade + Livewire (sem SPA)
- **Contexto.** Ferramenta interna com telas CRUD/relatórios (§6).
- **Decisão.** Blade + Livewire 3 (+ Alpine), server-rendered; a API REST (§5)
  permanece para acesso programático.
- **Consequências.** + Menos complexidade que SPA; CSRF/escape nativos.
  − Interações muito ricas dependem de Livewire/Alpine.

### ADR-005 — PostgreSQL + Eloquent + Migrations
- **Contexto.** Valores exatos, índice único parcial e `JSONB` (§3).
- **Decisão.** PostgreSQL; Eloquent + Migrations; SQL bruto pontual para o índice
  parcial.
- **Consequências.** + `NUMERIC` exato, UPSERT, índices parciais. − Um trecho de
  migration específico do PostgreSQL.

### ADR-006 — Idioma de domínio em português
- **Contexto.** Toda a especificação usa termos em português.
- **Decisão.** Identificadores de domínio e mensagens ao usuário em português;
  termos técnicos consagrados mantidos.
- **Consequências.** + Mapeamento 1:1 com os requisitos. − Mistura PT/EN com APIs
  de terceiros (aceitável).

---

**Fim do documento.**
