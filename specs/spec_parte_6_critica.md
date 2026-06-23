# Crítica construtiva — `spec_parte_6.md` (Motor MtM / Fase 6)

> **Autor da revisão:** arquiteto de software sênior.
> **Base da análise:** `specs/spec_parte_6.md` confrontada com `specs/requisitos.md`
> (v1.7, fonte da verdade — §3.2.8/3.2.9, §4.4/§4.5, §5.2.4, §7.3, §9.1), `specs/passos_dev.md`
> (Fase 6) e o **código real** do repositório:
> `app/Models/Posicao.php`, `Futuro.php`, `Ndf.php`, `Opcao.php`, `Otc.php`,
> `MtmDiario.php`, `MotorExecucao.php`, `PrecoReferencia.php`, `Usuario.php`,
> `app/Services/ServicoPosicoes.php`, `app/Services/Dados/EstadoMovimentacao.php`,
> `app/Http/Controllers/Api/V1/PosicaoController.php`, `app/Http/Resources/PosicaoResource.php`,
> e as migrations de `posicao`, `mtm_diario` e `motor_execucao`.
> **Natureza:** endurecimento da spec antes da implementação. Não altera regras de
> negócio nem contratos — onde houver divergência, `requisitos.md` prevalece.

## Veredito geral

A spec é **madura e bem estruturada**: a separação `MotorMtm` (cálculo) × `ServicoMotor`
(orquestração) é fiel ao §4.4; a proveniência condicional (D-604/`isDirty`) é uma melhoria
real sobre o `updateOrCreate` ingênuo do §4.4; a marcação de `VENCIDA` só na interseção
sucesso ∩ vencida (D-605) e o isolamento de falhas (D-606) estão corretos; o `falhas`
associativo (`{posicao_id, motivo}`) corrige o array posicional do exemplo do §4.4 e bate
com §3.2.9; e o polimorfismo do motor (sem `if`/`switch` por tipo) é respeitado. As
decisões estão numeradas, justificadas e rastreadas às RNs e aos rótulos `D-80x` do
`passos_dev`.

**Porém há dois bloqueadores que impedem seguir a spec ao pé da letra**, ambos herdados de
copiar trechos ilustrativos do `requisitos.md` §4.4 sem confrontá-los com os Models reais:
(1) o **eager loading das relações-filhas na query da base `Posicao` lança
`BadMethodCallException`** — o próprio código do time (`ServicoPosicoes::detalhar`) já
documenta que isso "não é viável"; (2) `Auth::user()?->login` **quebra o PHPStan nível 8**
que a própria spec exige no DoD, contrariando o padrão `instanceof Usuario` já adotado em
`ServicoPosicoes`. Há ainda um alto (vazamento de `QueryException` em `falhas`) e três
médios de desempenho/semântica (N+1 do `mtmOntem`, reprocessamento fora de ordem, corrida
motor × movimentação). Nada disso exige mudar §3/§5/§7 do `requisitos.md` — são correções
de implementação e de redação dos trechos de código.

## 1. Bloqueadores

### B-1. Eager loading das relações-filhas na query da base lança `BadMethodCallException`

- **O que a spec diz.** §4.1 (laço do `MotorMtm`) e §3 (pré-requisitos) e §6 (checklist)
  prescrevem, copiando o §4.4 do `requisitos.md`:
  ```php
  $posicoes = Posicao::query()
      ->with(['futuro.movimentacoes', 'ndf', 'opcao.pernas', 'otc'])
      ->where('status', 'ABERTA')
      ->get();
  ```
- **Por que é problema (evidência no código).** O builder de `Posicao::query()` tem como
  *model* a **base** `Posicao`, e o eager loading resolve cada relação chamando o método
  na **base**, não nas subclasses hidratadas por `newFromBuilder`. Mas as relações
  `futuro()`/`ndf()`/`opcao()`/`otc()` **só existem nas subclasses** — a base `Posicao`
  declara apenas `produto()`, `movimentacoes()` e `mtmDiarios()` (`app/Models/Posicao.php:88-104`,
  com o comentário explícito nas linhas 84-86). O próprio time já bateu nisso e documentou
  em `ServicoPosicoes::detalhar` (`app/Services/ServicoPosicoes.php:50-53`):
  > "Eager loading não é viável aqui porque os nomes (`futuro`/`ndf`/…) só existem nas
  > subclasses, não na base."
  Logo, `->with(['futuro...','ndf','opcao.pernas','otc'])` sobre a base dispara
  `BadMethodCallException: Call to undefined method App\Models\Posicao::futuro()` no `get()`.
