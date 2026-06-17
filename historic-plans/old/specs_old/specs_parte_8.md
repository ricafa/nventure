# Parte 8 — Módulo Motor MtM

Requisitos executáveis da **Parte 8** do `next_steps.md`. A fonte da verdade continua
sendo `specs/requisitos.md` (v1.4); este documento é o **recorte da Parte 8** —
referencia as §/RN de origem para rastreabilidade.

> **Decisões de escopo:**
> 1. A Parte 8 cobre o **Módulo motor MtM** (§2.1.3): `ServicoMotor` (orquestração +
>    idempotência RN-013 + persistência de `motor_execucao` + RN-014 vencidas),
>    **scheduler** (Artisan command + `routes/console.php`) e **API REST** do motor
>    (§5.2.4).
> 2. O **núcleo de cálculo já existe** (`App\Dominio\Motor\MotorMtm`, Parte 3) — a
>    Parte 8 **não reescreve o motor**; constrói a camada de aplicação/infra ao redor.
> 3. **Autenticação/RBAC adiada para a Parte 10** — endpoints abertos nesta parte.
> 4. **Telas** (tela 7 "Execução do motor", §6.1) podem ficar **fora** desta parte se
>    o corte for por camada; ver §6 (incluídas aqui como corte vertical recomendado).

---

## 1. Escopo

**Inclui**
- `ServicoMotor` (`app/Aplicacao/Motor/`): dispara `MotorMtm::processarDia()`,
  persiste a execução em `motor_execucao` (§3.2.9), grava `execucao_id` no
  `mtm_diario`, aplica RN-014 (vencidas) e devolve o resumo (§5.2.4).
- **Idempotência** de ponta a ponta (RN-013): reprocessar a mesma data atualiza
  registros e a própria execução, sem duplicar.
- **Histórico de execuções**: consulta de `motor_execucao` (listagem + detalhe).
- **API REST** `/api/v1/motor/*` (§5.2.4) com envelope de erro (§5.1).
- **Scheduler**: comando Artisan (`motor:processar`) + entrada no
  `routes/console.php` (Laravel Task Scheduler, §2.2).

**Não inclui (fora da Parte 8)**
- Autenticação/Sanctum e RBAC/Policies (§9.2) → **Parte 10**.
- Testes de integração contra PostgreSQL com `RefreshDatabase` → **Parte 11**
  (os casos são definidos aqui; execução é da Parte 11 — ver D-507/D-609).
- Relatórios consolidados (posição aberta, P&L, exposição) → **Parte 9**.
- Cálculo em si das subclasses de `Posicao` (§4) — já entregue nas Partes 3/4.

**Dependências:**
- **Parte 5** (Infraestrutura) — concluída: `RepositorioMtmEloquent`,
  `RepositorioPosicoesEloquent`, `MotorExecucaoModel`, binds no provider.
- **Parte 3** (Domínio) — concluída: `MotorMtm`, `ResultadoProcessamento`,
  `RegistroMtm`.
- **Parte 7** (Posições) — **recomendada para dados ponta a ponta** (criar posições
  para o motor processar). O motor e seus testes unitários **não dependem** da Parte 7
  (usam *fakes* dos contratos, D-402); a integração ponta a ponta sim. Se a Parte 7
  ainda não existir, validar com seeders/factories das Partes 2/5.

**Regras de negócio cobertas:** RN-011, RN-012, RN-013, RN-014, RN-015
(contexto correlato: RN-016..019 ficam para a Parte 9).

---

## 2. Estado já existente (reutilizar — não recriar)

