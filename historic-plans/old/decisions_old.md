# Decisões de implementação — NeverVenture

Registro das decisões tomadas durante a implementação que **vão além, complementam
ou interpretam** a especificação (`specs/requisitos.md` v1.4 é a fonte da verdade).
Decisões já cobertas pelos `specs/` (ADR-001..006 em `ARCHITECTURE.md`) não são
repetidas aqui — este arquivo cobre o que foi decidido no código.

> Convenção: cada entrada cita a Parte (do `next_steps.md`) e a §/RN relevante.

---

## Parte 1 — Fundação / config

### D-101 · Banco de testes em container separado (porta 5433)
- **Contexto.** O §3/ADR-005 exige PostgreSQL (índice único parcial, `JSONB`,
  `NUMERIC`). Rodar testes contra o banco de desenvolvimento arriscaria dados.
- **Decisão.** `docker-compose.yml` sobe dois serviços: `postgres` (dev, 5432, com
  volume persistente) e `postgres_test` (5433, em `tmpfs` — efêmero/rápido). O
  `phpunit.xml` e o CI apontam para o 5433.
- **Alternativa descartada.** SQLite em testes — não suporta índice único parcial
  nem `JSONB`, divergindo do comportamento de produção.

### D-102 · PHPStan/Larastan nível 8, excluindo camadas de framework
- **Decisão.** `phpstan.neon` analisa `app/` no nível 8, mas exclui `Http/`,
  `Livewire/`, `Providers/` (alto acoplamento com magia do framework). O foco da
  análise estática é o **domínio e a aplicação**, onde mora o risco.
- **Revisitar em.** Partes 6–10, quando essas camadas existirem — avaliar subir a
  cobertura do PHPStan sobre elas.

### D-103 · Locale `pt_BR` e idioma de domínio em português
- **Decisão.** `.env` com `APP_LOCALE=pt_BR` e `APP_FAKER_LOCALE=pt_BR`, alinhado ao
  ADR-006 (linguagem ubíqua em português).

---

## Parte 2 — Banco de dados

### D-201 · Tabela `usuario` substitui a `usuario` padrão do Laravel
- **Contexto.** O §3.2.10 define `usuario` (singular, colunas em português:
  `login`, `senha_hash`, `perfil`). O Laravel assume `usuario`/`email`/`password`.
- **Decisão.** A migration `create_usuario_table` foi reescrita para criar `usuario`
  (+ `sessions`). O Model `User` mapeia `$table = 'usuario'`, sobrescreve
  `getAuthPassword()` → `senha_hash` e desativa `timestamps` (a tabela usa
  `criado_em`). `password_reset_tokens` foi **removida** (fora do escopo MVP).
- **Consequência.** Autenticação por `login` (não e-mail); ajustes em Form Requests
  de login virão na Parte 10.

### D-202 · CHECKs de domínio via SQL bruto, não enums do Laravel
- **Decisão.** Todas as restrições `CHECK IN (...)` do §3.2 (instrumento, mercado,
  lado, status, perfil, tipo de movimentação, tipo/estilo de opção) e os
  `CHECK (... > 0 / >= 0)` são criados com `DB::statement` na migration, nomeados
  (`chk_*`). Garante a regra no banco, independente da aplicação.
- **Alternativa descartada.** Apenas validação na aplicação — deixaria o banco sem
  proteção contra escrita direta.

### D-203 · Índice único parcial `uq_mov_abertura` em SQL bruto
- **Decisão.** `CREATE UNIQUE INDEX ... WHERE tipo = 'ABERTURA'` via `DB::statement`
  (específico do PostgreSQL, §3.2.4a/RN-020). Garante **exatamente uma ABERTURA por
  posição** no nível do banco. Idem para os índices parciais/compostos do §3.3.

### D-204 · `senha_hash` via cast `hashed`; seeds com senha padrão
- **Decisão.** O Model `User` usa cast `'senha_hash' => 'hashed'`, então o
  `UsuarioFactory` e o `UsuarioSeeder` passam a senha **em texto plano** (`senha123`)
  e o cast aplica bcrypt — evita o duplo-hash que ocorreria com `Hash::make()` no
  factory. Usuários de seed: `admin`/`gestor`/`operador`, todos com `senha123`
  (apenas dev).

