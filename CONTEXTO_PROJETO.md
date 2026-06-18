# Contexto do Projeto - NeverVenture

Atualizado em: 2026-06-17

Este arquivo resume o contexto vigente extraido das especificacoes em `specs/`.
Em caso de divergencia, a fonte da verdade continua sendo `specs/requisitos.md`.
Nao usar `historic-plans/` como contexto.

## Fontes vigentes

- `specs/requisitos.md`: especificacao tecnica v1.6, fonte da verdade para negocio, modelo de dados, contratos, regras RN-001..RN-025 e arquitetura.
- `specs/passos_dev.md`: roteiro de desenvolvimento Fase 0..14.
- `specs/spec_parte_0.md`: especificacao executavel da Fase 0/Fundacao.
- `mock_telas/`: mock interativo das telas.

## Produto

NeverVenture e um MVP de gestao de risco de mercado para commodities. O foco e o ciclo
pos-trade: cadastro/importacao de posicoes, lancamento/importacao de precos de referencia,
processamento diario de MtM, historico de P&L e relatorios consolidados.

Fora do MVP: feeds em tempo real, gregas, Black-76/Black-Scholes, VaR/CVaR, Monte Carlo,
limites automatizados, ETRM/ERP, estoque, frete, clima, multi-tenant e compliance regulatorio.

## Arquitetura

- Laravel 13, PHP 8.3+, PostgreSQL 15+, Livewire 4, Tailwind, Flux UI, Fortify, Sanctum e Pest.
- Arquitetura alvo: MVC nativo Laravel com fat model Eloquent.
- Nao ha dominio PHP puro separado, repositorios ou contratos de persistencia.
- `app/Models/`: Eloquent gordo, com persistencia e calculo MtM.
- `app/Models/Concerns/`: aritmetica reutilizavel e calculos puros.
- `app/Services/`: orquestracao e regras transacionais usando Eloquent direto.
- `app/Services/Dados/`: DTOs/read models de saida.
- `app/Facades/`: fachadas convenientes dos servicos.
- `app/Support/Csv/ImportadorPrecosCsv`: ponto de extensao de ingestao via interface `FontePrecos`.

Regra de ouro: o motor MtM deve iterar `Posicao` e chamar `calcularMtm()` sem `if`/`switch`
por tipo de instrumento. O unico `match` por instrumento fica na hidratacao polimorfica
`Posicao::newFromBuilder`.

## Modelo central

Tabelas principais:

- `produto`
- `preco_referencia`
- `posicao`
- `posicao_futuro`
- `posicao_movimentacao`
- `posicao_ndf`
- `posicao_opcao`
- `posicao_opcao_perna`
- `posicao_otc`
- `mtm_diario`
- `motor_execucao`
- `usuario`

Cada linha em `posicao` tem exatamente uma tabela filha conforme `instrumento`:
`FUTURO`, `NDF`, `OPCAO` ou `OTC`.

## Instrumentos e calculos

- FUTURO: usa movimentacoes `ABERTURA`, `AUMENTO` e `REDUCAO`; preco medio e derivado das entradas; reducoes nao alteram preco medio; reducoes geram P&L realizado.
- NDF: calcula diferenca entre taxa de mercado e taxa contratada sobre nocional. Para NDF cambial, a moeda e cadastrada como produto com `cambio_brl = 1` para evitar dupla conversao.
- OPCAO: pode ter uma ou varias pernas; cada perna tem tipo, estilo, strike, premio, quantidade e lado; no MVP usa valor intrinseco, sem gregas e sem valor temporal.
- OTC: usa preco de entrada, indexador e premio OTC.

`mtm_diario.pl_acumulado` = `mtm_valor` + P&L realizado acumulado das reducoes ate a data.
Para instrumentos sem reducoes no MVP, coincide com `mtm_valor`.

## Regras principais

- RN-001..006: cadastro de posicoes e validacoes por instrumento.
- RN-004a..004e: opcoes multi-perna; posicao mae de OPCAO usa `quantidade = 1` e `lado` informativo.
- RN-007..010a: precos, CSV, unicidade produto/data e bloqueio de exclusao de preco referenciado.
- RN-011..015: motor MtM, posicoes abertas, falhas por preco ausente, idempotencia, vencimento e conversao BRL.
- RN-016..019: relatorios de posicao aberta, P&L diario, P&L acumulado e exposicao liquida.
- RN-020..025: movimentacoes de FUTURO, abertura automatica, preco medio, reducoes, realizado, invariante de quantidade e imutabilidade.

