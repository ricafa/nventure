# NeverVenture — Risco de Mercado para Commodities

- nunca commite como claude, apenas como meu usuario.

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

### Módulo Produtos & Preços (Parte 4)
- CRUD de produtos (soft delete por `ativo`) e ingestão de preços (manual + upload CSV),
  com as RNs RN-007..010a nos serviços (`ServicoProdutos`/`ServicoPrecos`).
- API REST: `GET|POST /api/v1/produtos`, `GET|PUT|DELETE /api/v1/produtos/{id}` (DELETE
  inativa, não remove), `GET|POST /api/v1/precos`, `POST /api/v1/precos/upload`,
  `DELETE /api/v1/precos/{id}`. Tudo sob `auth:sanctum` (autorização por perfil é Parte 10).
- Telas web (Livewire): `/produtos` (listagem + form criar/editar/inativar) e `/precos`
  (lançamento manual + upload CSV com relatório aceitas/rejeitadas + listagem filtrável).
- Ingestão de CSV pela interface `FontePrecos` (`app/Support/Csv/ImportadorPrecosCsv`):
  cabeçalho exato, anti-formula-injection (CWE-1236), limites 2 MB/5.000 linhas, aceita
  Excel pt-BR (`;`/decimal `,`). Linha inválida vira rejeição no relatório (RN-010).
- **Guia de uso (endpoints, exemplos, CSV, telas):** `docs/uso-parte-4.md`.

### Módulo Posições (Parte 5 / Parte 7)
- Cadastro dos 4 instrumentos (`ServicoPosicoes::criarFuturo/criarNdf/criarOpcao/criarOtc`)
  em transação mãe→filha; FUTURO ainda gera a `ABERTURA` automática (RN-020). Cada
  `create()` injeta `criado_por` no Service (`Auth::user()?->login ?? 'sistema'`, D-507);
  os Form Requests **não** aceitam o campo. RN-006 (indexador do OTC = produto cadastrado)
  é lookup no Service; demais RNs estruturais (RN-001..005) nos Form Requests por instrumento.
- Movimentações de FUTURO (`ServicoMovimentacoes::movimentarFuturo`) sob `DB::transaction` +
  `lockForUpdate`: valida RN-022 (redução ≤ saldo) **antes** do INSERT, recarrega
  `movimentacoes` para o `replay()`, consolida só `quantidade`/`status` (preço médio **não**
  é persistido — derivado, D-504) e devolve `EstadoMovimentacao`. Redução total → `ENCERRADA`.
- Deleção segura: `DELETE /posicoes/{id}` só sem MtM (D-502, 409 caso contrário); pós-MtM o
  caminho é `POST /posicoes/{id}/encerrar` (transição idempotente `ABERTA→ENCERRADA`, D-507).
- API REST (sob `auth:sanctum`): `GET /posicoes` (paginada, filtros status/produto_id),
  `GET /posicoes/{id}`, `POST /posicoes/{futuro|ndf|opcao|otc}`, `POST /posicoes/{id}/encerrar`,
  `DELETE /posicoes/{id}`, `GET|POST /posicoes/{id}/movimentacoes`. DTOs em
  `app/Services/Dados/` (`PosicaoResumo`/`PosicaoDetalhe`/`EstadoMovimentacao`).
- Telas Livewire: `/posicoes` (listagem + filtros + modal de detalhe/movimentar) e
  `/posicoes/nova` (formulário dinâmico por instrumento, pernas dinâmicas na OPCAO).

### Módulo Motor MtM (Parte 6 / Parte 8)
- Dois serviços (D-601, §4.4): `MotorMtm` é o **laço de cálculo** (itera `Posicao` ABERTA por
  subclasse com eager loading, `calcularMtm()` polimórfico **sem `if`/`switch` por tipo**, UPSERT
  idempotente em `mtm_diario`, marca `VENCIDA`) e `ServicoMotor` é a **orquestração/auditoria**
  (abre/fecha `motor_execucao`, consolida totais/falhas, devolve `ResumoExecucao`).
- Auditoria abre **antes** do laço e o `execucao_id` propaga a cada `mtm_diario` (D-602). Toda
  execução fica registrada, inclusive as que terminam só com falhas.
- Idempotência (RN-013) via `firstOrNew` + `isDirty` em vez de `updateOrCreate` direto:
  **proveniência condicional** (D-604) — `execucao_id`/`processado_em` só são recarimbados quando
  um valor financeiro muda; reprocessamento estéril conta como sucesso mas não toca a autoria.
- `VENCIDA` (RN-014, D-605): marca-se só posições com **sucesso ∩ `data_vencimento <= data_calculo`**,
  sob `lockForUpdate`; falha (ex.: sem preço) **permanece `ABERTA`** para reprocessar. Isolamento de
  falhas (RN-012, D-606): `try/catch` **por posição** sob `DB::transaction` local — o lote não é atômico.
- Conversão BRL (RN-015, D-607) polimórfica e sem caso especial; `pl_acumulado = mtmBrl + plRealizado()*cambio`
  (RN-023); `variacao_dia = mtmBrl − mtmOntem`. `float` nativo mantido (BCMath/Money é Fase 12).
