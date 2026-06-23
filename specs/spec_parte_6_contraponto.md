# Contraponto à crítica — `spec_parte_6_critica.md` (Motor MtM / Fase 6)

> **Autor da arbitragem:** tech lead / product owner (decisão de escopo e prioridade).
> **Base:** `specs/spec_parte_6_critica.md` ponderada contra `specs/spec_parte_6.md`,
> `specs/requisitos.md` (v1.7, fonte da verdade), `specs/passos_dev.md` (Fase 6,
> escopo/DoD) e o **código real** reconferido: `app/Models/Posicao.php` (88-104),
> `app/Models/Futuro.php`, `Ndf.php`, `Opcao.php`, `app/Services/ServicoPosicoes.php`
> (49-60, 223-229), `specs/future/pontos_de_atencao.md`, `specs/passos_dev.md` (Fase 6).
> **Natureza:** decide **o que vale a pena corrigir** na spec antes de reescrevê-la.
> Não altera regras de negócio nem a crítica; arbitra entre elas. Onde houver
> divergência, `requisitos.md` prevalece.

## Veredito geral

A crítica é **justa e bem fundamentada** — incomum por não ter quase nenhum falso
positivo nos achados de fato. Os **dois bloqueadores são reais e os reconferi no
código**: (B-1) o eager loading `->with(['futuro...','ndf','opcao.pernas','otc'])` sobre
`Posicao::query()` de fato dispara `BadMethodCallException`, porque a base só declara
`produto()`/`movimentacoes()`/`mtmDiarios()` (`Posicao.php:88-104`, com o comentário
explícito em 84-86) e o próprio time já documentou a inviabilidade em
`ServicoPosicoes::detalhar` (`ServicoPosicoes.php:49-52`); (B-2) `ServicoPosicoes::criadoPor`
**não** usa `Auth::user()?->login` — usa `instanceof Usuario` (`ServicoPosicoes.php:226-228`)
justamente para passar no PHPStan nível 8 que o DoD desta spec exige. Ou seja, a spec
mischaracteriza o próprio padrão do repositório em dois pontos, e ambos travam a
implementação ao pé da letra. **Os dois procedem e são pré-requisito do código.**

O alto (A-1, vazamento de `$e->getMessage()`) também procede: o `motivo` cru vai para
JSONB de auditoria **e** para a API/UI, reeditando a preocupação de não-exposição que as
críticas anteriores já trataram — e a correção é barata. Dos médios, **filtro o
over-engineering**: o N+1 do `mtmOntem` (M-1) é fato, mas a otimização com `DISTINCT ON`
é desempenho — explicitamente **Fase 12** pela própria spec (§1) — então aceito só a
**correção de redação** de D-608 (que hoje vende o item como mitigado) e adio a
otimização. M-3 (lock no `VENCIDA`) é uma corrida de probabilidade quase nula no MVP
(motor 19:00 × lançamentos no dia), mas o guard é barato e coerente com D-501 — aceito a
versão mínima. M-2 (defasagem de `variacao_dia` em reprocessamento fora de ordem) merece
só **documentação** (a cascata fica fora do MVP, como a própria crítica reconhece). Dos
baixos, BX-1/BX-2/BX-3 são notas cheap e corretas; **BX-4/BX-5/BX-6 a própria crítica
admite que "funcionam"/"são aceitáveis"** — rejeito por serem zelo de estilo sem mudança
de spec.

**Placar: ✅ ×7 · ◐ ×2 · ❌ ×3 · ⏳ ×0.** Conjunto mínimo que **bloqueia código** e tem de
entrar antes de implementar: **B-1 e B-2**. A-1, a redação de D-608 (M-1), a documentação
de M-2 e BX-3 são baratos e devem ir no mesmo passo; o resto é polimento opcional.

## 1. Achado a achado

