# Spec — Parte 0: Fundação do projeto (Laravel 13)

> **Equivale à Fase 0 do `passos_dev.md`.** Esta é a primeira etapa de desenvolvimento:
> estabelece o ambiente reprodutível, o pipeline de qualidade e o scaffold de
> autenticação **antes** de qualquer regra de negócio.
>
> **Fonte da verdade:** `specs/requisitos.md` (v1.6, negócio/contratos — §/RN; e a
> arquitetura alvo **MVC nativo + *fat model*** em §2.2 e §4). Roteiro de fases:
> `specs/passos_dev.md`.
>
> **Natureza:** documento de **especificação executável** — descreve o que entregar, com
> comandos e arquivos concretos, e os critérios de aceite (DoD). Não altera regras de
> negócio, modelo de dados nem contratos de API.

---

## 0. Decisões desta parte (fixadas)

| # | Tema | Decisão |
|---|---|---|
| **D-001** | Framework | **Laravel 13** (lançado mar/2026). |
| **D-002** | PHP | **PHP 8.3** (piso do Laravel 13; suportado 8.3–8.5 — fixamos 8.3 por compatibilidade). |
| **D-003** | Auth scaffold | **Starter kit oficial Livewire** (Livewire **4** + Tailwind + **Flux UI**), com auth *built-in* via **Laravel Fortify** (sessão) — **não** a variante WorkOS — **adaptado** à tabela `usuario`. |
| **D-004** | CI | **GitHub Actions**, com PostgreSQL de teste como *service*. |
| **D-005** | Banco de teste | Serviço Postgres separado (`postgres_test`, `tmpfs` — efêmero/rápido); testes apontam para ele. |
| **D-006** | Análise estática | PHPStan/Larastan **nível 8** sobre `app/`, **excluindo** `Http/`, `Livewire/`, `Providers/`. |
| **D-007** | Locale | `APP_LOCALE=pt_BR` e `APP_FAKER_LOCALE=pt_BR` (linguagem ubíqua em português). |
| **D-008** | Usuários | Tabela `usuario` (§3.2.10) substitui `users`; login por **`login`** (não e-mail); `senha_hash` com cast `hashed`. |

> As decisões D-005/006/007/008 reproduzem, **em conteúdo**, escolhas válidas de uma
> tentativa anterior (o `decisions.md` legado registrava equivalentes como
> D-101/102/103/201/204). Esse `decisions.md` foi **arquivado em `historic-plans/`** e
> está desatualizado (descreve a arquitetura DDD em camadas, já substituída por MVC fat
> model) — consolidar um doc de decisões novo é tarefa à parte e **não** bloqueia esta parte.

---

## 1. Objetivo e escopo

**Objetivo.** Ter, ao final, um projeto Laravel 13 que **sobe via Docker**, com banco de
desenvolvimento e de teste, **login funcional** contra a tabela `usuario`, e um **pipeline
de qualidade** (estilo + estática + testes) verde local e no CI.

**Dentro do escopo (Parte 0)**
- Projeto Laravel 13 (PHP 8.3) versionado.
- Ambiente Docker: app + PostgreSQL (dev) + PostgreSQL (teste).
- Starter kit Livewire instalado e **adaptado** ao `usuario`/`login`.
- Laravel Sanctum instalado (sessão web; base para tokens de API).
- Tooling: Pest, Pint, PHPStan/Larastan, scripts de Composer.
- CI no GitHub Actions.

**Fora do escopo (outras fases)**
- Migrations das tabelas de domínio do §3 (produto, posicao, mtm_diario, …) → **Fase 1**.
- Pastas MVC fat-model (`app/Models`, `app/Services`, `app/Facades`, …) → **Fase 1**.
- Policies/RBAC por perfil e emissão/uso de tokens de API → **Fase 10**.
- Qualquer regra de negócio (RN-001..025), motor, relatórios → **Fases 4–7**.

---

## 2. Stack alvo e versões

