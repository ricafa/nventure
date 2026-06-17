# Prompt — Especificação da estrutura e arquitetura do projeto

Você é um(a) arquiteto(a) de software. Leia **integralmente** o documento de
requisitos em `requisitos.md` (a especificação técnica vigente, v1.4 — fonte da
verdade) e crie um arquivo `ARCHITECTURE.md` na raiz do projeto que sirva como
referência arquitetural para todo o desenvolvimento do **NeverVenture**.

**Contexto do sistema** (resumo; confirme e aprofunde lendo o `requisitos.md`):
ferramenta **interna** de **gestão de risco de mercado** (MtM diário de derivativos
sobre commodities). O núcleo é o **mark-to-market (MtM) diário** de posições —
futuro, NDF, opção multi-perna e OTC — com preço médio ponderado e P&L realizado
para futuros, histórico de MtM, variação diária, P&L acumulado e exposição líquida
por produto. No MVP **não** há integração com feeds em tempo real nem risco
quantitativo (VaR, gregas) — ver escopo em §1.2. Perfis: OPERADOR, GESTOR e ADMIN
(§3.2.10).

## Stack obrigatória

A stack abaixo já está **decidida** (ver `requisitos.md` §2.2). O `ARCHITECTURE.md`
deve **adotá-la, detalhá-la e justificá-la** (citando as alternativas do §2.2 —
Django REST, .NET 8, Node/NestJS — e por que não foram escolhidas). Não rediscuta a
stack; aprofunde-a.

- **Linguagem (back):** PHP 8.2+
- **Framework:** Laravel 11
- **UI:** Blade + Livewire 3 (com Alpine.js; Vite para empacotar CSS/JS e Tailwind) — server-rendered, sem SPA
- **Validação:** Form Requests / Validator
- **ORM:** Eloquent (modelos na infraestrutura; domínio puro traduzido nos repositórios — §4.5)
- **Migrations:** Migrations do Laravel (Schema builder)
- **Banco:** PostgreSQL 15+
- **Agendador:** Laravel Task Scheduler (`php artisan schedule:run`)
- **Autenticação:** Laravel Sanctum (sessão para a UI Livewire; tokens para a API REST); Gates/Policies para RBAC
- **Testes:** Pest (sobre PHPUnit) + Laravel Testing (Feature)
- **Qualidade:** Laravel Pint (PSR-12), Larastan/PHPStan, pre-commit
- **Execução/empacotamento:** Docker + Laravel Sail; Composer
- **CI:** GitHub Actions (Pint, PHPStan, Pest, build de assets)

## Conteúdo do `ARCHITECTURE.md` (nesta ordem)

1. **Visão geral do sistema** — propósito, usuários-alvo (OPERADOR/GESTOR/ADMIN),
   escopo dentro/fora (resumir §1.2) e contexto de uso (ferramenta interna
   pós-trade, não exposta a terceiros).

2. **Stack tecnológica** — listar a stack obrigatória acima, justificando cada
   escolha e o encaixe nos requisitos (API REST, motor polimórfico, relatórios,
   telas internas). Citar as alternativas do §2.2 e por que ficaram fora.

3. **Estrutura de pastas** — árvore de diretórios sobre a base do Laravel,
   explicando cada pasta. Refletir as camadas (apresentação/Http+Livewire,
   aplicação/serviços, domínio, infraestrutura) e os quatro módulos do §2.1
   (preços, posições, motor MtM, relatórios). Indicar onde vivem as classes de
   **domínio puro** (`Posicao` e subclasses, `Perna`, `Movimentacao`), os **Models
   Eloquent** e repositórios (§4.5), os **Form Requests/Resources**, os componentes
   **Livewire**, as **migrations** e os testes.