- **Consequência prática.** O laço central do motor **não roda** — o primeiro disparo
  quebra com 500 antes de calcular qualquer posição. É o defeito de maior severidade
  (mesma natureza do B-1 da `parte_4_critica`: trecho que não executa). Note ainda que as
  subclasses **precisam** dessas relações: `Ndf::calcularMtm` lê `$this->ndf->…`
  (`Ndf.php:23-27`), `Opcao::calcularMtm` lê `$this->pernas` (`Opcao.php:38-41`),
  `Otc::calcularMtm` lê `$this->otc->…` (`Otc.php:23-29`) — sem carregá-las o cálculo
  também falharia (ou viraria N+1 silencioso).
- **Recomendação.** O snippet do §4.4 é **ilustrativo** (os contratos são §3/§5/§7), então
  a spec deve divergir dele para a forma que funciona. Recomendado: **carregar por
  subclasse concreta** e concatenar, mantendo o laço polimórfico único:
  ```php
  $abertas = collect()
      ->merge(Futuro::query()->with('futuro', 'movimentacoes')->where('status','ABERTA')->get())
      ->merge(Ndf::query()->with('ndf')->where('status','ABERTA')->get())
      ->merge(Opcao::query()->with('opcao','pernas')->where('status','ABERTA')->get())
      ->merge(Otc::query()->with('otc')->where('status','ABERTA')->get());
  // ... um único foreach polimórfico chama $posicao->calcularMtm(...)
  ```
  Isso preserva o eager loading (sem N+1 nas filhas), mantém o cálculo sem `if` por tipo
  (a enumeração de subclasses é só **carga de dados**, não cálculo) e elimina o
  `BadMethodCallException`. Alternativa pior: aceitar lazy-loading por posição (N+1 —
  ver M-1). Atualizar §3, §4.1 e §6 de acordo.

### B-2. `Auth::user()?->login` quebra o PHPStan nível 8 exigido pelo próprio DoD

- **O que a spec diz.** §4.2, §4.4 e §4.6 usam `Auth::user()?->login ?? 'sistema'`, e a
  §4.2 afirma ser "(mesmo fallback do `ServicoPosicoes`, D-507)".
- **Por que é problema (evidência no código).** `Auth::user()` é tipado como
  `\Illuminate\Contracts\Auth\Authenticatable|null`; a *interface* `Authenticatable`
  **não** expõe a propriedade `login`. O PHPStan nível 8 (gate da Fase 0, exigido no DoD
  §7.8 e no checklist §6 desta spec) acusa "Access to an undefined property
  Authenticatable::$login". Por isso `ServicoPosicoes::criadoPor()`
  (`app/Services/ServicoPosicoes.php:223-229`) **não** faz `Auth::user()?->login`; faz:
  ```php
  $usuario = Auth::user();
  return $usuario instanceof Usuario ? $usuario->login : 'sistema';
  ```
  (`Usuario` tem `@property string $login`, `app/Models/Usuario.php:20`.) A spec, portanto,
  **mischaracteriza** o padrão existente: o fallback do `ServicoPosicoes` não é o que a spec
  escreveu.
- **Consequência prática.** Seguindo o exemplo verbatim, o `phpstan` falha — e o DoD da
  própria spec (e o gate de CI) ficam vermelhos; o item "phpstan nível 8 liso" não é
  atingível com o código que a spec ensina.
- **Recomendação.** Padronizar com o guard `instanceof Usuario` em `ServicoMotor`,
  `MotorController` e na tela Livewire — ou, melhor ainda, **centralizar** a origem do
  ator em um único helper (a mesma lógica de `criadoPor()` se repete em `ServicoPosicoes`,
  `ServicoMovimentacoes` e agora no motor — candidato a um trait/método compartilhado).
  No motor, lembrar que o Command usa `'agendador'` (D-609) — só o caminho API/Livewire
  consulta `Auth`.