### D-205 · Seed inclui produto "Dólar USD/BRL"
- **Decisão.** O `ProdutoSeeder` cadastra "Dólar USD/BRL" com `moeda_cotacao = BRL`,
  materializando a convenção cambial de NDF (§1.4/§4.3.2/RN-015) já nos dados de dev.

---

## Parte 3 — Domínio puro (§4)

### D-301 · `RegistroMtm` — value object para o MtM anterior
- **Contexto.** O §4.4 lê `$mtmOntem?->mtmValor` sem nomear o tipo de retorno de
  `RepositorioMtm::buscarUltimoAnterior`.
- **Decisão.** Criado `App\Dominio\Motor\RegistroMtm` (readonly: `posicaoId`,
  `dataCalculo`, `mtmValor`) como retorno tipado do contrato. Mínimo necessário
  para a variação diária.

### D-302 · `MotorMtm` (Domínio) depende de `Aplicacao\Contratos`
- **Contexto.** Tensão entre a localização de pastas (`next_steps.md`: contratos em
  `app/Aplicacao/Contratos`) e o motor estar no domínio (§3 do ARCHITECTURE).
- **Decisão.** Seguir a localização prescrita. O motor depende de **interfaces puras**
  (inversão de dependência), então a regra "Domínio não conhece Laravel" permanece
  intacta. A dependência Domínio→Contratos é de abstração, aceitável.

### D-303 · `upsert` do contrato sem `execucao_id`
- **Contexto.** `mtm_diario` tem `execucao_id` (§3.2.8), mas o código de referência
  do §4.4 chama `upsert` sem ele.
- **Decisão.** Manter a assinatura literal do §4.4. A coluna é `NULL`-able; a
  amarração com `motor_execucao` fica para a **Parte 8** (`ServicoMotor`), conforme
  o próprio `next_steps.md`. Evita inventar contrato divergente da spec.

### D-304 · `ResultadoProcessamento` com helpers de apresentação
- **Decisão.** Além de `sucessos`/`falhas` (pares `[posicaoId, motivo]`, como usados
  no §4.4), adicionei `totalProcessadas/totalSucessos/totalFalhas/falhasFormatadas()`.
  O `falhasFormatadas()` já entrega o shape `{posicao_id, motivo}` da API (§5.2.4) e
  da persistência (§3.2.9), sem lógica de formatação espalhada.

### D-305 · Domínio usa `float`; persistência usa `decimal`
- **Decisão.** As classes de domínio seguem o código de referência do §4 (tipos
  `float`). A exatidão monetária é responsabilidade da borda: casts `decimal:` nos
  Models (Parte 5) e arredondamento a 2 casas no serviço/API. Coerente com a
  premissa do `UNIT-TESTS.md` §3.

---

## Parte 4 — Testes unitários do domínio

### D-401 · Testes de domínio são PHP puro (sem `TestCase`/banco)
- **Decisão.** `tests/Pest.php` vincula `TestCase + RefreshDatabase` apenas a
  `Feature`. `Unit/Dominio` roda sem framework e sem banco (UNIT-TESTS §1),
  mantendo a suíte determinística e rápida (43 testes em ~60ms).

### D-402 · Test doubles tipados em `tests/Doubles/`
- **Contexto.** O esboço do `UNIT-TESTS.md` §8 usa fakes com retorno `?object`.
- **Decisão.** Os fakes (`RepositorioPosicoesFake/PrecosFake/MtmFake`) implementam os
  contratos com os **tipos reais** (`?PrecoReferencia`, `?RegistroMtm`), garantindo
  conformidade de assinatura. O `RepositorioMtmFake` chaveia por `(posicaoId|data)`,
  reproduzindo a idempotência do UPSERT (RN-013) no próprio double.

### D-403 · `toBe` vs. `toEqualWithDelta`
- **Decisão.** `toBe` (estrito `===`) onde os valores são exatos em ponto flutuante
  (Futuro/Opção/OTC com números redondos); `toEqualWithDelta(_, 0.001)` em NDF e no
  motor, onde `5,20 − 5,10` e o câmbio introduzem erro de representação — o cuidado
  pedido no `UNIT-TESTS.md` §3.

### D-404 · Teste do `catch` do motor com posição anônima
- **Decisão.** Para cobrir o ramo de exceção do `MotorMtm` (§4.4) e não furar a meta
  de cobertura ≥ 90%, um teste injeta uma subclasse **anônima** de `Posicao` cujo
  `calcularMtm` lança — verificando que a falha é capturada e o processamento segue.