- API REST (sob `auth:sanctum`, **sem** AuthZ por perfil — Fase 10, D-612): `POST /motor/processar`
  (200 com `ResumoExecucao` flat §5.2.4, sem wrapper `data`, `data_calculo` **obrigatório**, D-611),
  `GET /motor/execucoes`, `GET /motor/execucoes/{id}`.
- Command `motor:processar {--data=}` (D-609): sem `--data` processa **hoje**, `disparado_por='agendador'`;
  agendamento `Schedule::command('motor:processar')->weekdays()->at('19:00')` em `routes/console.php`.
- Tela Livewire `/motor` (D-610): injeta `ServicoMotor` e chama o método PHP direto (sem auto-chamada
  HTTP); disparo + resumo + histórico de execuções. DTOs em `app/Services/Dados/`
  (`ResultadoProcessamento`/`RegistroMtm`/`ResumoExecucao`).

### Módulo Relatórios (Parte 7 / Parte 9)
- `ServicoRelatorios` (D-701): leitura agregada das 4 visões sobre `mtm_diario`; **não recalcula
  MtM** — soma/agrupa valores prontos. O coração é o `snapshot()` (D-702): último `mtm_diario`
  com `data_calculo <= data` de cada posição ABERTA, em **um** `DISTINCT ON` (PostgreSQL-only).
  Posição sem MtM `<= data` aparece com `tem_mtm=false`/zeros. PM do FUTURO reusa `Futuro::precoMedio()`.
- Exposição (RN-019) usa **`Posicao::quantidadeExposicao()` polimórfico** (D-705, sem `if` no Service):
  base = `quantidade` (FUTURO/OTC), `Ndf` sobrescreve → `valor_nocional`, OPCAO herda `1` (D-705a, sem
  delta no MVP). `ExposicaoProduto` expõe `mix` (contagem por tipo) e `unidade_mista` (`mix['NDF']>0 &&
  (mix['FUTURO']>0 || mix['OTC']>0)`) sinalizando o mismatch contratos×nocional.
- P&L: diário (RN-017) = `Σ variacao_dia` na **data exata**; acumulado (RN-018) = `Σ pl_acumulado` do
  snapshot das ABERTA (inclui realizado, RN-023). A série do gráfico usa `SUM(pl_acumulado)` (D-704),
  não `SUM(mtm_valor)`. Histórico (`historico-mtm`) é a série de uma posição; `find() ?? throw
  ErroNaoEncontrado` (404 no envelope §5.1, **não** `findOrFail`).
- API REST (sob `auth:sanctum`, sem AuthZ por perfil — Fase 10, D-709): `GET /relatorios/posicao-aberta`,
  `/pl-diario`, `/exposicao-liquida`, `/historico-mtm`. `RelatorioRequest` (`data` opcional → hoje;
  helper `dataRef()` — `data()` colidiria com `Request::data()`) e `HistoricoMtmRequest` dedicado
  (`posicao_id` `required` → 422 se ausente). `?formato=json|csv|pdf`: `csv` via `ExportadorCsv`
  (CWE-1236, BOM, `StreamedResponse`); `pdf` → **501** `FORMATO_INDISPONIVEL` (no contrato, diferido,
  D-707). Controller tipado `Symfony\...\Response` (supertipo de `StreamedResponse`+`JsonResponse`, A-1).
- Telas Livewire sob `auth` (D-708): `Dashboard` (rota `dashboard`, home autenticada — §6.2/BX-4),
  `/relatorios/posicao-aberta`, `/relatorios/pl`, `/relatorios/exposicao`; injetam `ServicoRelatorios`
  (sem auto-HTTP, D-610). DTOs em `app/Services/Dados/` (`RelatorioPosicaoAberta`/`LinhaPosicaoAberta`/
  `ResumoPL`/`ExposicaoProduto`/`PontoHistoricoMtm`). **Guia:** `docs/uso-parte-7.md`.

## Progresso de desenvolvimento

> Roteiro completo em `specs/passos_dev.md` (Fase 0..14). Estado atual abaixo —
> manter sincronizado conforme as fases forem concluídas.

- **Concluído:** Fase 0 (Fundação), Fase 1 (Esqueleto MVC + banco), Fase 2 (Models +
  cálculo), Fase 3 (testes unitários), Fase 4 (Produtos & Preços — Parte 6), Fase 5
  (Módulo Posições — Parte 7), Fase 6 (Motor MtM — Parte 8), Fase 7 (Relatórios — Parte 9).
- **Próximo passo: Fase 8 — Seed & dados de demonstração** (`passos_dev.md`): portfólio
  realista com histórico de MtM (produto → preço → posição → movimentação → motor) que
  torna os 4 relatórios "ricos" em dev/demo/UAT.
- **Pendente depois:** fases seguintes (testes de integração, RBAC, segurança, etc.).

## Pastas a ignorar

- **`historic-plans/`** — arquivo morto de planos de sessões anteriores do Claude Code, mantido apenas para registro histórico. **Não leia, não edite e não use como contexto** — o conteúdo pode estar desatualizado em relação à especificação vigente.