| Camada | Artefato | Observação |
|---|---|---|
| Domínio | `app/Dominio/Motor/MotorMtm.php` | `processarDia(\DateTimeImmutable)` — loop polimórfico, RN-011/012/015 já aplicadas. **Não reescrever.** |
| Domínio | `app/Dominio/Motor/ResultadoProcessamento.php` | `sucessos[]`, `falhas[]`, `totalProcessadas/Sucessos/Falhas()`, `falhasFormatadas()` (shape `{posicao_id, motivo}`, §5.2.4). |
| Domínio | `app/Dominio/Motor/RegistroMtm.php` | VO do MtM anterior (D-301). |
| Contrato | `app/Aplicacao/Contratos/RepositorioMtm.php` | `buscarUltimoAnterior()`, `upsert(...)` idempotente (RN-013). `upsert` **não tem `execucao_id`** hoje (D-303) — ver §3.4 (decisão D-803). |
| Contrato | `app/Aplicacao/Contratos/RepositorioPosicoes.php` | `buscarAbertas()` (RN-011), `buscarPorId()`. **A estender** para RN-014 (§3.4). |
| Contrato | `app/Aplicacao/Contratos/RepositorioPrecos.php` | `buscar(produtoId, data)` (leitura usada pelo motor). |
| Infra | `app/Infraestrutura/Repositorios/RepositorioMtmEloquent.php` | Idempotência via `updateOrCreate(posicao_id, data_calculo)` (D-505). |
| Infra | `app/Infraestrutura/Repositorios/RepositorioPosicoesEloquent.php` | Hidratação factory/`match` (§4.5). |
| Infra | `app/Infraestrutura/Models/MotorExecucaoModel.php` | `$table = 'motor_execucao'`, `$fillable`/`$casts` prontos (`falhas` JSONB, datas). |
| Infra | `app/Infraestrutura/Models/MtmDiarioModel.php` | Possui coluna `execucao_id` (FK, §3.2.8). |
| Bind | `app/Providers/RepositorioServiceProvider.php` | Binds via `$bindings` (D-506) — **adicionar** novos contratos (§3.4). |
| API | `routes/api.php` + `bootstrap/app.php` | Grupo `/api/v1` já registrado (D-608); handler de erros central (D-605). |
| Padrões | Exceções de aplicação → envelope (D-605); `tests/Doubles/` (D-402). | Reusar. |

---

## 3. Camada de aplicação — `app/Aplicacao/Motor/`

### 3.1 `ServicoMotor`
Orquestra a execução completa e a auditoria (§2.3, §3.2.9). Responsabilidades:

- `processar(\DateTimeImmutable $dataCalculo, string $disparadoPor): ResumoExecucao`
  1. **Abre** um registro em `motor_execucao` (`iniciado_em = now()`,
     `finalizado_em = NULL`, `disparado_por`).
  2. Invoca `MotorMtm::processarDia($dataCalculo)` (domínio) — RN-011/012/013/015.
  3. **RN-014** — após o processamento, marca como `VENCIDA` **apenas** as posições
     cujo `data_vencimento = dataCalculo` **e que foram processadas com sucesso** neste
     dia (estão em `ResultadoProcessamento::$sucessos`). Posições que venceriam hoje
     mas **falharam** (ex.: preço ausente, RN-012) **permanecem `ABERTA`** para poder
     receber o MtM do último dia num reprocessamento — ver §3.4 / D-804 e o risco de
     *lock-out* descrito ali.
  4. **Fecha** a execução: `finalizado_em = now()`, `total_posicoes`, `sucessos`,
     `falhas` (JSONB no shape `falhasFormatadas()`; ver nota de escalabilidade em §3.3).
  5. Devolve um **`ResumoExecucao`** com `execucao_id`, `data_calculo`,
     `posicoes_processadas`, `sucessos`, `falhas[]` (§5.2.4).
- `listarExecucoes(paginacao): LengthAwarePaginator` — histórico (D-603).
- `detalharExecucao(int $id): ResumoExecucao` — 404 se não existir.

> **Idempotência (RN-013):** o `upsert` do `mtm_diario` já é idempotente por
> `(posicao_id, data_calculo)` (D-505). Reprocessar a mesma data **não duplica MtM**.
> Decidir o comportamento de `motor_execucao` no reprocesso (D-802): registrar **uma
> nova** execução de auditoria por disparo (recomendado — cada disparo é um evento) e
> manter o histórico, sem violar a unicidade do `mtm_diario`.
>
> **Preservar a proveniência do cálculo (D-803):** no reprocesso, o `upsert` só deve
> sobrescrever `execucao_id` e `processado_em` **quando os valores financeiros
> (`mtm_valor`, `variacao_dia`, `pl_acumulado`, `preco_mercado`) efetivamente mudarem**.
> Se o recálculo produz exatamente os mesmos valores, **não tocar** na linha — assim o
> `execucao_id`/`processado_em` continuam apontando para a execução que de fato
> *calculou* aquele valor, e não para a última reexecução “a vazio”. Isso mantém a
> auditoria fiel (§2.3).

### 3.2 VO `ResumoExecucao`
Espelha o shape da resposta §5.2.4 e desacopla a API do Model Eloquent:
- `execucaoId: int`, `dataCalculo: \DateTimeImmutable`,
- `posicoesProcessadas: int`, `sucessos: int`,
- `falhas: array<{posicao_id:int, motivo:string}>`,
- (opcional) `iniciadoEm`/`finalizadoEm` para o detalhe.