### D-405 · Cobertura medida só no CI
- **Decisão.** Não há Xdebug/PCOV no ambiente local; a medição (`--coverage`) roda
  no CI (`coverage: xdebug`). **Recomendação** do spec: gate `--min=90` para o
  pacote de domínio.

---

## Parte 5 — Infraestrutura

### D-501 · `movimentacoes` exposta na relação `futuro`, não só na `posicao`
- **Contexto.** A FK de `posicao_movimentacao` é `posicao_id` (§3.2.4a), mas o
  código de hidratação do §4.5 lê `$m->futuro->movimentacoes`.
- **Decisão.** `PosicaoFuturoModel::movimentacoes()` é um `hasMany` casando
  `posicao_id` ↔ `posicao_id` (ambas as pontas), reproduzindo `futuro.movimentacoes`
  do §4.5 ao pé da letra. A `PosicaoModel` também tem `movimentacoes()` direta, para
  uso dos serviços (Parte 7).

### D-502 · Colunas de data **não** recebem cast; `decimal:` em todo valor financeiro
- **Contexto.** O §4.5 hidrata datas com `new \DateTimeImmutable($m->data_entrada)`,
  que espera string. Um cast `date` devolveria Carbon e quebraria essa chamada.
- **Decisão.** Manter as colunas de data sem cast (string crua do PostgreSQL) e
  aplicar `decimal:N` (escala da migration) a todos os numéricos. O cast `decimal`
  devolve string, convertida com `(float)` na hidratação — coerente com o §4.5 e com
  a regra "nunca float no banco" (ARQUITETURA §8). Defensivamente, a hidratação usa
  `(string) $m->coluna` antes do `DateTimeImmutable`.

### D-503 · `$timestamps = false` em todos os Models
- **Decisão.** Nenhuma tabela usa o par `created_at/updated_at` do Laravel — a
  auditoria é via `criado_em`/`processado_em`/`iniciado_em` (§3.2). Todos os Models
  desativam timestamps; as colunas de auditoria têm default no banco ou são setadas
  explicitamente (ex.: `processado_em = now()` no upsert).

### D-504 · `$fillable` explícito em todos os Models
- **Decisão.** Cada Model declara `$fillable` com suas colunas graváveis (sem `id`
  nem `criado_em`). Antecipa a mitigação de mass assignment (CWE-915) da Parte 12 e
  habilita o `updateOrCreate` do repositório de MtM.

### D-505 · Idempotência do MtM via `updateOrCreate(posicao_id, data_calculo)`
- **Decisão.** `RepositorioMtmEloquent::upsert` usa `updateOrCreate` com chave
  `(posicao_id, data_calculo)` — espelha a constraint UNIQUE do §3.2.8 e cumpre
  RN-013. `processado_em` é atualizado a cada reprocessamento; `execucao_id` fica
  para a Parte 8 (ver D-303).

### D-506 · Binds via propriedade `$bindings` num provider dedicado
- **Decisão.** `RepositorioServiceProvider` declara os binds contrato→implementação
  na propriedade `$bindings` (registro deferido nativo do Laravel), em vez de
  `register()`. Registrado em `bootstrap/providers.php`. Com isso o `MotorMtm` (§4.4)
  é **auto-resolvido** pelo container (verificado por script descartável). O contrato
  `FontePrecos` **não** é ligado aqui — seu adaptador CSV chega na Parte 6.

### D-507 · Integração com banco fica para a Parte 11
- **Contexto.** As migrations usam recursos exclusivos do PostgreSQL (índice parcial,
  `JSONB`); o ambiente local não tem Postgres no ar (Docker desligado).
- **Decisão.** A Parte 5 foi validada por **lint**, **resolução de binds no container**
  (sem I/O) e pela suíte unitária (43 verdes). Os testes de **hidratação §4.5 contra
  o banco real** (round-trip Model→domínio) são da Parte 11 (`spec-integration-tests`).

---

## Parte 6 — Módulo Preços (e Produtos)

### D-601 · Estender o contrato `RepositorioPrecos` (um repositório por agregado)
- **Contexto.** O contrato existente só tinha `buscar()` (leitura do motor, §4.4). A
  Parte 6 precisa de escrita/consulta (§3.4 da spec da parte).