## 2. Altos

### A-1. `falhas` grava `$e->getMessage()` cru — vaza detalhe de `QueryException`/SQL

- **O que a spec diz.** §4.1: `catch (\Throwable $e) { $resultado->registrarFalha($posicao->id, $e->getMessage()); }`,
  e esse `falhas` vai para a coluna JSONB `motor_execucao.falhas` e para a resposta da API
  (§5.2.4) e para a tela.
- **Por que é problema.** No caminho de concorrência que a própria spec prevê (D-613:
  violação de `UNIQUE(posicao_id,data_calculo)` → `QueryException` 23505, capturada como
  falha da posição), `$e->getMessage()` contém a mensagem PDO crua (nome de constraint,
  fragmento de SQL). Isso reedita exatamente a preocupação das críticas anteriores
  (não expor `QueryException`/detalhe interno; envelope §5.1) — agora persistido em banco e
  servido na API. Qualquer erro inesperado (ex.: `DomainException` de `sinal()` inválido,
  `Posicao.php:114`) também entra como texto livre.
- **Consequência prática.** Vazamento de informação interna por um canal de leitura
  (auditoria + API + UI), e mensagens inúteis para o operador da mesa ("SQLSTATE[23505]…").
- **Recomendação.** Mapear o `motivo`: mensagem amigável para os casos previstos
  (preço ausente já é tratado antes; para `QueryException` usar algo como "Conflito ao
  gravar MtM (reprocessamento concorrente)"; para o resto, "Erro inesperado ao processar a
  posição") e registrar o detalhe técnico apenas no log estruturado (§9.4), não em `falhas`.

## 3. Médios

### M-1. `mtmOntem` permanece N+1 (uma query por posição) — tensão com §9.1

- **O que a spec diz.** D-608 lista, entre as mitigações de N+1, que "`mtmOntem` usa o
  índice `idx_mtm_posicao_data`". §4.1 faz, dentro do laço, uma query por posição:
  ```php
  MtmDiario::query()->where('posicao_id',$id)->where('data_calculo','<',$dataStr)
      ->orderByDesc('data_calculo')->value('mtm_valor');
  ```
- **Por que é problema.** O índice torna **cada** query rápida, mas continuam sendo **N**
  idas ao banco (1.000 posições → 1.000 queries só para o `mtmOntem`, além da query do
  `replay`/relações). D-608 enuncia "evitar N+1" e cita a meta §9.1 (1.000 posições < 30 s),
  mas o `mtmOntem` é, ele próprio, um N+1 não resolvido — diferente dos preços, que a spec
  corretamente carrega num mapa único.
- **Consequência prática.** Risco à meta §9.1; a redação de D-608 sugere que o ponto está
  resolvido quando não está.
- **Recomendação.** Ou (a) pré-carregar o "último MtM anterior à data" num mapa
  `posicao_id ⇒ mtm_valor` com **uma** query agregada (`DISTINCT ON (posicao_id) … WHERE
  data_calculo < :data ORDER BY posicao_id, data_calculo DESC` no Postgres), análogo ao mapa
  de preços; ou (b) assumir explicitamente o N+1 como aceito no MVP e empurrar a otimização
  para a Fase 12 (§9.1) — mas então **corrigir a redação de D-608**, que hoje vende o item
  como mitigado.

### M-2. Reprocessamento/backfill fora de ordem deixa `variacao_dia` de dias posteriores defasada

- **O que a spec diz.** RN-013/D-603 garantem idempotência **por data**; `variacao_dia =
  mtmBrl − mtmOntem` (último registro com `data_calculo < dataStr`).
- **Por que é problema.** Se D+1 for processado/reprocessado **depois** de D+2 já existir
  (correção de preço retroativa, feriado lançado tarde, backfill da Fase 8), o
  `variacao_dia` de D+2 continua calculado contra o que existia antes (D ou zero), ficando
  inconsistente — RN-013 só promete consistência da **mesma** data, não recomputo em cascata
  dos dias seguintes. Isso é plausível porque o motor aceita `data_calculo` arbitrária no
  payload (§5.2.4) e a Fase 8 fará loop de datas.
- **Consequência prática.** "Buracos"/saltos na série de `variacao_dia` que o relatório de
  P&L (Fase 7) exibirá.
- **Recomendação.** Registrar a limitação explicitamente (§8 Riscos e em
  `pontos_de_atencao.md`) e, no mínimo, recomendar processar datas em ordem crescente no
  backfill da Fase 8. Recomputo em cascata de dias posteriores fica fora do MVP — mas deve
  ser **decisão consciente**, não omissão.

### M-3. Motor marca `VENCIDA` sem `lockForUpdate` — corrida com movimentação concorrente

- **O que a spec diz.** §4.1 envolve cada posição em `DB::transaction`, mas lê/atualiza a
  `posicao` **sem** `lockForUpdate` ao fazer `->update(['status' => 'VENCIDA'])`.
- **Por que é problema.** `ServicoMovimentacoes::movimentarFuturo` opera sob
  `lockForUpdate` (D-501) e decide encerrar/abrir a posição. O motor, sem lock, pode
  marcar `VENCIDA` concorrentemente, levando a *lost update* de `status`/`quantidade`
  (ex.: uma redução total que deveria `ENCERRADA` sendo sobrescrita por `VENCIDA`, ou
  vice-versa).
- **Consequência prática.** Baixa probabilidade no MVP (motor às 19:00, lançamentos durante
  o dia), mas é exatamente a classe de corrida que a arquitetura diz tratar com lock.
- **Recomendação.** Adquirir `lockForUpdate` na posição dentro da transação por posição
  antes de transicionar para `VENCIDA` (ou recarregar com lock e revalidar o `status ===
  'ABERTA'` antes de gravar). Citar a interação com D-501.

## 4. Baixos

- **BX-1.** **D-604 depende da equivalência float→`decimal:` no `isDirty`.** O `isDirty`
  comparar `round($x,2)` (float) com o valor original (string `decimal:2`) só funciona
  porque o Eloquent normaliza casts `decimal:` em `originalIsEquivalent`. Vale um teste
  explícito (já há um na §6) e uma nota: se algum atributo comparado não tiver cast
  `decimal:` adequado, o registro ficaria "sempre dirty", anulando a economia de
  proveniência. `MtmDiario` tem os casts certos (`MtmDiario.php:41-48`) — apenas registrar.
- **BX-2.** **"sucessos" conta reprocessamento estéril.** Em D-604, quando o valor não muda,
  a posição entra em `sucessos` mas nenhum `mtm_diario.execucao_id` aponta para a nova
  `motor_execucao`. É semântica defensável (a posição foi avaliada com sucesso), mas vale
  uma frase explicando, para não confundir auditoria ("execução com sucessos mas sem linhas
  novas/atualizadas apontando para ela").
- **BX-3.** **`--data` inválido no Command.** `new \DateTimeImmutable($this->option('data') ?: 'today')`
  lança em entrada malformada (ex.: `--data=ontem`). Tratar com mensagem clara e retorno
  `self::FAILURE`, em vez de stack trace.
- **BX-4.** **DI em método de ação do Livewire.** §4.6 injeta `ServicoMotor` em
  `disparar(ServicoMotor $motor)`. Livewire resolve parâmetros de ação pelo container, então
  funciona, mas é um padrão menos óbvio que o `app(ServicoPosicoes::class)` usado em
  `ListaPosicoes::render` (`app/Livewire/Posicoes/ListaPosicoes.php:46`). Manter um estilo
  só (preferir injeção no método de ação é ok) e garantir consistência.
- **BX-5.** **Status HTTP de `POST /motor/processar` (D-611).** A escolha de 200 é
  defensável e está bem justificada; só registrar que, como cada disparo cria uma
  `motor_execucao`, um cliente que quisesse o `Location` da execução não o tem — aceitável
  no MVP.
- **BX-6.** **`processado_em`/`useCurrent()`.** A migration define
  `processado_em ... ->useCurrent()` (`mtm_diario` migration:22). Em D-604 o registro novo
  recebe `processado_em = now()` explícito (ok) e o caso "não-dirty" nunca insere; sem
  conflito — apenas confirmar que o caminho novo sempre seta o valor (seta).

## 5. Pontos fortes (para preservar)

- **Separação `MotorMtm` × `ServicoMotor`** fiel ao §4.4, com `execucao_id` aberto antes do
  laço e propagado ao UPSERT (D-601/D-602) — auditoria por design (§2.3/§3.2.9) bem modelada.
- **Proveniência condicional (D-604)** via `firstOrNew`+`isDirty` é superior ao
  `updateOrCreate` do §4.4: preserva quem/quando produziu o número vigente em
  reprocessamentos estéreis, sem perder a idempotência (UNIQUE como backstop).
- **`falhas` associativo `{posicao_id, motivo}`** (§4.3) corrige o array posicional do
  exemplo do §4.4 e alinha-se a §3.2.9 e ao payload §5.2.4.
- **Polimorfismo intacto:** nenhum `if`/`switch` por instrumento no laço; `VENCIDA` só na
  interseção sucesso ∩ vencida (D-605) com a ordem correta (calcula o dia do vencimento e
  só então transita).
- **Mapa de preços do dia em uma query (D-608)** elimina corretamente o N+1 dos preços
  (o snippet do §4.4 fazia uma query de preço por posição).
- **Escopo bem recortado:** carry-over, BCMath, `ESTORNO` e decomposição preço×câmbio
  reafirmados fora do MVP, com remissão a `pontos_de_atencao.md`; AuthZ adiada à Fase 10
  (D-612) coerente com D-402.

## 6. Ações recomendadas (prioridade)

| # | Severidade | Ação |
|---|---|---|
| B-1 | Bloqueador | Substituir o eager loading na base por carga **por subclasse** (`Futuro/Ndf/Opcao/Otc::query()->with(...)`) concatenada, mantendo um único laço polimórfico. Corrigir §3, §4.1, §6. |
| B-2 | Bloqueador | Trocar `Auth::user()?->login` pelo guard `instanceof Usuario` (padrão de `ServicoPosicoes::criadoPor`) em `ServicoMotor`/`MotorController`/Livewire; idealmente centralizar o helper de "ator". |
| A-1 | Alto | Sanitizar o `motivo` das falhas (mensagens amigáveis; `QueryException`→genérica); detalhe técnico só no log estruturado (§9.4), não em `falhas`/API. |
| M-1 | Médio | Resolver o N+1 do `mtmOntem` (mapa via `DISTINCT ON` único) **ou** corrigir a redação de D-608 assumindo o N+1 como aceito e empurrado à Fase 12. |
| M-2 | Médio | Documentar a defasagem de `variacao_dia` em reprocessamento fora de ordem; recomendar backfill em ordem crescente (Fase 8); registrar em `pontos_de_atencao.md`. |
| M-3 | Médio | Adquirir `lockForUpdate` na posição antes de marcar `VENCIDA` (revalidar `status==='ABERTA'`), citando a interação com D-501. |
| BX-1..6 | Baixo | Notas de clareza/robustez: equivalência `isDirty`/decimal, semântica de "sucessos" estéril, `--data` inválido, estilo de DI no Livewire, ausência de `Location`, `processado_em`. |

> **Nota final.** O esqueleto da Fase 6 está certo — decisões, rastreabilidade RN e recorte
> de escopo são sólidos. O que trava a implementação são os **dois bloqueadores herdados de
> copiar o código ilustrativo do `requisitos.md` §4.4 sem confrontá-lo com os Models reais**
> (eager loading na base e `Auth::user()?->login`); ambos têm correção barata e já
> existem padrões no próprio repositório (`ServicoPosicoes`). O alto (A-1) e os médios
> (N+1 do `mtmOntem`, reprocessamento fora de ordem, corrida com movimentação) afetam
> robustez/desempenho/auditoria, não o caminho feliz imediato. **Nada aqui exige alterar
> §3/§5/§7 do `requisitos.md`** — são ajustes de implementação e de redação dos trechos de
> código e de D-608.
</content>
</invoke>