## Motor MtM

- Processa somente posicoes `ABERTA`.
- Busca preco de referencia por produto e data.
- Se faltar preco, registra falha e continua o lote.
- Usa `MtmDiario::updateOrCreate([posicao_id, data_calculo], ...)` para idempotencia.
- Registra auditoria em `motor_execucao`.
- Apos sucesso, posicoes que vencem na data devem virar `VENCIDA`.
- Comando esperado: `php artisan motor:processar --data=YYYY-MM-DD`.
- Agendamento esperado: dias uteis as 19:00 em `routes/console.php`.

## APIs e telas

API REST principal:

- Produtos e precos.
- `GET/POST /api/v1/posicoes`
- `POST /api/v1/posicoes/{futuro,ndf,opcao,otc}`
- `GET /api/v1/posicoes/{id}`
- `POST /api/v1/posicoes/{id}/encerrar`
- `DELETE /api/v1/posicoes/{id}`
- `GET|POST /api/v1/posicoes/{id}/movimentacoes`
- `POST /api/v1/motor/processar`
- `GET /api/v1/motor/execucoes`
- `GET /api/v1/motor/execucoes/{id}`
- Relatorios: posicao aberta, P&L diario/acumulado e exposicao liquida.

Telas Livewire esperadas:

- `/posicoes`
- `/posicoes/nova`
- `/motor`
- telas de produtos/precos, relatorios e dashboard nas fases correspondentes.

## Roadmap de implementacao

- Fase 0: Fundacao Laravel 13, Docker, Postgres dev/test, auth, Sanctum, Pest, Pint, PHPStan e CI.
- Fase 1: Esqueleto MVC + migrations completas.
- Fase 2: Models fat model + calculos.
- Fase 3: Testes unitarios de calculo.
- Fase 4: Produtos e precos.
- Fase 5: Posicoes e movimentacoes.
- Fase 6: Motor MtM.
- Fase 7: Relatorios.
- Fase 8: Seed e dados de demo.
- Fase 9: Testes de integracao.
- Fase 10: RBAC e autenticacao.
- Fase 11: seguranca.
- Fase 12: nao-funcionais.
- Fase 13: regressao e CI gates.
- Fase 14: hardening e entrega.

## Fundacao (Parte 0)

Decisoes fixadas:

- Laravel 13.
- PHP 8.3.
- Starter kit oficial Livewire com auth built-in Fortify, sem WorkOS.
- GitHub Actions.
- `postgres_test` separado, efemero.
- Larastan/PHPStan nivel 8.
- Locale `pt_BR`.
- Tabela `usuario` no lugar de `users`; login por `login`; senha em `senha_hash`.

DoD da Parte 0:

- `docker compose up -d` sobe `app`, `postgres` e `postgres_test`.
- Login autentica contra `usuario` por `login`/senha.
- Testes rodam contra `postgres_test`.
- Pint e PHPStan passam.
- CI verde.
- Registro publico, verificacao de e-mail e reset de senha desabilitados.

## Comandos de desenvolvimento

- Subir ambiente: `docker compose up -d`
- Derrubar ambiente: `docker compose down`
- Build: `docker compose build`
- Logs app: `docker compose logs -f app`
- Migrations: `docker compose exec app php artisan migrate`
- Tinker: `docker compose exec app php artisan tinker`
- Composer install: `docker compose exec app composer install`
- Testes: `docker compose exec -e DB_HOST=postgres_test -e DB_PORT=5432 app composer test`

## Cuidados para proximos agentes

- Nao ler, editar ou usar `historic-plans/`.
- Preservar MVC fat model; nao introduzir DDD em camadas, repositorios ou dominio separado.
- Preservar polimorfismo do motor; condicionais por tipo nao pertencem ao motor.
- Usar transacoes e `lockForUpdate` nos servicos de movimentacao.
- Movimentacoes sao imutaveis no MVP.
- Cuidado com CSV: validar tipos, tamanho/linhas e prevenir formula injection.
- Usar PostgreSQL para recursos que SQLite nao cobre bem: indice parcial, `NUMERIC`, `JSONB`.
