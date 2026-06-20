# NeverVenture — Risco de Mercado para Commodities

Especificação e mocks do MVP de gestão de risco de mercado (MtM diário de derivativos sobre commodities).

- `specs/requisitos.md` — especificação técnica vigente (fonte da verdade, v1.6).
- `specs/passos_dev.md` — roteiro de fases de desenvolvimento (Fase 0..14).
- `specs/spec_parte_0.md` — especificação da Parte 0 (Fundação, Laravel 13).
- `mock_telas/` — mock interativo de telas (HTML + React via Babel Standalone).

> O antigo `decisions.md` (decisões da tentativa DDD anterior) foi arquivado em
> `historic-plans/` e está **desatualizado** — não usar como contexto.

## Arquitetura (MVC nativo — *fat model*)

A aplicação segue **MVC nativo do Laravel com *fat model*** (Eloquent ActiveRecord) —
decisão de arquitetura "Alternativa A", registrada em `specs/requisitos.md` §2.2 e §4.
Não há domínio em PHP puro separado nem repositórios/contratos de persistência.

- **Models (`app/Models/`)** — Eloquent "gordo": concentram persistência **e** o cálculo
  de MtM. `Posicao` é a base com a fábrica de hidratação polimórfica (`newFromBuilder`);
  `Futuro`/`Ndf`/`Opcao`/`Otc` a estendem; `Perna`/`Movimentacao` são filhos. O cálculo
  é mantido "puro" (sem query) e a aritmética reutilizável fica em `app/Models/Concerns/`.
- **Services (`app/Services/`)** — regras de negócio/orquestração, usando Eloquent
  direto (transações, `lockForUpdate`, `updateOrCreate` para idempotência). DTOs/read
  models de saída em `app/Services/Dados/`.
- **Facades (`app/Facades/`)** — fachada conveniente dos serviços (`Posicoes`, `Motor`,
  etc.); controllers preferem injeção por construtor.
- **Polimorfismo do motor** preservado sem `if`/`switch` por tipo: o motor itera Models
  `Posicao` e chama `calcularMtm()`; o único `match` por instrumento vive no
  `newFromBuilder`. Único ponto de extensão de ingestão que sobrevive como interface:
  `FontePrecos` (`app/Support/Csv/ImportadorPrecosCsv`).

## Ambiente Docker

**Stack:** PHP 8.3 · Laravel 13 · PostgreSQL 15+ · Livewire 4 + Tailwind + Flux UI · Fortify + Sanctum · Pest.
A fundação do projeto está especificada em `specs/spec_parte_0.md` (Parte 0).

A aplicação e os bancos de dados são executados via Docker Compose para garantir a paridade de ambiente.

### Comandos do Docker
- Subir ambiente completo: `docker compose up -d`
- Derrubar ambiente: `docker compose down`
- Reconstruir imagem da aplicação: `docker compose build`
- Ver logs da aplicação: `docker compose logs -f app`

### Comandos de Desenvolvimento (no Container)
Qualquer comando do Laravel Artisan ou Composer pode ser executado através do container `app`:
- Executar migrations: `docker compose exec app php artisan migrate`
- Rodar Tinker: `docker compose exec app php artisan tinker`
- Instalar dependências: `docker compose exec app composer install`

### Testes
- Executar suite de testes: `docker compose exec -e DB_HOST=postgres_test -e DB_PORT=5432 app composer test` (ou `./vendor/bin/pest` dentro do container)

## Progresso de desenvolvimento

> Roteiro completo em `specs/passos_dev.md` (Fase 0..14). Estado atual abaixo —
> manter sincronizado conforme as fases forem concluídas.

- **Concluído:** Fase 0 (Fundação), Fase 1 (Esqueleto MVC + banco), Fase 2 (Models +
  cálculo), Fase 3 (testes unitários), Fase 4 (Produtos & Preços — Parte 6).
- **Próximo passo: Fase 5 — Módulo Posições (Parte 7).** Cadastro dos 4 instrumentos
  (FUTURO, NDF, OPCAO, OTC) + movimentações de FUTURO (RN-001..006, RN-020..025).
  Ainda **não implementado**: não existem `ServicoPosicoes`/`ServicoMovimentacoes`, nem
  as rotas/telas de posições. Os Models já existem (criados na Fase 2).
- **Pendente depois:** Fase 6 — Motor MtM (Parte 8) e fases seguintes (Relatórios, seed,
  testes de integração, RBAC, segurança, etc.).
- Para especificar a próxima fase, use a skill `especificar-proximo-passo`.

## Pastas a ignorar

- **`historic-plans/`** — arquivo morto de planos de sessões anteriores do Codex, mantido apenas para registro histórico. **Não leia, não edite e não use como contexto** — o conteúdo pode estar desatualizado em relação à especificação vigente.