- **Decisão.** Estendido o **mesmo** contrato com `listar`, `buscarPorId`,
  `existePorProdutoData` (RN-007), `estaReferenciadoEmMtm` (RN-010a), `existe`,
  `salvar`, `remover` — em vez de criar um contrato separado de escrita. Um
  repositório por agregado, como recomendado pela spec.
- **Nota.** Adicionei `existe()` (além da lista da spec) para o serviço distinguir
  404 (preço inexistente) de 409 (RN-010a) na remoção.

### D-602 · Produtos via contrato `RepositorioProdutos` + VO `Produto`
- **Contexto.** §3.4 pedia avaliar contrato vs. uso direto do `ProdutoModel`.
- **Decisão.** Criado o contrato `RepositorioProdutos` e o VO `App\Dominio\Produtos\Produto`,
  por **simetria** com os demais agregados e para manter o `ServicoProdutos` livre
  de Eloquent e testável com fake (D-402). O VO é de dados puro (sem comportamento
  de cálculo, diferente de `Posicao`).
- **Alternativa descartada.** `ProdutoModel` direto no serviço — acoplaria a camada
  de aplicação ao Eloquent e dificultaria o teste unitário sem banco.

### D-603 · Listagens paginadas retornam `LengthAwarePaginator` de VOs
- **Decisão.** `listar()` (produtos e preços) devolve o
  `Illuminate\Contracts\Pagination\LengthAwarePaginator` do Laravel, com os itens já
  convertidos para VO de domínio via `->through()`. A paginação (§9.1, 50/pág) é
  tratada como **concern de entrega**; importar o *contrato* de paginação na
  Aplicação é uma dependência de abstração aceitável e mantém os metadados de página
  para os API Resources. Os fakes de teste devolvem um paginator em memória.

### D-604 · VO `ResultadoImportacao` (relatório RN-010)
- **Decisão.** Criado `App\Aplicacao\Precos\ResultadoImportacao` espelhando o padrão
  de `ResultadoProcessamento` (D-304): contadores (`total`, `aceitas`, rejeitadas) e
  `rejeitadasFormatadas()` no shape `{linha, motivo}` da API. `linha` é o número da
  linha de **dados** (sem o cabeçalho).

### D-605 · Exceções de aplicação genéricas mapeadas ao envelope
- **Contexto.** A spec da parte citou `PrecoDuplicadoException`/`PrecoReferenciadoException`;
  a ARQUITETURA §9 define exceções genéricas por status.
- **Decisão.** Seguir a ARQUITETURA §9: `ErroAplicacao` (base) + `ErroValidacao` (422),
  `ErroConflito` (409), `ErroNaoEncontrado` (404), cada uma carregando um **código
  estável** (`erro`). Os casos específicos viram códigos: `preco_duplicado` (RN-007),
  `preco_referenciado` (RN-010a), `preco_invalido` (RN-008), `cambio_invalido` (RN-009)
  etc. Um único handler em `bootstrap/app.php` (`withExceptions`) traduz tudo para o
  envelope `{ erro, mensagem }` nas rotas `api/*`, junto de `ValidationException` (422
  com `detalhes`), 404 e 401. Reusável nas Partes 7–9.

### D-606 · Bind `FontePrecos`/`AnalisadorPrecosCsv` → `ImportadorPrecosCsv` (fecha D-506)
- **Decisão.** O `ImportadorPrecosCsv` implementa **duas** portas: `FontePrecos`
  (ingestão — D-506) e o novo `AnalisadorPrecosCsv` (parsing do upload). Ambas
  ligadas no `RepositorioServiceProvider`. No MVP não há pull automático, então
  `obterPrecosDoDia()` retorna `[]`; a ingestão real é o upload via `analisar()`.
- **Segurança (SECURITY §C10/RN-010).** O parsing rejeita células iniciadas por
  `= + - @` (formula injection, CWE-1236), limita o nº de linhas (anti-DoS) e valida
  cabeçalho/colunas. RNs de negócio ficam no `ServicoPrecos` (linhas ruins não
  abortam o lote — RN-010).

