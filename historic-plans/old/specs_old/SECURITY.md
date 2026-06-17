# Revisão de segurança — NeverVenture (MVP de gestão de risco de mercado)

**Versão:** 2.0 · **Data:** 2026-06-13 · **Bases:** `requisitos.md` v1.4 e `ARCHITECTURE.md` v2.0
**Stack:** PHP 8.2+ / Laravel 11 · **Alvo:** OWASP ASVS nível 2 · **Refs:** OWASP Top 10 (2021), OWASP API Security Top 10 (2023), CWE

> **Como ler os status.** O sistema está em fase de especificação/mocks (sem código
> de produção). O **status** indica se o controle já é **exigido pelos requisitos/
> arquitetura** — não que um código foi testado: **Atende** = exigido e
> especificado; **Parcial** = mencionado, porém subespecificado; **Lacuna** = não
> endereçado. **Recomendação**/**Premissa** vão além do que os requisitos exigem.

---

## 1. Visão geral e escopo da revisão

**Objetivo.** Avaliar a postura de segurança e definir os controles obrigatórios na
implementação Laravel, alinhados ao ASVS nível 2 e ao OWASP API Security Top 10.

**Escopo:** backend Laravel (API + Livewire), banco PostgreSQL, infraestrutura/
contêineres (Docker/Sail) e CI (GitHub Actions).

**Fora de escopo:** segurança física, segurança de rede além do contêiner e itens
de roadmap (feeds/integrações — §10).

**Classificação de sensibilidade:**

| Dado | Classe | Observação |
|---|---|---|
| `senha_hash`, tokens Sanctum, cookies de sessão, `APP_KEY` | **Crítico** | Nunca expor/logar; CWE-532, CWE-798. |
| Posições, `mtm_diario`, P&L, exposição | **Confidencial de negócio** | Informação sensível da mesa. |
| Preços de referência, produtos | **Interno** | Integridade alimenta o MtM. |
| Usuário (login, nome, perfil) | **Interno/PII leve** | LGPD. |

---

## 2. Modelo de ameaças

**Ativos:** credenciais/tokens/sessões; integridade dos valores financeiros (preços
→ MtM → P&L); confidencialidade das posições; trilha de auditoria; disponibilidade
do motor.

**Atores/ameaças:** (a) usuário interno abusando de privilégios; (b) conta
comprometida; (c) atacante na rede interna; (d) dependência/imagem vulnerável; (e)
segredo vazado (`.env`/`APP_KEY`).

**Fronteiras de confiança e superfícies:**

```
[Navegador] --HTTPS--> (TB1) [Laravel: Livewire (web, sessão+CSRF) | API /api/v1 (Sanctum)]
                                  │ (TB2: authN Sanctum + authZ Gates/Policies)
        ┌─────────────────────────┼──────────────────────────┐
        ▼                         ▼                            ▼
  [Upload CSV de preços]   [Rotas REST §5.2]            [/up health, telescope?]
        │                         │  (TB3: borda de dados)
        ▼                         ▼
                          [Eloquent/repos] --> [PostgreSQL]
                                  ▲
                          [Scheduler] dispara o motor
```

**STRIDE (resumo):**

| Componente | Ameaças | Mitigação-chave |
|---|---|---|
| Login/Sanctum | Spoofing, Elevation | Hash forte, throttling, sessão segura/token revogável (§9.2) |
| API/Livewire | Tampering, Elevation, Info disclosure | Gates/Policies, Form Requests, Blade auto-escape, erros sem vazamento |
| Eloquent/Repos (§4.5) | Tampering (SQLi, CWE-89) | Query builder parametrizado; SQL bruto só com bindings |
| Upload CSV (§5.2.2) | Tampering (formula injection CWE-1236), DoS | Validação de conteúdo, limites de tamanho/linhas |
| Mass assignment | Tampering (CWE-915) | `$fillable` explícito; DTO/Form Request, nunca `Model::create($request->all())` |
| Logs/auditoria | Repudiation, Info disclosure (CWE-532) | Trilha imutável; não logar segredos; `APP_DEBUG=false` |
| Config/segredos | Info disclosure (CWE-798) | `.env` fora do repo; gitleaks; `APP_KEY` setado |

---

## 3. Autenticação

- **Hashing de senha** — **Atende** (§9.2). **Recomendação:** driver **argon2id**
  (`config/hashing.php`) ou bcrypt custo ≥ 12; nunca texto plano (CWE-256).
- **Sessão (UI Livewire)** — **Atende parcial.** Guard `web` do Laravel: cookies
  `HttpOnly`, **`SESSION_SECURE_COOKIE=true`**, **`SESSION_SAME_SITE=lax|strict`**,
  rotação de sessão no login (`Auth::login` regenera), CSRF nativo.
- **API (tokens Sanctum)** — **Atende.** Tokens revogáveis com expiração
  (`config/sanctum.php` / `personal_access_tokens`); enviar via `Authorization:
  Bearer`. (Para OAuth2/JWT estrito → Passport, §9.2.)
- **Política de senha** — **Lacuna.** **Recomendação:** regra `Password::min(12)
  ->uncompromised()` (checagem contra vazamentos), sem expiração forçada.
- **Força bruta / credential stuffing (API4)** — **Parcial.** Aplicar o
  **`throttle`** do Laravel no login (ex.: `throttle:5,1`) + `RateLimiter`; logar
  tentativas falhas.

---

## 4. Autorização (RBAC)

Enforcement **server-side** via **Gates/Policies** e middleware `can:` em **toda**
rota de escrita (menor privilégio). Não confiar na UI. **Matriz perfil × operação**
(de §9.2 e §5.2):

| Operação (endpoint §5.2) | OPERADOR | GESTOR | ADMIN |
|---|:---:|:---:|:---:|
| Ver relatórios / listagens (GET) | ✅ | ✅ | ✅ |
| Lançar preços / upload CSV | ✅ | ✅ | ✅ |
| Remover preço (`DELETE /precos/{id}`) | ✅¹ | ✅ | ✅ |
| Criar posição / movimentação / encerrar | ✅ | ✅ | ✅ |
| **Remover** posição (`DELETE /posicoes/{id}`) | ❌ | ✅ | ✅ |
| Disparar motor | ✅ | ✅ | ✅ |
| Cadastrar/editar **produtos** | ❌ | ❌ | ✅ |
| Cadastrar **usuários** | ❌ | ❌ | ✅ |

¹ Remoção de preço já é bloqueada se houver MtM associado (RN-010a, `409`).

- **BOLA/IDOR (API1)** — **Lacuna a cobrir:** Policies que validem o objeto em cada
  acesso por `id`.
- **Segregação de funções** — registrar quem dispara o motor vs. quem remove
  (trilha §9.2).

---

## 5. Validação de entrada e segurança da API/UI

- **Validação** — **Atende** via **Form Requests** (rules para RN-001, enums de
  `tipo`, etc.), retornando `422` no envelope `{erro,mensagem}` (§5.1).
- **Injeção SQL (CWE-89, API8)** — **Atende com ressalva.** Eloquent/query builder
  parametriza; o **SQL bruto** (índice parcial na migration; qualquer
  `DB::select`/`whereRaw`) deve usar **bindings**, nunca interpolação.
- **Mass assignment (CWE-915, API6)** — **Lacuna a cobrir (Laravel-específico):**
  definir `$fillable` explícito nos Models; preencher a partir do Form Request
  validado, nunca `Model::create($request->all())`.
- **XSS (CWE-79)** — **Atende com ressalva:** Blade escapa por padrão (`{{ }}`);
  evitar `{!! !!}` com dado de usuário; cuidado com bindings de Alpine/Livewire.
- **Upload de CSV (§5.2.2, RN-010)** — **Parcial.** Validar `file|mimes:csv,txt`,
  **limite de tamanho/linhas**, e sanitizar **formula injection** (células iniciadas
  por `= + - @`); relatório de aceitas/rejeitadas sem abortar o lote.
- **CSRF** — **Atende:** nativo do Laravel para web/Livewire; API stateless com
  token.
- **Rate limiting (API4)** — **Lacuna:** `RateLimiter`/`throttle` por usuário/IP em
  login, `/precos/upload` e `/motor/processar`.
- **CORS / headers** — **Recomendação:** `config/cors.php` restrito à origem;
  middleware de security headers (HSTS, `X-Content-Type-Options`, CSP).
- **Erros** — **Atende:** handler central no envelope `{erro,mensagem}`; **`APP_DEBUG=false`**
  em produção (senão vaza stack trace/env — CWE-489).

---

## 6. Proteção de dados

- **Em trânsito** — **Recomendação:** TLS 1.2+, redirecionar HTTP→HTTPS,
  `SESSION_SECURE_COOKIE=true`.
- **Em repouso** — **Recomendação:** criptografia de disco/volume e backups;
  `Crypt`/casts `encrypted` para campos sensíveis se necessário.
- **Segredos (CWE-798)** — **Atende parcial:** `.env` fora do repo; **`APP_KEY`**
  presente; usar `config()` (não `env()`) fora de `config/`; validar com **gitleaks**.
- **Dados sensíveis em logs (CWE-532)** — **Atende** (regra do `ARCHITECTURE.md`
  §9): nunca logar `senha_hash`, tokens, cookies; cuidado com `APP_DEBUG`.
- **Backup** — **Atende** (§9.3: diário, ≥ 30 dias). **Recomendação:** criptografar
  e testar restauração.

---

## 7. Integridade, auditoria e não-repúdio

- **Imutabilidade das movimentações** — **Atende** (RN-025): sem update/delete.
- **Trilha de auditoria** — **Atende** (§9.2): `criado_por`/`criado_em`,
  `disparado_por`/`motor_execucao` (§3.2.9). **Recomendação:** trilha append-only.
- **Idempotência** — **Atende** (RN-013): `updateOrCreate` por (`posicao_id`,
  `data_calculo`).
- **Integridade numérica** — **Atende:** colunas `NUMERIC` + casts `decimal:` nos
  Models (nunca `float` no banco); invariante RN-024 em transação (`DB::transaction`).

---

## 8. Dependências e cadeia de suprimentos

- **SCA** — **Lacuna a implementar:** **`composer audit`** (e `npm audit` para os
  assets) no CI; falhar em vulnerabilidade alta/crítica.
- **Pinning** — `composer.lock`/`package-lock.json` versionados.
- **Imagens** — base PHP-FPM mínima, escaneadas com **Trivy**; rebuild periódico.
- **SBOM** — **Recomendação:** CycloneDX no pipeline.
- **Política** — janela de correção por severidade.

---

## 9. Configuração e hardening

- **App** — **`APP_DEBUG=false`** e `APP_ENV=production` em prod; `APP_KEY` setado;
  `php artisan config:cache` sem segredos logados.
- **PostgreSQL** — **Recomendação:** usuário de app com **menor privilégio** (sem
  superuser); rede restrita; senha via secret.
- **Contêiner** — usuário **não-root**; FS somente leitura quando possível.
- **Endpoints operacionais** — `/up` (health do Laravel 11) sem dados sensíveis;
  **Telescope/Horizon** (se usados) **protegidos por gate** e desabilitados em prod;
  `/metrics` autenticado.
- **Docs OpenAPI** — avaliar restringir Scribe/Swagger fora da rede interna.

---

## 10. Logging, detecção e resposta a incidentes

- **Eventos de segurança** — **Parcial:** login com falha, lockout, troca de perfil,
  remoções, falhas do motor.
- **Formato/correlação** — **Atende** (§9.4): Monolog JSON, `request_id`,
  `execucao_id`.
- **Alertas** — **Recomendação:** picos de falha de login e erros `5xx`.
- **Resposta a incidentes** — **Recomendação:** runbook — revogar tokens Sanctum,
  invalidar sessões, desativar usuário (`ativo=false`), girar `APP_KEY`/segredos.

---

## 11. Segurança no SDLC

- **No CI (GitHub Actions)** — **Lacuna a implementar:** **Larastan/PHPStan**,
  **Enlightn** (scanner de segurança Laravel), **`composer audit`**, **gitleaks**;
  além de Pint e Pest.
- **pre-commit** — Pint + gitleaks.
- **Code review** — checklist: `$fillable` definido, bindings no SQL bruto (§4.5),
  Policies nas rotas de escrita, `{!! !!}` revisado, validação de upload.
- **Gestão de vulnerabilidades** — severidade + **SLA** de correção.

---

## 12. Checklist e achados priorizados

| # | Controle | Ref. | Status |
|---|---|---|---|
| C1 | Hash de senha (argon2id/bcrypt) | ASVS V2.4 | Atende |
| C2 | Sessão segura + tokens Sanctum revogáveis | ASVS V3 / API2 | Parcial |
| C3 | Throttling / anti-brute force | API4 | Parcial |
| C4 | RBAC server-side (Gates/Policies) | ASVS V4 / API5 | Parcial |
| C5 | Object-level (BOLA/IDOR) | API1 | Lacuna |
| C6 | Validação (Form Requests) | ASVS V5 | Atende |
| C7 | SQL parametrizado (incl. SQL bruto §4.5) | API8 / CWE-89 | Atende c/ ressalva |
| C8 | Mass assignment (`$fillable`) | CWE-915 / API6 | Lacuna |
| C9 | XSS (Blade escape) | CWE-79 | Atende c/ ressalva |
| C10 | Upload CSV seguro | CWE-1236 | Parcial |
| C11 | Segredos / `APP_KEY` / `APP_DEBUG=false` | CWE-798 / CWE-489 | Parcial |
| C12 | Sem dados sensíveis em log | CWE-532 | Atende |
| C13 | TLS / headers / CORS | ASVS V9/V14 | Lacuna |
| C14 | SAST/SCA/secret scan no CI | ASVS V1.14 | Lacuna |
| C15 | Auditoria e imutabilidade | ASVS V7 | Atende |
| C16 | Hardening Postgres/contêiner; Telescope/`/up` | ASVS V14 | Lacuna |

**Achados priorizados e remediação:**

| Risco | Achado | Ação | Onde |
|---|---|---|---|
| **Alto** | `APP_DEBUG`/`.env`/`APP_KEY` mal configurados (C11) | `APP_DEBUG=false` em prod, `APP_KEY` setado, gitleaks no CI | `.env`, CI |
| **Alto** | Mass assignment sem `$fillable` (C8) | Definir `$fillable`; preencher do Form Request validado | `app/Infraestrutura/Models` |
| **Alto** | TLS/headers/CORS não especificados (C13) | HTTPS, HSTS/CSP, `config/cors.php` restrito | middleware, `config/cors.php` |
| **Alto** | CI sem SAST/SCA/secret scan (C14) | Larastan + Enlightn + `composer audit` + gitleaks | `.github/workflows` |
| **Médio** | Throttling de login ausente (C3) | `throttle` + log de falhas | `routes/api.php`, login |
| **Médio** | BOLA/IDOR sem Policy (C5) | Policies por objeto | `app/Policies` |
| **Médio** | Upload CSV sem limites/anti-formula (C10) | rules `mimes`/tamanho + sanitização | `Infraestrutura/Csv` |
| **Médio** | Telescope/OpenAPI potencialmente expostos (C16) | Gate + desabilitar em prod | `Providers`, config |
| **Baixo** | Política de senha indefinida | `Password::min(12)->uncompromised()` | regras de validação |
| **Baixo** | Usuário Postgres com privilégio amplo | least privilege no banco | infra/IaC |

**Conclusão.** Integridade/auditoria e validação já estão bem ancoradas (§9.2,
§9.4) e o Laravel entrega CSRF, escaping (Blade), hashing e throttling nativos. As
maiores lacunas para ASVS 2 são **configuração/perímetro** (`APP_DEBUG`/headers/
TLS/CORS), **mass assignment**, **RBAC/BOLA** e **automação de segurança no CI** —
todas endereçáveis sem mudar requisito de negócio.

---

**Fim do documento.**