| Camada | Escolha | Observação |
|---|---|---|
| Linguagem | **PHP 8.3** | Laravel 13 suporta 8.3–8.5; fixado 8.3 (D-002). |
| Framework | **Laravel 13** | Esqueleto enxuto (sem `Http/Console Kernel`); `bootstrap/app.php` para middleware/exceções/agendamento. |
| Banco | **PostgreSQL 15+** | Necessário p/ índice único parcial, `NUMERIC`, `JSONB` (Fase 1). |
| UI | **Livewire 4 + Tailwind + Flux UI** | Via starter kit; server-rendered, sem SPA. (Frontend em `resources/views`.) |
| Auth (scaffold) | **Laravel Fortify** | Provê login/registro/reset/verificação/2FA, configurados em `config/fortify.php`. É o que o starter kit usa. |
| Tokens de API | **Laravel Sanctum** | Instalado nesta parte; emissão/uso de tokens só na Fase 10. |
| Testes | **Pest** | `Feature` com banco; `Unit` sem banco. |
| Estilo | **Laravel Pint** | `pint.json` na raiz. |
| Estática | **Larastan/PHPStan** | Nível 8, exclusões D-006. |
| Assets | **Vite** | Empacota CSS/JS/Tailwind. |

> **Disponível, mas fora do MVP:** Laravel AI SDK e suporte nativo a vetores
> (PostgreSQL + pgvector) do Laravel 13 — **não** utilizar nesta entrega.

---

## 3. Pré-requisitos

- Docker + Docker Compose.
- Git.
- (Opcional, para criação local) Laravel Installer (`composer global require laravel/installer`).

---

## 4. Passo a passo

> Comandos `php artisan`/`composer` rodam **no container** (`docker compose exec app …`),
> conforme `CLAUDE.md`. A criação inicial pode ser feita na máquina host e depois
> "dockerizada", ou via container utilitário — documentar o caminho escolhido no PR.

### 4.1 Criar o projeto com o starter kit Livewire

Criar o projeto selecionando o starter kit **Livewire** com **auth built-in** (não WorkOS):

O instalador do Laravel é **interativo** — não há flag `--livewire`. Ele pergunta o
starter kit e o provedor de auth; selecione **Livewire** e **built-in** (Fortify),
**não** WorkOS:

```bash
composer global require laravel/installer   # se ainda não tiver o instalador
laravel new nventure
#   → starter kit:        Livewire
#   → autenticação:       built-in (NÃO WorkOS)
#   → testes:             Pest
cd nventure
npm install && npm run build
composer run dev            # sobe o servidor de desenvolvimento
```

> A variante WorkOS AuthKit é uma opção separada no prompt — **não** selecioná-la (o MVP
> não usa provedor externo). Kits de comunidade usariam `laravel new ... --using=vendor/kit`,
> o que **não** se aplica aqui.

### 4.2 Docker

Criar três artefatos:

- **`Dockerfile`** — base `php:8.3-fpm` (ou `-cli` conforme o runner de dev), com:
  - Extensões: `pdo_pgsql`, `pgsql`, `mbstring`, `bcmath`, `intl`, `zip`.
  - Composer (copiado do `composer:latest`).
  - Node LTS (para Vite/Flux build).
- **`docker-compose.yml`** — serviços:
  - `app` — a aplicação (PHP-FPM + servidor web ou `artisan serve`), monta o código,
    expõe a porta HTTP; depende de `postgres`.
  - `postgres` — PostgreSQL 15+ de **desenvolvimento**, porta **5432**, **volume
    persistente**.
  - `postgres_test` — PostgreSQL 15+ de **teste**, porta **5433**, **`tmpfs`** (sem volume;
    efêmero e rápido) — **D-005**.
  - (Conforme necessidade) serviço/etapa de **Vite** para assets.
- **`.dockerignore`** — excluir `vendor/`, `node_modules/`, `.git/`, `.env`.

### 4.3 Variáveis de ambiente

- **`.env`** (dev): `APP_LOCALE=pt_BR`, `APP_FAKER_LOCALE=pt_BR` (D-007),
  `DB_CONNECTION=pgsql`, `DB_HOST=postgres`, `DB_PORT=5432`, credenciais do dev.
- **`.env.testing`** (ou conexão `pgsql_test` no `config/database.php` + `phpunit.xml`):
  aponta para `postgres_test` (`DB_HOST=postgres_test`, `DB_PORT=5432` interno / `5433`
  externo).
- Refletir no doc/README o comando de teste do `CLAUDE.md`:
  `docker compose exec -e DB_HOST=postgres_test -e DB_PORT=5432 app composer test`.