### D-607 · Form Requests validam estrutura; serviço é a fonte das RNs
- **Decisão.** Os Form Requests (`Http/Requests`) cobrem tipo/formato/obrigatórios; as
  regras de negócio (RN-007/008/009 + existência de produto, nome único) vivem nos
  serviços. Assim a mesma validação é reusada pelo upload CSV (linha a linha) e fica
  coberta por testes unitários sem banco.

### D-608 · `routes/api.php` registrado em `bootstrap/app.php`; nomes de API prefixados
- **Decisão.** Adicionado `api: routes/api.php` ao `withRouting` (prefixo `api`,
  endpoints sob `/api/v1`). O `apiResource` de produtos usa `->names('api.produtos')`
  para **não colidir** com as rotas web Livewire `produtos.index`/`precos.index`.
- **Dependência nova.** `livewire/livewire` adicionado ao `composer.json` (telas 3 e 4).

### D-609 · Testes de integração da API escritos, executados na Parte 11
- **Decisão.** Os testes de `tests/Feature/Api` (endpoints, upload multipart, envelope,
  409 da RN-010a, paginação) já estão escritos, mas dependem de PostgreSQL +
  `RefreshDatabase` (D-507) — executam na Parte 11. A suíte **unitária** (serviços +
  importador, RN-007..010a, anti-fórmula) roda sem banco e está verde.

---

## Parte 8 — Módulo Motor MtM

### D-801 · `ServicoMotor` orquestra `MotorMtm` + auditoria `motor_execucao`
- **Decisão.** `App\Aplicacao\Motor\ServicoMotor` é a camada de aplicação do motor
  (§2.1.3): abre a execução, invoca `MotorMtm::processarDia()` (domínio puro), aplica
  a RN-014 e fecha a execução, devolvendo um VO `ResumoExecucao` (§5.2.4). O **núcleo
  de cálculo da Parte 3 não foi reescrito** — só recebeu um parâmetro opcional
  `?int $execucaoId` em `processarDia()`, repassado ao `upsert` (ver D-803). O domínio
  permanece sem dependência de Eloquent; toda a infra entra por contratos.

### D-802 · Reprocesso registra nova execução de auditoria; MtM idempotente
- **Decisão.** Cada disparo de `processar()` grava **uma nova** linha em
  `motor_execucao` (cada disparo é um evento auditável), enquanto o `mtm_diario`
  permanece idempotente por `(posicao_id, data_calculo)` (RN-013). Reprocessar a mesma
  data não duplica MtM, mas o histórico de execuções cresce — comportamento desejado.

### D-803 · `mtm_diario.execucao_id` via parâmetro opcional no `upsert` + proveniência
- **Decisão.** Adotada a opção (a): `RepositorioMtm::upsert(..., ?int $execucaoId = null)`.
  O `ServicoMotor` abre a execução antes do cálculo e passa o id por
  `MotorMtm::processarDia($data, $execucaoId)`, que o repassa ao `upsert` — mudança
  mínima, motor enxuto.
- **Proveniência (crítica item 2).** `RepositorioMtmEloquent::upsert` faz *short-circuit*:
  se a linha já existe e **todos** os valores financeiros (`preco_mercado`, `mtm_valor`,
  `variacao_dia`, `pl_acumulado`) batem na escala da coluna (preço 6 casas, demais 2),
  **não toca a linha** — `execucao_id`/`processado_em` continuam apontando para a
  execução que de fato calculou o valor. Só recálculos que mudam algo carimbam a nova
  execução. O `RepositorioMtmFake` reproduz esse short-circuit para os testes unitários.

### D-804 · RN-014 marca vencidas só por sucesso ∩ vencimento (anti-lock-out)
- **Decisão.** O `ServicoMotor` calcula o conjunto a vencer = `idsAbertasVencendoEm(data)`
  **∩** `ResultadoProcessamento::$sucessos` e chama
  `RepositorioPosicoes::marcarVencidas(array $ids)` (UPDATE `status='VENCIDA'` com guard
  `status='ABERTA'`). Ou seja, **só vence quem foi marcado a mercado com sucesso no dia
  do vencimento.**
- **Por quê (crítica item 1).** Um UPDATE cego por data venceria também posições que
  falharam por preço ausente (RN-012), travando-as para sempre fora do reprocessamento
  (RN-011 só processa `ABERTA`). Mantendo-as `ABERTA`, o operador cadastra o preço,
  reprocessa o dia D e só então elas vencem. A alternativa de flexibilizar a RN-011 para
  reprocessar `VENCIDA` foi **descartada** por exigir mudança na semântica do motor de
  domínio (já testado, Parte 4).

