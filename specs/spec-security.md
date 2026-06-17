# Prompt — Especificação de revisão de segurança do projeto

Você é um(a) especialista em segurança de aplicações (AppSec). Leia
**integralmente** o `requisitos.md` (v1.4 — fonte da verdade) e o `ARCHITECTURE.md`
(arquitetura e stack Laravel) e crie um arquivo `SECURITY.md` na raiz do projeto:
uma **especificação de revisão de segurança** do **NeverVenture**, que sirva tanto
para avaliar a postura atual quanto para guiar a implementação dos controles.

**Contexto** (confirme lendo os documentos-base): ferramenta **interna** de gestão
de risco de mercado (MtM diário de derivativos sobre commodities). Mesmo interna,
lida com **autenticação**, **autorização por perfil**, **integridade de valores
financeiros** (marcações, P&L) e **credenciais** — alvo legítimo de revisão. Base
parcial no §9.2 (hash bcrypt/argon2id; **Laravel Sanctum** — sessão para a UI
Livewire e tokens para a API; RBAC OPERADOR/GESTOR/ADMIN; auditoria de escrita) e na
stack do `ARCHITECTURE.md` (PHP 8.2+/Laravel 11, Blade+Livewire, Eloquent,
PostgreSQL, Docker, GitHub Actions).

## Normas e ferramentas de referência (obrigatórias)

- **Padrões:** OWASP ASVS (nível 2 como alvo), OWASP Top 10 (2021), **OWASP API
  Security Top 10 (2023)** — sistema API-first — e CWE quando aplicável.
- **SAST / análise estática:** **Larastan/PHPStan** e **Enlightn** (scanner de
  segurança/performance específico de Laravel); Psalm opcional.
- **SCA / dependências:** **`composer audit`** e `npm audit` (ou Dependabot);
  lockfiles.
- **Segredos:** **gitleaks** (repositório e histórico; atenção a `.env`/`APP_KEY`).
- **Imagens/contêiner:** **Trivy**.

## Conteúdo do `SECURITY.md` (nesta ordem)

1. **Visão geral e escopo** — objetivos; escopo (backend Laravel API + Livewire,
   banco, contêineres, CI) e o que fica fora; premissas; **classificação de
   sensibilidade** (credenciais/tokens/sessões/`APP_KEY` = crítico; posições,
   marcações e P&L = confidencial de negócio; preços; dados de usuário).

2. **Modelo de ameaças** — **ativos**; **atores/ameaças** (usuário interno
   mal-intencionado, conta comprometida, atacante na rede, dependência vulnerável,
   segredo vazado); **fronteiras de confiança** e **superfícies** (login/Sanctum,
   Livewire/web, rotas REST §5.2, upload CSV, `/up`/Telescope). Aplicar **STRIDE**
   por componente, com diagrama textual de trust boundaries.

3. **Autenticação** — hashing **argon2id** (`config/hashing.php`) ou bcrypt; sessão
   da UI Livewire (cookies `HttpOnly`/`SESSION_SECURE_COOKIE`/`SESSION_SAME_SITE`,
   rotação no login, CSRF nativo); tokens **Sanctum** revogáveis com expiração para
   a API; política de senha (`Password::min(12)->uncompromised()`); proteção a força
   bruta com **`throttle`/RateLimiter** (API4). (Passport se exigir OAuth2/JWT.)

4. **Autorização (RBAC)** — **matriz perfil × operação** (de §9.2 e §5.2) aplicada
   por **Gates/Policies** e middleware `can:` em toda rota de escrita; menor
   privilégio; verificação em nível de objeto (**BOLA/IDOR**, API1); segregação de
   funções.

5. **Validação de entrada e segurança da API/UI** — **Form Requests**; **injeção
   SQL** (Eloquent/query builder parametrizam — **SQL bruto** do §4.5/migrations só
   com **bindings**, CWE-89); **mass assignment** (CWE-915: `$fillable` explícito,
   nunca `Model::create($request->all())`); **XSS** (Blade escapa por padrão; evitar
   `{!! !!}`); **upload de CSV** (mimes/tamanho/linhas + formula injection CWE-1236,
   §5.2.2/RN-010); **rate limiting**; **CORS** (`config/cors.php`) e security
   headers; erros sem vazamento e **`APP_DEBUG=false`** em prod (CWE-489).

6. **Proteção de dados** — TLS em trânsito; repouso; **segredos** (`.env`/secret
   manager, nada versionado, `APP_KEY` setado, `config()` fora de `env()` —
   gitleaks); dados sensíveis **nunca** em logs (`ARCHITECTURE.md` §9 / §9.4);
   **backup** diário ≥ 30 dias (§9.3) criptografado.

7. **Integridade, auditoria e não-repúdio** — **imutabilidade** das movimentações
   (RN-025); trilha de auditoria (§9.2) via `criado_por`/`criado_em`/`disparado_por`
   e `motor_execucao` (§3.2.9); **idempotência** (RN-013, `updateOrCreate`);
   `NUMERIC` + casts `decimal:` (não `float`); invariantes em transação (RN-024).

8. **Dependências e cadeia de suprimentos** — **`composer audit`**/npm audit no CI,
   **pinning** (lockfiles), imagens base mínimas (Trivy), SBOM (CycloneDX) e política
   de atualização.

9. **Configuração e hardening** — `APP_DEBUG=false`/`APP_ENV=production`/`APP_KEY`;
   **PostgreSQL** com usuário de **menor privilégio**; **contêiner** não-root;
   `/up` sem dados sensíveis e **Telescope/Horizon** protegidos por gate (off em
   prod); `/metrics`/OpenAPI restritos.

10. **Logging, detecção e resposta a incidentes** — eventos de segurança (login
    falho, lockout, troca de perfil, remoções, falhas do motor); Monolog JSON com
    `request_id`/`execucao_id` (§9.4); alertas; runbook (revogar tokens Sanctum,
    invalidar sessões, desativar usuário, girar `APP_KEY`/segredos).

11. **Segurança no SDLC** — **no CI (GitHub Actions):** Larastan/PHPStan, **Enlightn**,
    `composer audit`, gitleaks (além de Pint e Pest); **pre-commit**; checklist de
    code review (`$fillable`, bindings no SQL bruto §4.5, Policies nas rotas de
    escrita, `{!! !!}`, validação de upload); gestão de vulnerabilidades com SLA.

12. **Checklist e achados priorizados** — tabela mapeando cada controle ao **status**
    (Atende / Parcial / Lacuna) contra OWASP ASVS e API Top 10, com **achados** e
    **recomendações priorizadas por risco** (Crítico/Alto/Médio/Baixo) e plano de
    remediação, apontando o arquivo/camada conforme a estrutura do `ARCHITECTURE.md`.

## Diretrizes finais

- Idioma do documento: **português**. Tom: técnico, objetivo, direto.
- Distinga o que os requisitos **já exigem** (cite § e RN) do que é **recomendação
  nova** (marque **Premissa**/**Recomendação**).
- **Priorize por risco** e destaque os controles **específicos de Laravel** (mass
  assignment, `APP_DEBUG`, CSRF/Blade, Sanctum, Telescope).
- Não contradiga regras de negócio nem invente requisito; para lacunas reais,
  registre premissas explícitas.