### B-1 — Eager loading das relações-filhas na base lança `BadMethodCallException` · Veredito: ✅ PROCEDE
- **O que a crítica diz:** §4.1/§3/§6 copiam do `requisitos.md` §4.4 um
  `Posicao::query()->with(['futuro.movimentacoes','ndf','opcao.pernas','otc'])`, mas
  essas relações só existem nas subclasses, não na base — o `get()` quebra com 500.
- **A favor de agir:** é um defeito de execução (o laço central do motor não roda no
  primeiro disparo) — mesma natureza do B-1 da Parte 4. Sem isso, nada do MtM calcula.
- **Contraponto:** nenhum relevante. O snippet do §4.4 é ilustrativo; a spec pode e deve
  divergir dele para a forma que funciona, sem tocar em regra de negócio.
- **Conferência:** confirmado. `Posicao` (base) declara apenas `produto()`,
  `movimentacoes()`, `mtmDiarios()` (`Posicao.php:88-104`) e tem o comentário explícito
  (84-86) de que `futuro/ndf/opcao/otc` ficam nas subclasses; `ServicoPosicoes::detalhar`
  (49-52) já documenta "Eager loading não é viável aqui". As relações existem mesmo nas
  filhas (`Futuro::futuro`, `Ndf::ndf`, `Opcao::opcao/pernas`) e os `calcularMtm` as leem
  (`Ndf.php:24`, `Opcao.php:33`, `Futuro` via `movimentacoes` herdada). A correção
  proposta (carregar por subclasse e concatenar) usa relações reais e preserva o laço
  polimórfico único — a enumeração de subclasses é **carga de dados**, não cálculo, então
  o "sem `if` por tipo" do D-601 continua honrado.
- **Decisão:** substituir o eager loading na base por carga por subclasse concreta
  (`Futuro::query()->with('futuro','movimentacoes')`, `Ndf::query()->with('ndf')`,
  `Opcao::query()->with('opcao','pernas')`, `Otc::query()->with('otc')`) concatenada num
  único `Collection`, com um só `foreach` polimórfico. Atualizar §3, §4.0, §4.1, §6 e a
  linha de D-608 que cita o `with([...])` na base.

### B-2 — `Auth::user()?->login` quebra o PHPStan nível 8 do próprio DoD · Veredito: ✅ PROCEDE
- **O que a crítica diz:** §4.2/§4.4/§4.6 usam `Auth::user()?->login ?? 'sistema'` e
  alegam ser "o mesmo fallback do `ServicoPosicoes`, D-507" — mas `Authenticatable` não
  expõe `$login`, então o PHPStan nível 8 (gate da Fase 0 e do DoD §8 desta spec) acusa
  acesso a propriedade indefinida.
- **A favor de agir:** o DoD da própria spec exige "phpstan nível 8 liso"; seguir o
  exemplo verbatim deixa o gate vermelho. E a spec descreve mal o padrão existente.
- **Contraponto:** nenhum relevante — é fato verificável e contradiz o código real.
- **Conferência:** confirmado. `ServicoPosicoes::criadoPor` (`ServicoPosicoes.php:223-229`)
  faz `$usuario = Auth::user(); return $usuario instanceof Usuario ? $usuario->login : 'sistema';`
  — exatamente para satisfazer o PHPStan. A spec atribui ao `ServicoPosicoes` um padrão
  que ele não tem.
- **Decisão:** trocar `Auth::user()?->login ?? 'sistema'` pelo guard `instanceof Usuario`
  nos três pontos (`MotorController`, Livewire `ProcessarMotor`, e a nota da §4.2). A
  sugestão de **centralizar** o helper de "ator" (trait/método compartilhado entre
  `ServicoPosicoes`/`ServicoMovimentacoes`/motor) é boa, mas é refatoração transversal a
  Services já entregues — fica como melhoria opcional, não pré-requisito desta fase.
  Lembrar que o Command usa `'agendador'` (D-609) e não consulta `Auth`.