### D-805 · Contrato `RepositorioExecucoes` + impl. Eloquent + bind
- **Decisão.** `App\Aplicacao\Contratos\RepositorioExecucoes` (`abrir`/`fechar`/`listar`/
  `buscarPorId`) com impl. `RepositorioExecucoesEloquent` sobre `MotorExecucaoModel`,
  hidratando o VO `ResumoExecucao`. Bind no `RepositorioServiceProvider` (`$bindings`,
  D-506). A leitura **tolera `finalizado_em = NULL`** (execuções abertas/"zumbis", §3.3).

### D-806 · Scheduler: comando `motor:processar` + `Schedule` em `routes/console.php`
- **Decisão.** `ProcessarMotorCommand` (`motor:processar --data= --por=`) chama o
  `ServicoMotor` e imprime o resumo; `--data` default = hoje. Agendado em
  `routes/console.php` com `Schedule::command('motor:processar --por=agendador')
  ->weekdays()->dailyAt('19:00')` (dias úteis, horário comercial, §9.3). O cron do
  servidor (`php artisan schedule:run`) recebe hardening na Parte 13.

### D-807 · Tela Livewire 7 incluída (corte vertical)
- **Decisão.** A tela 7 "Execução do motor" (§6.1.7) foi **incluída** nesta parte como
  corte vertical: `App\Livewire\Motor\ExecutarMotor` + view + rota web `/motor` + item de
  navegação. Date picker para disparar o dia, histórico de execuções com drill-down das
  falhas.

### D-808 · Livewire injeta `ServicoMotor`; API REST em paralelo (sem self-call)
- **Decisão.** A tela Livewire injeta e chama o `ServicoMotor` **diretamente** —
  `disparado_por = "ui"` — sem requisição HTTP à própria API. A API REST `/api/v1/motor/*`
  (`disparado_por = "api"`) existe em paralelo, como contrato para consumidores externos
  (§5), compartilhando o mesmo serviço. Evita self-call e duplicação de serialização
  (crítica item 5).

### Notas de evolução (não-decisões da Parte 8)
- **Execuções "zumbis" → Parte 13 (crítica item 3).** Um *fatal error* do PHP pode
  deixar `motor_execucao.finalizado_em = NULL` para sempre. *Cleanup*/`timeout` que marque
  um estado terminal fica para a Parte 13 (observabilidade). Por ora, a leitura tolera o
  NULL sem quebrar (D-805).
- **`falhas` JSONB → tabela 1:N (crítica item 4).** Para a meta de 1.000 posições (§9.1)
  o JSONB atende. Se o volume escalar para dezenas de milhares com falha massiva,
  normalizar para `motor_execucao_falhas` — evolução futura, não implementada agora.

### D-809 · Testes de integração do motor escritos, executados na Parte 11
- **Decisão.** `tests/Feature/Api/MotorApiTest` (fluxo ponta a ponta, idempotência no
  banco, RN-014 mudando `status`, envelope/validação, paginação) e
  `tests/Feature/Console/ProcessarMotorCommandTest` já estão escritos, mas dependem de
  PostgreSQL + `RefreshDatabase` (D-507/D-609) — executam na Parte 11. A suíte
  **unitária** (`ServicoMotorTest`, com fakes) roda sem banco e está verde.

---

## Parte 7 — Módulo Posições (e Movimentações)

### D-701 · Estender o contrato `RepositorioPosicoes` (escrita/consulta), não criar novo
- **Decisão.** O contrato — antes só-leitura para o motor (`buscarAbertas`/`buscarPorId`/
  `idsAbertasVencendoEm`/`marcarVencidas`) — recebeu `listar`, `detalhar`,
  `buscarParaAtualizar`, `criarFuturo/Ndf/Opcao/Otc`, `registrarMovimentacao`,
  `atualizarQuantidadeStatus`, `encerrar`, `remover`, `emTransacao`. Um repositório por
  agregado, por simetria com D-601 (Parte 6 fez o mesmo com `RepositorioPrecos`).