### 3.3 Transação e robustez
- O passo 2–4 deve rodar de forma que uma falha geral feche a execução com estado
  coerente (registrar erro; `finalizado_em` preenchido). Falhas **por posição** já são
  capturadas pelo `try/catch` do `MotorMtm` (D-404) e vão para `falhas` — **não**
  abortam o lote (RN-012).
- Performance (§9.1): 1.000 posições em < 30 s. Manter eager loading (§4.5) e evitar
  N+1; considerar transação/`chunk` apenas se necessário.
- **Execuções “zumbis” (débito técnico — Parte 13).** Um *fatal error* do PHP
  (`Allowed memory size exhausted`, perda de conexão) **não** é capturável por
  `try/catch` e pode matar o processo, deixando `motor_execucao` com
  `finalizado_em = NULL` para sempre — o que polui a UI/auditoria. Está **fora do
  escopo da Parte 8**, mas fica registrado: prever na **Parte 13** (observabilidade/
  deploy) um *cleanup*/`timeout` que marque execuções com `finalizado_em = NULL` há
  mais de X horas (estado terminal, ex.: `INCONCLUSIVA`/`FALHA_CATASTROFICA`). Para o
  MVP, a leitura de execuções deve ao menos **tolerar** `finalizado_em = NULL` sem
  quebrar.
- **Escalabilidade do JSONB de `falhas` (bandeira, não bloqueante).** Para a meta atual
  (1.000 posições, §9.1) o array JSONB em `motor_execucao.falhas` atende bem. Se o
  volume escalar para dezenas de milhares com falha massiva (ex.: ingestão de preços
  quebrada), o JSONB pode ficar grande e pesar na (de)serialização do Eloquent. Mitigar
  só quando necessário, normalizando para uma tabela filha `motor_execucao_falhas`
  (1:N) — registrar como evolução futura, **não** implementar agora.

### 3.4 Contratos — extensão (decisões a registrar)
- **`execucao_id` no `mtm_diario` (D-803):** o `upsert` do `RepositorioMtm` hoje não
  recebe `execucao_id` (D-303). Para preencher `mtm_diario.execucao_id` (§3.2.8),
  escolher e registrar:
  - (a) adicionar `?int $execucaoId = null` ao `upsert` (mín. mudança no contrato), **ou**
  - (b) novo método `vincularExecucao(...)`, **ou**
  - (c) injetar o `execucao_id` no `MotorMtm` (passar adiante ao `upsert`).
  Recomendação: **(a)** — parâmetro opcional, mantém o motor enxuto.
  **Refinamento (proveniência):** a implementação Eloquent (D-505) deve sobrescrever
  `execucao_id`/`processado_em` **apenas quando algum valor financeiro mudar** — se o
  recálculo é idêntico, a linha não é tocada (ver nota em §3.1). Comparar os campos
  antes do `save()` (ou `updateOrCreate` apenas com os novos valores e *short-circuit*
  quando não houver `isDirty()` financeiro).
- **RN-014 — marcar vencidas, evitando *lock-out* (D-804):** adicionar ao
  `RepositorioPosicoes` (ou novo `RepositorioPosicoesEscrita`) um método de marcação
  por **lista de IDs**, ex. `marcarVencidas(array $posicaoIds): int` (UPDATE
  `status='VENCIDA'` onde `id IN (:ids) AND status='ABERTA'`).
  - O `ServicoMotor` calcula o conjunto a marcar = posições com
    `data_vencimento = dataCalculo` **∩** `ResultadoProcessamento::$sucessos`. Ou seja,
    **só vence quem foi marcado a mercado com sucesso no dia do vencimento.**
  - **Por quê:** se usássemos um UPDATE cego por data
    (`status='ABERTA' AND data_vencimento = :data`, rodado após o cálculo), uma posição
    que vence hoje **e** falhou por falta de preço (RN-012) viraria `VENCIDA` e nunca
    mais seria reprocessada (RN-011 só processa `ABERTA`) — ficaria **permanentemente
    sem o MtM do seu último dia**. Mantendo-a `ABERTA`, o operador cadastra o preço e
    **reprocessa o dia D**, agora com sucesso, e só então ela vence.
  - Alternativa considerada e descartada: flexibilizar a RN-011 para reprocessar
    `VENCIDA` cujo `data_vencimento >= data_calculo`. Rejeitada por exigir mudança na
    semântica do motor de domínio (já testado, Parte 4) e tornar o `buscarAbertas()`
    dependente da data; a abordagem por sucesso resolve no serviço, sem tocar o domínio.