### A-1 — `falhas` grava `$e->getMessage()` cru, vazando `QueryException`/SQL · Veredito: ✅ PROCEDE
- **O que a crítica diz:** §4.1 faz `registrarFalha($posicao->id, $e->getMessage())`; esse
  texto vai para `motor_execucao.falhas` (JSONB de auditoria), para a API §5.2.4 e para a
  tela. No caminho de concorrência que a própria spec prevê (D-613, `QueryException` 23505),
  isso expõe nome de constraint / fragmento de SQL; um `DomainException` de `sinal()`
  também entraria como texto livre.
- **A favor de agir:** vazamento de detalhe interno por um canal de leitura persistido, e
  mensagem inútil para o operador da mesa ("SQLSTATE[23505]…"). Coerente com a postura de
  envelope/não-exposição já firmada nas críticas das Partes 4 e 5.
- **Contraponto:** o caminho feliz não quebra; é robustez/segurança, não bloqueador. Há
  risco de over-engineering se virar uma taxonomia de exceções elaborada.
- **Conferência:** o código da §4.1 de fato propaga `$e->getMessage()` sem filtro; o §5.2.4
  serve `falhas[]` na resposta. A preocupação é real e o custo é um `catch` com poucos
  ramos.
- **Decisão:** aceitar o **núcleo** mantendo-o mínimo — mapear o `motivo`:
  `QueryException` → mensagem genérica ("Conflito ao gravar MtM / reprocessamento
  concorrente"); qualquer outro `Throwable` → "Erro inesperado ao processar a posição";
  o detalhe técnico vai só para o log estruturado (§9.4), não para `falhas`/API. **Não**
  criar hierarquia de exceções nova. (Preço ausente já é tratado antes do `try`, com
  mensagem amigável — manter.)

### M-1 — `mtmOntem` permanece N+1 (uma query por posição) · Veredito: ◐ PROCEDE EM PARTE
- **O que a crítica diz:** D-608 lista o `mtmOntem` entre as mitigações de N+1, mas §4.1
  faz uma query por posição (`MtmDiario::query()->where('posicao_id',$id)…->value(...)`).
  São N idas ao banco; sugere (a) mapa via `DISTINCT ON (posicao_id)` ou (b) assumir o N+1
  e corrigir a redação de D-608.
- **A favor de agir:** o fato é verdadeiro — o índice acelera cada query, mas não elimina
  as N consultas. A redação de D-608 hoje sugere "resolvido" quando não está; isso é uma
  inconsistência interna da spec (engana quem implementa e quem audita o desempenho).
- **Contraponto:** a **otimização** em si (`DISTINCT ON`) é desempenho puro, e a própria
  spec §1 coloca o teste de carga "1.000 posições < 30 s (§9.1)" **explicitamente na Fase
  12**. Construir o mapa agora é antecipar trabalho de uma fase futura sem o benchmark que
  justificaria a forma. No MVP a base é pequena.
- **Conferência:** §4.1 `calcularRegistro` faz a query individual; correto que é N+1. O
  mapa de **preços** (D-608) está certo (1 SELECT), mas é coisa distinta do `mtmOntem`.
- **Decisão:** aceitar **só a opção (b)** — corrigir a redação de D-608 e do §8 para
  enunciar que o `mtmOntem` é um N+1 **conscientemente aceito no MVP**, com a otimização
  (mapa `DISTINCT ON` único) empurrada para a Fase 12 junto do teste de carga. **Rejeitar
  construir a otimização agora.** Manter o índice `idx_mtm_posicao_data` como está.

### M-2 — Reprocessamento fora de ordem defasa `variacao_dia` de dias posteriores · Veredito: ✅ PROCEDE (só documentação)
- **O que a crítica diz:** se D+1 for (re)processado depois de D+2 já existir (correção
  retroativa de preço, feriado tardio, backfill da Fase 8), o `variacao_dia` de D+2
  continua calculado contra o estado anterior — RN-013 só garante consistência da **mesma**
  data, não recomputo em cascata. O motor aceita `data_calculo` arbitrária (§5.2.4) e a
  Fase 8 fará loop de datas, então é plausível.
- **A favor de agir:** sem registrar, vira "buraco"/salto na série que o relatório de P&L
  (Fase 7) exibirá — e é uma decisão de produto que deve ser **consciente**, não omissão.
  Custo de documentar é trivial.
- **Contraponto:** o **recomputo em cascata** é fora do MVP e a própria crítica concorda;
  não há ação de código aqui.
- **Conferência:** `variacao_dia = mtmBrl − mtmOntem` com "último `< dataStr`" (§4.1)
  confirma a limitação. Coerente com `pontos_de_atencao.md` (que já trata carry-over/série).
- **Decisão:** registrar a limitação em §8 (Riscos) da spec e em
  `specs/future/pontos_de_atencao.md`; recomendar que o backfill da Fase 8 processe datas
  em **ordem crescente**. Nenhum código novo no motor agora.

### M-3 — Motor marca `VENCIDA` sem `lockForUpdate` (corrida com movimentação) · Veredito: ◐ PROCEDE EM PARTE
- **O que a crítica diz:** §4.1 envolve cada posição em `DB::transaction` mas faz
  `->update(['status'=>'VENCIDA'])` sem lock; `ServicoMovimentacoes::movimentarFuturo`
  opera sob `lockForUpdate` (D-501) e também decide encerrar — possível *lost update* de
  `status`/`quantidade`.
- **A favor de agir:** é exatamente a classe de corrida que a arquitetura diz tratar com
  lock (D-501); o guard é barato e a transação por posição já existe.
- **Contraponto:** a própria crítica admite **baixíssima probabilidade no MVP** — o motor
  roda às 19:00 em dias úteis e o disparo manual é pontual; lançamentos acontecem durante o
  dia. É defesa em profundidade, não risco do caminho feliz.
- **Conferência:** §4.1 confirma `update` sem lock dentro do `DB::transaction`. D-501 em
  `ServicoMovimentacoes` confirma o uso de lock no outro lado.
- **Decisão:** aceitar a **versão mínima** — antes de transicionar para `VENCIDA`,
  recarregar a posição com `lockForUpdate` dentro da transação por posição e revalidar
  `status === 'ABERTA'` (só então gravar `VENCIDA`), citando a interação com D-501. Marcar
  como prioridade baixa, não bloqueador. Não reescrever o desenho transacional.

### BX-1 — Equivalência float→`decimal:` no `isDirty` (D-604) · Veredito: ✅ PROCEDE (nota)
- **O que a crítica diz:** o `isDirty` comparar `round($x,2)` (float) com o original
  (string `decimal:2`) só funciona porque o Eloquent normaliza casts `decimal:`; se algum
  atributo comparado não tiver o cast, o registro ficaria "sempre dirty".
- **A favor de agir:** uma frase de nota + o teste (já previsto na §6) protegem D-604, que
  é o coração da proveniência condicional. Custo nulo.
- **Contraponto:** `MtmDiario` já tem os casts certos — é só registro, não correção.
- **Conferência:** os atributos comparados (`preco_ref_id`,`preco_mercado`,`mtm_valor`,
  `variacao_dia`,`pl_acumulado`) são os derivados do `RegistroMtm`; a dependência do cast é
  real.
- **Decisão:** acrescentar uma nota curta em D-604 lembrando da dependência do cast
  `decimal:` para o `isDirty` valer; manter o teste de proveniência já listado.

### BX-2 — "sucessos" conta reprocessamento estéril · Veredito: ✅ PROCEDE (nota)
- **O que a crítica diz:** em D-604, quando o valor não muda, a posição entra em `sucessos`
  mas nenhum `mtm_diario.execucao_id` aponta para a nova `motor_execucao` — semântica
  defensável, mas merece explicação para não confundir auditoria.
- **A favor de agir:** uma frase evita a leitura "execução com sucessos mas sem linhas
  apontando para ela" parecer bug. Custo nulo.
- **Contraponto:** é só clareza; o comportamento está correto.
- **Decisão:** acrescentar uma frase em D-604/§8 explicitando que "sucesso" = posição
  avaliada com sucesso (mesmo sem rescrita material da proveniência).

### BX-3 — `--data` inválido no Command lança stack trace · Veredito: ✅ PROCEDE
- **O que a crítica diz:** `new \DateTimeImmutable($this->option('data') ?: 'today')` lança
  em entrada malformada (`--data=ontem`), gerando stack trace em vez de erro claro.
- **A favor de agir:** robustez de operação real (o Command é a porta do agendador/operador);
  custo baixo — um `try/catch` com `$this->error(...)` + `self::FAILURE`.
- **Contraponto:** entrada operacional, não exposta a usuário final; risco baixo. Ainda
  assim, barato e melhora a UX do CLI.
- **Conferência:** §4.5 confirma o construtor direto sem validação.
- **Decisão:** validar o `--data` no Command: data malformada → mensagem clara e
  `self::FAILURE`. Pode ir no mesmo passo da implementação.

### BX-4 — DI no método de ação do Livewire · Veredito: ❌ NÃO PROCEDE
- **O que a crítica diz:** §4.6 injeta `ServicoMotor` em `disparar(ServicoMotor $motor)`;
  Livewire resolve pelo container, mas é estilo menos óbvio que `app(...)` usado em
  `ListaPosicoes::render`.
- **A favor de agir:** consistência de estilo no código Livewire.
- **Contraponto:** a própria crítica admite que **funciona**. Injeção no método de ação é
  um padrão suportado e legítimo do Livewire; não há defeito nem risco. Mudar por estilo é
  zelo sem retorno, e `render` (que precisa do Service a cada repintura) e um método de
  ação pontual têm necessidades diferentes — uniformizar à força não é melhoria.
- **Decisão:** nenhuma. Manter como está na spec.

### BX-5 — `POST /motor/processar` (200) não devolve `Location` da execução · Veredito: ❌ NÃO PROCEDE
- **O que a crítica diz:** como cada disparo cria uma `motor_execucao`, um cliente que
  quisesse o `Location` não o tem.
- **A favor de agir:** semântica REST mais "pura" para quem quer endereçar a execução.
- **Contraponto:** a própria crítica diz "aceitável no MVP", e D-611 já justifica bem o 200
  (a execução é log de auditoria, não recurso REST que o cliente criou; o efeito material é
  idempotente). O `ResumoExecucao` já devolve `execucao_id`, então o cliente tem o
  identificador. Adicionar `Location` contradiria a decisão consciente de D-611 sem ganho.
- **Decisão:** nenhuma. D-611 permanece.

### BX-6 — `processado_em`/`useCurrent()` · Veredito: ❌ NÃO PROCEDE
- **O que a crítica diz:** a migration define `processado_em ...->useCurrent()`; em D-604 o
  registro novo recebe `processado_em = now()` explícito e o caso "não-dirty" nunca insere
  — "sem conflito, apenas confirmar".
- **A favor de agir:** confirmar que o caminho novo sempre seta o valor.
- **Contraponto:** a própria crítica conclui "sem conflito"; o `persistir` da §4.1 seta
  `processado_em = now()` no ramo novo/dirty e não há caminho que insira sem setar. Não há
  o que corrigir.
- **Decisão:** nenhuma.

## 2. Observações fora da crítica

- **Inconsistência interna D-603 × D-604 (a crítica não destacou).** D-603 e a tarefa do
  `passos_dev` Fase 6 descrevem a idempotência como `MtmDiario::updateOrCreate([...])`,
  mas D-604 e o código real da §4.1 usam `firstOrNew` + `isDirty` (justamente para
  preservar a proveniência). Não é erro — D-604 **refina** D-603 —, mas a leitura literal
  de D-603 contradiz o código que a spec ensina. Vale **harmonizar a redação de D-603**
  (citar que o UPSERT é via `firstOrNew`+`save`, com `updateOrCreate` apenas como
  descrição conceitual da idempotência) ao reescrever a spec. Custo: uma frase.

## 3. O que de fato corrigir na spec (recomendação priorizada)

| # | Achado | Veredito | Ação na spec |
|---|---|---|---|
| 1 | B-1 | ✅ | Trocar eager loading na base por carga **por subclasse** (`Futuro/Ndf/Opcao/Otc::query()->with(...)`) concatenada, laço polimórfico único. Corrigir §3, §4.0, §4.1, §6 e a linha de D-608. |
| 2 | B-2 | ✅ | Substituir `Auth::user()?->login` por guard `instanceof Usuario` (padrão de `ServicoPosicoes::criadoPor`) em `MotorController`, Livewire e nota da §4.2. |
| 3 | A-1 | ✅ | Sanitizar o `motivo`: `QueryException`→genérica, outros→"erro inesperado"; detalhe técnico só no log (§9.4). Manter mínimo (sem taxonomia nova). |
| 4 | M-1 | ◐ | Corrigir a redação de D-608/§8: `mtmOntem` é N+1 **aceito no MVP**, otimização (`DISTINCT ON`) → Fase 12. **Não** otimizar agora. |
| 5 | M-2 | ✅ | Documentar a defasagem de `variacao_dia` em reprocessamento fora de ordem (§8 + `pontos_de_atencao.md`); recomendar backfill em ordem crescente (Fase 8). |
| 6 | M-3 | ◐ | Recarregar a posição com `lockForUpdate` e revalidar `status==='ABERTA'` antes de marcar `VENCIDA` (citar D-501). Prioridade baixa. |
| 7 | BX-1 | ✅ | Nota em D-604 sobre a dependência do cast `decimal:` para o `isDirty`. |
| 8 | BX-2 | ✅ | Frase explicando "sucesso" = avaliação com sucesso (mesmo em reprocesso estéril). |
| 9 | BX-3 | ✅ | Validar `--data` no Command (mensagem clara + `self::FAILURE`). |
| 10 | BX-4 | ❌ | Nenhuma — DI no método de ação do Livewire funciona; estilo. |
| 11 | BX-5 | ❌ | Nenhuma — D-611 (200 sem `Location`) já justificado; `execucao_id` no corpo. |
| 12 | BX-6 | ❌ | Nenhuma — sem conflito; `persistir` já seta `processado_em`. |
| — | Obs. §2 | ✅ | Harmonizar D-603 com D-604 (`firstOrNew`+`save`; `updateOrCreate` só conceitual). |

## 4. Veredito de custo-benefício

**Pré-requisito que bloqueia código (corrigir ANTES de implementar): B-1 e B-2.** Sem o
B-1 o laço do motor não roda (500 no primeiro disparo); sem o B-2 o DoD da própria spec
(phpstan nível 8) fica vermelho. Ambos têm correção barata e padrão pronto no repositório
(`ServicoPosicoes`), então não há razão para não corrigir já.

**Vale incluir no mesmo passo (barato, alto retorno):** A-1 (sanitizar `motivo` — sem
expor SQL na auditoria/API), a **redação** de D-608 (M-1), a documentação de M-2, o
tratamento de `--data` (BX-3), as notas BX-1/BX-2 e a harmonização D-603↔D-604. Tudo é
texto ou poucas linhas.

**Polimento opcional/menor:** M-3 (lock no `VENCIDA`) — defesa em profundidade de risco
quase nulo no MVP; faça se for trivial, não bloqueie por isso.

**Não fazer agora:** a otimização `DISTINCT ON` do `mtmOntem` (Fase 12, junto do teste de
carga §9.1), o recomputo em cascata de `variacao_dia` (fora do MVP), a centralização do
helper de "ator" (refatoração transversal) e os BX-4/5/6 (estilo/já justificados).

Frase final: **corrigir B-1 e B-2 antes de implementar; A-1, a redação de D-608, a
documentação de M-2, BX-3 e a harmonização D-603↔D-604 vão no mesmo passo; M-3 é opcional;
o restante é Fase 12 ou não-ação.** A crítica é, no todo, **acertada e econômica** — quase
não há o que filtrar nos achados de fato; o filtro recai sobre a otimização de M-1 e os
três baixos de estilo.