### D-702 · Persistência por método `criar*` tipado (não `salvar(Posicao, dadosFilha)`)
- **Decisão.** Cada instrumento tem seu `criar*(array $dados): int` no repositório,
  recebendo o array **normalizado** pelo serviço. Mais legível que um `salvar` genérico
  e evita reconstruir o domínio só para persistir (o domínio `Posicao` não carrega
  `mercado`/`contraparte`/`observacoes`/`criado_por` — campos de cadastro, não de cálculo).

### D-703 · Transação da criação no repositório; da movimentação no serviço
- **Decisão.** A criação composta (mãe + filha + `ABERTURA`) é uma persistência atômica
  do agregado → `DB::transaction` **dentro** do `criar*` do repositório. O registro de
  movimentação precisa de orquestração (lock → validação RN-022 → replay do domínio →
  update) → transação no **serviço**, via `RepositorioPosicoes::emTransacao` (D-720).

### D-704 · Read models (`PosicaoResumo`/`PosicaoDetalhe`/`MovimentacaoDetalhe`/`EstadoMovimentacao`)
- **Contexto.** O domínio `Posicao` (cálculo) não tem `instrumento`/`mercado`/
  `contraparte`/`observacoes`, necessários à listagem/detalhe da API e UI.
- **Decisão.** DTOs de leitura em `app/Aplicacao/Posicoes/`, hidratados pelo repositório.
  `PosicaoResumo` (listagem leve — a `quantidade` exibida é a coluna da mãe, em sincronia
  por RN-024, sem replay por linha); `PosicaoDetalhe` (inclui, para FUTURO, preço médio/
  quantidade atual/P&L realizado derivados do domínio + histórico); `EstadoMovimentacao`
  (resposta 200 de `POST .../movimentacoes`).

### D-705 · Reuso do domínio no serviço (sem recalcular preço médio à mão)
- **Decisão.** `ServicoMovimentacoes` insere a movimentação, **recarrega** o `Futuro`
  (com a nova) e lê `precoMedio()`/`quantidadeAtual()`/`plRealizado()` — a fórmula vive
  num único lugar (§4.3.1), já coberta pelos testes de domínio (Parte 4). A prévia do
  modal usa o mesmo domínio (D-714).

### D-706 · Placeholder `criado_por` (`"api"`/`"ui"`) até a Parte 10
- **Decisão.** Sem autenticação ainda (RBAC é Parte 10), o `criado_por` da posição e o
  da movimentação recebem `"api"` (controllers) ou `"ui"` (Livewire), como nas Partes 6/8.

### Decisões da crítica técnica (§7-bis da spec)

### D-710 · RN-006 reinterpretada: validar `produto_id`; `indexador` é rótulo livre
- **Decisão.** `produto` não tem coluna de código/indexador e `OTC::calcularMtm` precifica
  pelo `produto_id`. `criarOtc` valida a **existência de `produto_id`** (422 se ausente) e
  trata `indexador` como texto livre (`VARCHAR(30)`), sem lookup. Sem coluna nova (fora do MVP).

### D-711 · `Movimentacao` carrega `sequencia` (id); `replay` desempata por ela *(ajuste de domínio)*
- **Decisão.** O VO `Movimentacao` ganhou `?int $sequencia` (a ordem de inserção/`id`); o
  `Futuro::replay` ordena por `[data, ABERTURA-primeiro, sequencia]`, alinhando ao índice
  `idx_mov_posicao_data` e tornando determinístico o estado quando AUMENTO e REDUCAO caem
  na mesma data (o `usort` não é estável). O repositório hidrata `sequencia = id`. Cobertura:
  teste de domínio em `FuturoTest` (mesma data, sem quantidade negativa no meio do replay).

### D-712 · Comparações de quantidade arredondadas a 4 casas (escala da coluna)
- **Decisão.** O guard da RN-022 e a decisão de encerrar (saldo == 0) operam sobre
  `round($q, 4)` (escala de `quantidade NUMERIC(18,4)`), evitando resíduo de `float`
  (domínio em `float`, D-305). "Redução total" = saldo arredondado igual a zero; "excede"
  = `round(reducao,4) > round(saldo,4)`.

### D-713 · Lock pessimista no registro de movimentação
- **Decisão.** `ServicoMovimentacoes::registrar` roda em `emTransacao` e lê a posição com
  `RepositorioPosicoes::buscarParaAtualizar` (`lockForUpdate` na linha de `posicao`) antes
  de validar RN-022 — serializa reduções concorrentes (evita superredução → CHECK
  `quantidade >= 0` viraria 500 em vez de 422/409). Mesma proteção cobre o encerramento
  por redução total.