- `.env.example` atualizado (sem segredos).

### 4.4 Adaptar a autenticação à tabela `usuario`

O starter kit usa **Laravel Fortify** e assume `users`/e-mail/registro. Adaptar ao
§3.2.10 e §9.2:

- **Model de usuário** → `$table = 'usuario'`; `getAuthPassword()` retorna `senha_hash`;
  cast `'senha_hash' => 'hashed'` (D-008); `public $timestamps = false` (a tabela usa
  `criado_em`). Manter o contrato `Authenticatable`.
- **Login por `login`** (não e-mail): ajustar a página/componente Livewire de login
  (`resources/views`) e o *username* de autenticação do Fortify para `login` + senha.
- **Desabilitar no MVP** via `config/fortify.php` (usuários criados por ADMIN — Fase 10):
  remover/comentar `Features::registration()`, `Features::resetPasswords()` e
  `Features::emailVerification()` do array `features`. Avaliar **2FA**
  (`Features::twoFactorAuthentication()`, ligado por padrão): manter como reforço de
  segurança ou desligar para enxugar o MVP — **decisão a registrar no PR**.
- **Criação/validação de usuário:** ajustar `app/Actions/Fortify/CreateNewUser.php` e
  `PasswordValidationRules.php` para o esquema `usuario` (`login`/`nome`/`perfil`,
  sem e-mail obrigatório).
- **`perfil`** (`OPERADOR`/`GESTOR`/`ADMIN`) presente no Model como base do RBAC; as
  **Policies/Gates** por perfil ficam para a **Fase 10**.
- **Rate limiting de login:** o Fortify já expõe o limiter `login` no
  `FortifyServiceProvider` — manter (alinha com a fase de segurança, §9.2 / Fase 11).
- **Sanctum:** instalar e publicar config (`php artisan install:api` ou pacote +
  `vendor:publish`). Nesta parte só a **sessão web** (Fortify) é exercida; tokens de API
  na Fase 10.
- **Migration de `usuario` nesta parte:** a versão definitiva do §3.2.10 (com `perfil`
  CHECK, `criado_em`, etc.) pertence à **Fase 1**. Para o login do scaffold funcionar já
  na Parte 0, criar uma **migration mínima de `usuario`** (colunas `id`, `login` UNIQUE,
  `nome`, `senha_hash`, `perfil`, `ativo`, `criado_em`) — a Fase 1 a consolida/ajusta ao
  §3. Um seeder de dev cria `admin`/`gestor`/`operador` (senha de dev), apenas em ambiente
  não-produtivo.

### 4.5 Qualidade e testes

- **Pest:** `tests/Pest.php` vincula `TestCase` + `RefreshDatabase` **apenas** a `Feature/`;
  `Unit/` roda sem framework/banco (suíte determinística e rápida).
- **Pint:** `pint.json` na raiz (preset Laravel).
- **PHPStan/Larastan:** `phpstan.neon` analisando `app/` no **nível 8**, **excluindo**
  `app/Http/`, `app/Livewire/`, `app/Providers/` (D-006). Revisitar exclusões nas fases
  que materializam essas camadas.
- **Scripts no `composer.json`:**
  - `test` → roda Pest (com env de teste).
  - `pint` → `vendor/bin/pint` (e `pint:test` → `--test`).
  - `stan` → `vendor/bin/phpstan analyse`.

### 4.6 CI — GitHub Actions

`.github/workflows/ci.yml`, disparando em `push` e `pull_request`:

- Runner Ubuntu; **PHP 8.3** (setup-php).
- **Service** `postgres` (15+) para os testes (porta exposta ao job; healthcheck).
- Passos: checkout → cache Composer → `composer install` → `cp .env.example .env` +
  `php artisan key:generate` → **`vendor/bin/pint --test`** → **`vendor/bin/phpstan
  analyse`** → **`php artisan test`/`pest`** apontando para o Postgres do service.

---

## 5. Estrutura esperada após a Parte 0

Esqueleto **padrão do Laravel 13** + Docker + CI + tooling + **login funcional** contra
`usuario`. As pastas da arquitetura MVC fat model — `app/Models` (+ `Concerns/`),
`app/Services` (+ `Dados/`), `app/Facades`, `app/Support/Csv`, `app/Exceptions`,
`app/Policies` — são **materializadas na Fase 1** (`passos_dev.md` Fase 1 e
`requisitos.md` §4). Esta parte **não** as cria.