4. **Padrões arquiteturais** — camadas e regras de dependência (o domínio não
   conhece Laravel/Eloquent; dependências apontam para dentro; contratos/portas na
   aplicação ligados por Service Provider). Detalhar os padrões **obrigatórios**:
   (a) **polimorfismo sobre condicionais** — motor sem `if` por tipo (§2.3, §4.4);
   (b) **aberto/fechado** — novo instrumento = nova subclasse + ramo no `match` da
   hidratação; (c) **Repository + Factory** (§4.5); (d) **idempotência** (UPSERT/
   `updateOrCreate`, RN-013); (e) **auditoria por design** (`motor_execucao`,
   imutabilidade). Enfatizar o **domínio em PHP puro separado do Eloquent**.

5. **Convenções de código** — nomenclatura; idioma. **Recomendação a justificar:**
   identificadores de **domínio em português** (linguagem ubíqua do `requisitos.md`:
   `Posicao`, `calcularMtm`, `precoMedio`, `posicao_movimentacao`); comentários em
   português. Definir **PSR-12 via Pint**, tipagem estrita + **Larastan/PHPStan**, e
   commits **Conventional Commits**. Colunas do banco em `snake_case`.

6. **Estratégia de testes** — tipos (unidade com **Pest**; Feature/integração com
   Laravel + `RefreshDatabase`; e2e opcional com Dusk), organização e metas de
   cobertura do §8.4 (domínio ≥ 90%, aplicação ≥ 70%, total ≥ 75%). Casos críticos:
   `calcularMtm` por tipo, multi-perna, preço médio/realizado de futuro (RN-021/023),
   idempotência.

7. **API e contratos** — REST, versionamento (`/api/v1`, `routes/api.php`), formato
   de resposta (API Resources) e de erro (`{ "erro", "mensagem" }`), status codes
   (incl. 409/422 do domínio), paginação (`paginate`), autenticação **Sanctum** e
   documentação OpenAPI (Scribe/L5-Swagger). Alinhar com §5.2.

8. **Banco de dados** — modelo conceitual (tabelas §3.2; relacionamentos §3.1),
   migrations (Laravel), convenções (`snake_case`; **tabelas no singular** via
   `$table`), índices recomendados (§3.3, incl. índice único parcial via SQL bruto),
   casts `decimal:` para valores financeiros, e soft-delete vs. hard-delete (produto
   `ativo`; preço usado → 409 RN-010a; movimentações imutáveis RN-025; cascata FK).

9. **Tratamento de erros e logs** — hierarquia de exceções (domínio/aplicação/infra)
   e mapeamento para HTTP no handler central; logs Monolog em JSON, níveis,
   correlação por `request_id`/`execucao_id`; e o que **não** logar (`senha_hash`,
   tokens, cookies; `APP_DEBUG=false` em prod).

10. **Build, deploy e ambientes** — dev/homolog/prod; rodar localmente (**Laravel
    Sail** + PostgreSQL, `migrate --seed`); buildar (Composer, `config/route/view
    cache`, Vite); deploy em containers; agendamento/filas; metas §9.1/§9.3.

11. **Dependências externas** — sistema autocontido no MVP (feeds fora do escopo).
    Abstrair, atrás de **contratos** (portas), a ingestão de preços (CSV hoje;
    feeds na Fase 4) e e-mail (`Mail`). Sem integração com terceiros no MVP.

12. **Decisões registradas (ADRs)** — 4-6 decisões no formato **Contexto / Decisão /
    Consequências**: motor polimórfico; Laravel como framework; domínio PHP puro
    separado do Eloquent; frontend Blade+Livewire; preço médio por replay (§4.3.1,
    RN-021); PostgreSQL + Eloquent + Migrations; idioma de domínio em português.

## Diretrizes finais

- Idioma do documento: **português**. Tom: técnico, objetivo, direto.
- Mantenha coerência com o `requisitos.md`: referencie § e RN ao justificar
  decisões e **não** contradiga regras de negócio.
- A stack está fixada — não a rediscuta; aprofunde-a. Para pontos de implementação
  ausentes nos requisitos, registre **premissas explícitas**.