- **`RepositorioExecucoes` (D-805):** contrato + implementação Eloquent
  (`RepositorioExecucoesEloquent` sobre `MotorExecucaoModel`) para abrir/fechar/listar/
  detalhar execuções. **Bind** no `RepositorioServiceProvider` (`$bindings`, D-506).

---

## 4. Scheduler — `app/Console/Commands/` + `routes/console.php`

- **Comando Artisan** `motor:processar` (`ProcessarMotorCommand`):
  - argumento/opção `--data=YYYY-MM-DD` (default: **hoje**);
  - `--por=agendador` para `disparado_por` (default `"agendador"` no agendamento);
  - chama `ServicoMotor::processar()` e imprime o resumo (sucessos/falhas) no console.
- **Agendamento** (§2.2, §10): registrar em `routes/console.php`
  `Schedule::command('motor:processar')->dailyAt('...')` (ou `weekdays()` — horário
  comercial, §9.3). Documentar a entrada de cron `php artisan schedule:run` no
  `CLAUDE.md`/deploy (Parte 13 fará o hardening).

---

## 5. API REST — `app/Http/` (§5.2.4)

Rotas sob `/api/v1/motor` em **`routes/api.php`** (grupo já existente, D-608).

### 5.1 Endpoints
```
POST /api/v1/motor/processar         Dispara processamento para uma data
GET  /api/v1/motor/execucoes         Histórico de execuções (paginado)
GET  /api/v1/motor/execucoes/{id}    Detalhe (sucessos, falhas)
```

**`POST /motor/processar` — payload (§5.2.4):**
```json
{ "data_calculo": "2026-05-23" }
```

**Resposta (200) (§5.2.4):**
```json
{
  "execucao_id": 47,
  "data_calculo": "2026-05-23",
  "posicoes_processadas": 23,
  "sucessos": 22,
  "falhas": [
    { "posicao_id": 18, "motivo": "Preço não cadastrado para a data" }
  ]
}
```

### 5.2 Validação, serialização e erros
- **Form Request** (`app/Http/Requests/`) valida `data_calculo` (obrigatória, `date`,
  ISO 8601). Em payload inválido → **422** com envelope (§5.1).
- **API Resources** (`app/Http/Resources/`) serializam `ResumoExecucao` e a listagem
  de execuções: **decimais sem aspas**, datas ISO, `falhas` como array de objetos.
- **Envelope de erro** `{ "erro": "código", "mensagem": "descrição" }` (§5.1) via
  handler central (D-605); status `400/404/422` (e `401/403` quando a Parte 10 ligar
  o RBAC). `GET /execucoes/{id}` inexistente → **404**.
- Paginação 50/pág (§9.1) em `GET /execucoes` (D-603).
- `disparado_por` na API: até a Parte 10, usar um valor fixo (ex.: `"api"`); depois
  passa a ser o usuário autenticado (Sanctum).

---

## 6. UI — Blade + Livewire (§6) — `app/Livewire/Motor/` *(corte vertical recomendado)*

- **Tela 7 — Execução do motor (§6.1.7):** botão para disparar o processamento de uma
  data (date picker) + **histórico de execuções** (tabela com data, disparado por,
  início/fim, sucessos/falhas) e *drill-down* para o detalhe das falhas.
- Rotas Livewire em `routes/web.php`; views em `resources/views/`. Estética: usar
  `mock_telas/` como referência visual.
- **A tela Livewire injeta e chama o `ServicoMotor` diretamente** — **não** faz
  requisição HTTP para a própria API REST (evita *self-call* e duplicação de
  serialização). A API REST `/api/v1/motor/*` existe **em paralelo**, como contrato
  para consumidores externos (§5 é requisito global da spec) — ambos compartilham o
  mesmo `ServicoMotor`. Registrar em `decisions.md` (D-808).
- Se o corte for **por camada**, esta seção pode ser adiada — registrar em
  `decisions.md`.

---

## 7. Testes (§8)

