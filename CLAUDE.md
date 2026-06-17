# NeverVenture — Risco de Mercado para Commodities

Especificação e mocks do MVP de gestão de risco de mercado (MtM diário de derivativos sobre commodities).

- `especificacao_mvp_risco_mercado.md` — especificação técnica vigente (fonte da verdade).
- `revisao_especificacao_v1.1.md` — relatório da revisão técnica que gerou a v1.2.
- `mock_telas/` — mock interativo de telas (HTML + React via Babel Standalone).

## Ambiente Docker

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

### Módulo Posições (Parte 7)
- Quatro instrumentos (FUTURO, NDF, OPCAO, OTC) + movimentações de FUTURO (RN-020..025).
- API REST: `GET/POST /api/v1/posicoes`, `POST /api/v1/posicoes/{futuro,ndf,opcao,otc}`,
  `GET /api/v1/posicoes/{id}`, `POST /api/v1/posicoes/{id}/encerrar`,
  `DELETE /api/v1/posicoes/{id}`, `GET|POST /api/v1/posicoes/{id}/movimentacoes`.
- Telas web (Livewire): `/posicoes` (listagem + detalhe + modal Movimentar) e
  `/posicoes/nova` (cadastro dinâmico por instrumento).
- As regras de negócio vivem nos serviços (`app/Aplicacao/Posicoes/`); o cadastro de
  FUTURO cria a movimentação `ABERTURA` automática na mesma transação (RN-020).

### Motor MtM (Parte 8)
- Processar o MtM de um dia manualmente: `docker compose exec app php artisan motor:processar --data=2026-05-23`
  (sem `--data`, processa **hoje**; `--por=` define o `disparado_por`).
- Agendamento (`routes/console.php`): `motor:processar` roda em dias úteis às 19:00. Em
  produção, o cron do servidor chama `php artisan schedule:run` a cada minuto (hardening
  na Parte 13).
- Tela de operação: rota web `/motor` (Livewire). API REST: `POST /api/v1/motor/processar`,
  `GET /api/v1/motor/execucoes`, `GET /api/v1/motor/execucoes/{id}`.

## Pastas a ignorar

- **`historic-plans/`** — arquivo morto de planos de sessões anteriores do Claude Code, mantido apenas para registro histórico. **Não leia, não edite e não use como contexto** — o conteúdo pode estar desatualizado em relação à especificação vigente.