### D-714 · `pl_realizado` da API = acumulado; prévia da UI = delta
- **Decisão.** O `pl_realizado` da resposta de `POST .../movimentacoes` e do detalhe é o
  **acumulado** (`Futuro::plRealizado()`), coerente com `pl_acumulado` do MtM. A prévia do
  modal (§6.4) mostra o **incremento** da operação, calculado por diferença sobre o domínio
  em `ServicoMovimentacoes::prever` (VO `PreviaMovimentacao`) — sem duplicar a fórmula.

### D-715 · `mercado` e `lado` obrigatórios no Form Request (com `in:`)
- **Decisão.** `posicao.mercado` é NOT NULL + CHECK; os Form Requests de criação exigem
  `mercado` (`required|in:BOLSA,BALCAO`) e `lado` (`required|in:COMPRADO,VENDIDO`). A RN-003
  (BALCAO exige contraparte) é avaliada no serviço com `mercado` já garantido. Sem default.

### D-716 · Teto de `data_movimentacao`: `<= data_vencimento` e `<= hoje`
- **Decisão.** Além da RN-025 (`>= data_entrada`), a movimentação rejeita (422
  `movimentacao_invalida`) data **posterior ao vencimento** ou **no futuro**. "Hoje" vem de
  `CarbonImmutable::now()` (congelável nos testes com `setTestNow`). A criação mantém só a
  RN-002 (entrada pode ser retroativa).

### D-717 · `encerrar` de FUTURO com saldo > 0 → 409 (`posicao_com_saldo`)
- **Decisão.** `POST /posicoes/{id}/encerrar` bloqueia FUTURO com `quantidade_atual`
  (arredondada a 4 casas) > 0, forçando a **redução total** (RN-022), que realiza o P&L.
  NDF/OPCAO/OTC encerram direto pelo guard `status = ABERTA`.

### D-718 · `RepositorioMtm::existePorPosicao` (substitui `temMtm` em `RepositorioPosicoes`)
- **Decisão.** O bloqueio do DELETE consulta `RepositorioMtm::existePorPosicao(int): bool`
  (o dado vive em `mtm_diario`, agregado do MtM) — `ServicoPosicoes::remover` → 409
  `posicao_com_mtm`. Mais aderente à propriedade do dado que um método em `RepositorioPosicoes`.

### D-719 · Extensões além da `requisitos.md`
- **Filtro `instrumento`** na listagem (`?instrumento=`) e o filtro "tipo" da tela 6, além
  do §5.2.3 (`?status=&produto_id=`). **OPCAO** força `quantidade = 1` na mãe (ignora — não
  rejeita — o que o cliente enviar). **`quantidade > 0` por perna** validada no serviço
  (não só no CHECK do banco), com teste.

### D-720 · `emTransacao` no contrato (mantém `DB` fora da Aplicação)
- **Contexto.** D-713 exige a transação no serviço, mas a Aplicação não deve importar o
  facade `DB` (e os testes unitários rodam sem container/facade).
- **Decisão.** `RepositorioPosicoes::emTransacao(\Closure): mixed` — o Eloquent delega a
  `DB::transaction`; o fake executa o callback direto. O serviço envolve o registro de
  movimentação sem acoplar a Aplicação ao facade, mantendo a suíte unitária pura (D-402).

### Nota de evolução — PHPStan na hidratação (não-decisão)
- `RepositorioPosicoesEloquent` acumula `property.nonObject` (acesso a `$m->futuro->…` em
  relação `Model|null`) e `match.unhandled` — **mesma família** já presente no `hidratar`
  original (pré-existente, catalogada no `next_steps.md` item 0). Os novos métodos de
  leitura (`dadosTipo`/`movimentacoesDetalhe`) seguem o **mesmo padrão aceito**; a limpeza
  unificada (genéricos nas relações / null-handling) fica para a revisão de PHPStan já
  agendada. **Sem** `@phpstan-ignore`/baseline. Os dois erros realmente novos (comparação
  estrita no filtro de `listar` e `->pernas()` em Model sem genérico) foram corrigidos aqui.

---

**Fim do documento.**