- **Unidade (Pest, sem banco):** `ServicoMotor` com *fakes* dos contratos
  (`tests/Doubles/`, D-402):
  - **RN-013** — reprocessar a mesma data **não duplica** MtM (o *fake* do
    `RepositorioMtm` registra upsert por chave `(posicao, data)`).
  - **RN-012** — posição sem preço entra em `falhas`, processamento **continua**.
  - **RN-014** — posição que vence na data **e processou com sucesso** é marcada
    `VENCIDA` (verificar IDs passados ao repositório).
  - **RN-014 anti-*lock-out*** — posição que vence na data mas **falhou** (preço
    ausente) **continua `ABERTA`**; ao cadastrar o preço e reprocessar o dia D, ela é
    marcada a mercado e só então vence (cenário do item 1 da crítica).
  - **RN-011** — só posições `ABERTA` processadas.
  - **Auditoria** — abre/fecha `motor_execucao`, `total_posicoes`/`sucessos`/`falhas`
    coerentes; `ResumoExecucao` no shape §5.2.4.
  - **execucao_id** — o `upsert` recebe/propaga o `execucao_id` (conforme D-803).
  - **Proveniência no reprocesso** — reexecutar com valores **idênticos** não altera
    `execucao_id`/`processado_em`; reexecutar com preço **diferente** atualiza ambos.
  - O `MotorMtm` (domínio) já tem cobertura na Parte 4; **não** reescrever.
- **Comando Artisan:** teste de `motor:processar` (default = hoje; `--data`).
- **Feature/Integração (definir aqui, executar na Parte 11 — D-507/D-609):** fluxo
  ponta a ponta (produto → preço → posição → `POST /motor/processar` → `mtm_diario`
  populado), idempotência no banco (rodar 2×, conferir 1 linha por posição/dia e
  `execucao_id` atualizado), RN-014 muda `status` no banco, envelope/validação, contra
  PostgreSQL com `RefreshDatabase`.

---

## 8. Decisões a registrar em `decisions.md` (seção "Parte 8")

- **D-801** — `ServicoMotor` na camada de aplicação orquestra `MotorMtm` + auditoria
  (`motor_execucao`); o domínio permanece sem dependência de Eloquent.
- **D-802** — Reprocesso registra **nova** execução de auditoria por disparo; MtM
  permanece idempotente por `(posicao_id, data_calculo)` (RN-013).
- **D-803** — Como gravar `mtm_diario.execucao_id` (parâmetro opcional no `upsert`
  vs. método dedicado vs. injeção). Recomendação: parâmetro opcional. **Refinamento:**
  só sobrescrever `execucao_id`/`processado_em` quando os valores financeiros mudarem,
  preservando a proveniência do cálculo original (crítica item 2).
- **D-804** — RN-014 (vencidas) marcando **apenas** posições que venceram **e**
  processaram com sucesso no dia (lista de IDs vinda de `$sucessos`), em vez de UPDATE
  cego por data — evita o *lock-out* permanente de posições com preço ausente no dia do
  vencimento (crítica item 1). Alternativa de flexibilizar a RN-011 foi descartada.
- **D-805** — Contrato `RepositorioExecucoes` + impl. Eloquent + bind.
- **D-806** — Scheduler: comando `motor:processar` + `Schedule` em
  `routes/console.php`; `disparado_por = "agendador"`.
- **D-807** — (se aplicável) Incluir/adiar a tela Livewire 7 (corte vertical vs. camada).
- **D-808** — Livewire injeta `ServicoMotor` diretamente; a API REST existe em paralelo
  (requisito §5) compartilhando o mesmo serviço — sem *self-call* HTTP (crítica item 5).
- **Notas de evolução (não-decisões da Parte 8):** *cleanup* de execuções zumbis
  (`finalizado_em = NULL`) → **Parte 13** (crítica item 3); normalização de `falhas`
  para tabela 1:N caso o volume escale → evolução futura (crítica item 4).

---

## 9. Critérios de aceite

- [ ] `ServicoMotor` dispara o cálculo, persiste `motor_execucao` (abre/fecha) e
      devolve o resumo §5.2.4.
- [ ] `mtm_diario.execucao_id` é preenchido com a execução que gerou/atualizou a linha;
      reprocesso com valores idênticos **não** sobrescreve `execucao_id`/`processado_em`.
- [ ] **RN-013** — reprocessar a mesma data não duplica `mtm_diario` (verificado por teste).
- [ ] **RN-012** — preço ausente vira falha sem abortar o lote.
- [ ] **RN-014** — posição que vence na data **e processa com sucesso** fica `VENCIDA`;
      posição que vence mas **falha** por preço ausente **continua `ABERTA`** e pode ser
      reprocessada (sem *lock-out*).
- [ ] Endpoints §5.2.4 sob `/api/v1` com envelope de erro §5.1 e paginação no histórico.
- [ ] Comando `motor:processar` funcional e agendado em `routes/console.php`.
- [ ] Decisões registradas em `decisions.md`; novos binds ativos no provider.
- [ ] Suíte unitária verde; casos de integração escritos (execução na Parte 11).