---

## 6. Arquivos a entregar (checklist)

- [ ] Projeto Laravel 13 (`composer.json`, `artisan`, `app/`, `bootstrap/app.php`, `routes/`).
- [ ] `Dockerfile`, `docker-compose.yml`, `.dockerignore`.
- [ ] `.env.example` e configuração de conexão de teste (`.env.testing`/`phpunit.xml`).
- [ ] Starter kit Livewire instalado (Livewire 4 + Tailwind + Flux) e adaptado a `usuario`/`login`.
- [ ] `config/fortify.php` ajustado (registro/reset/verificação desligados; 2FA conforme decisão) e `app/Actions/Fortify/*` adaptados.
- [ ] Sanctum instalado/configurado (tokens de API só na Fase 10).
- [ ] `tests/Pest.php`, `pint.json`, `phpstan.neon`.
- [ ] Scripts `test`/`pint`/`stan` no `composer.json`.
- [ ] Migration mínima de `usuario` + seeder de dev (admin/gestor/operador).
- [ ] `.github/workflows/ci.yml`.

---

## 7. Definition of Done (critérios de aceite)

1. `docker compose up -d` sobe **`app` + `postgres` + `postgres_test`** (`docker compose ps`
   mostra os três saudáveis).
2. A aplicação responde no browser e a **tela de login do starter kit autentica** um usuário
   da tabela `usuario` por **`login`/senha** (sessão criada).
3. `docker compose exec -e DB_HOST=postgres_test -e DB_PORT=5432 app composer test` roda
   contra o banco de teste e passa.
4. `vendor/bin/pint --test` e `vendor/bin/phpstan analyse` **sem erros**.
5. **CI verde** no GitHub Actions (estilo + estática + testes, com Postgres service).
6. `APP_LOCALE=pt_BR`; em `config/fortify.php`, **registro público, verificação de e-mail
   e reset de senha desabilitados** (`route:list` não exibe `/register`, `/forgot-password`,
   `/email/verify`).

---

## 8. Riscos e pontos a verificar (na implementação)

| Risco | Mitigação / ação |
|---|---|
| Instalador é interativo (sem flag `--livewire`) | Selecionar Livewire + built-in (não WorkOS) no prompt do `laravel new`. **Resolvido** (§4.1). |
| **Flux UI** tem componentes free e **Pro** (pago) | Usar somente componentes free; Flux Pro está **fora do escopo**. |
| Adaptação Fortify `users`/e-mail → `usuario`/`login` deixa rotas/telas órfãs | Desabilitar features em `config/fortify.php`; ajustar `app/Actions/Fortify/*`; varrer rotas/telas do kit e remover o que não é MVP (§4.4). |
| Sanctum convivendo com a sessão do kit | Nesta parte, só sessão; validar que `install:api` não quebra a auth de sessão. Tokens de API → Fase 10. |
| Extensões PHP 8.3 ausentes na imagem | Instalar `pdo_pgsql`, `pgsql`, `bcmath`, `intl`, `mbstring`, `zip` no `Dockerfile`. |
| `decisions.md` legado (DDD), arquivado em `historic-plans/`, confunde implementadores/agentes | Tratar como obsoleto; **não** seguir; consolidar doc de decisões novo à parte. |
| Migration mínima de `usuario` divergir do §3.2.10 | A Fase 1 consolida; manter colunas compatíveis (login, senha_hash, perfil, ativo, criado_em). |

---

## 9. Referências (Laravel 13)

- Release notes: https://laravel.com/docs/13.x/releases
- Starter kits (Livewire; auth, enable/disable features): https://laravel.com/docs/13.x/starter-kits
- Laravel Fortify (auth do starter kit): https://laravel.com/docs/13.x/fortify
- Repositório do starter kit Livewire: https://github.com/laravel/livewire-starter-kit
- Anúncio (PHP 8.3, AI SDK): https://laravel-news.com/laravel-13-released

---

**Fim do documento.** Próxima etapa: **Fase 1 — Esqueleto MVC + Banco de dados**
(`passos_dev.md`), que materializa as pastas fat-model e as migrations do §3.
