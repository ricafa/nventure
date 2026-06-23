# Contraponto à crítica — `spec_parte_7_critica.md` (Relatórios — Fase 7)

> **Autor da arbitragem:** tech lead / product owner (decisão de escopo e prioridade).
> **Base:** `specs/spec_parte_7_critica.md` ponderada contra `specs/spec_parte_7.md`,
> `specs/requisitos.md` (v1.7, fonte da verdade — §5.1, §5.2.5, §7.4 RN-016..019/RN-023/024),
> `specs/passos_dev.md` (Fase 7, escopo/DoD) e o **código real** reconferido:
> `bootstrap/app.php`, `app/Exceptions/ErroAplicacao.php`/`ErroNaoEncontrado.php`,
> `app/Services/ServicoPosicoes.php` (padrão 404), `app/Services/MotorMtm.php` (carga por
> subclasse), `app/Models/Posicao.php` (`newFromBuilder`/`sinal`),
> `app/Http/Controllers/Api/V1/PosicaoController.php` (tipo `Response`).
> **Natureza:** decide **o que vale a pena corrigir** na spec antes de reescrevê-la.
> Não altera regras de negócio nem a crítica; arbitra entre elas. Onde houver
> divergência, `requisitos.md` prevalece.

## Veredito geral

A crítica é **justa e bem ancorada no código** — não é zelo abstrato. Reconferi os dois
achados mais sérios e ambos **procedem como fato verificável**: (1) `bootstrap/app.php:24`
só registra `render` para `ErroAplicacao`, e `findOrFail` lança
`Illuminate\Database\Eloquent\ModelNotFoundException` (que **não** estende `ErroAplicacao`),
então o 404 do `historico-mtm` vazaria no formato padrão do Laravel — exatamente o bug que o
commit `2b45a71` corrigiu, com o padrão `find() ?? throw new ErroNaoEncontrado` já vivo em
`ServicoPosicoes.php:56-57` (**B-1**); e (2) `PosicaoController` importa
`Illuminate\Http\Response`, mas o exportador devolve `Symfony\...\StreamedResponse` e o JSON
devolve `JsonResponse` — cujo supertipo comum é o `Response` do **Symfony**, não o do
Illuminate, quebrando o PHPStan nível 8 do DoD #9 (**A-1**). Esses dois são baratos,
bloqueiam código/CI e devem entrar.

A crítica também acerta o achado que **mais aparece para o usuário**: a série do gráfico
soma `mtm_valor` enquanto o KPI canônico de RN-018 soma `pl_acumulado` (= `mtm_valor` +
realizado, RN-023/§253) — dois números rotulados "acumulado" que divergem quando há reduções
(**M-2**). Vale corrigir antes da tela ir ao ar.

Onde a crítica **extrapola** é na *justificativa* de **M-1**: ela alega que a carga
base+overlay "desperdiça query" e fere o orçamento §9.1. Reconferindo o motor
(`MotorMtm.php:32-36`), o padrão por subclasse faz **4 queries** (uma por instrumento),
enquanto o base+overlay da spec faz **2** (base + a subclasse que precisa de eager loading);
a base já hidrata as subclasses via `newFromBuilder` (`Posicao.php:67-82`). Ou seja: o
base+overlay é **mais econômico em nº de queries**, não menos. O ponto legítimo de M-1 é
**consistência de idioma** entre Fase 6 e Fase 7, não economia — aceito o miolo, recuso a
premissa. **M-3** (pdf→422 vs 501) é semanticamente correto e barato, mas é polimento de
borda num formato que o MVP nem usa — aceito o princípio, com prioridade baixa.

**Placar: 5 ✅ · 4 ◐ · 1 ⏳ · 1 ❌.** Conjunto mínimo que bloqueia/precede a implementação:
**B-1, A-1, M-2**; somar **A-2** e **BX-2** (baratos e removem ambiguidade); o resto é
polimento alinhável.

## 1. Achado a achado

### B-1 — `historicoMtm` usa `findOrFail` → 404 fora do envelope §5.1 · Veredito: ✅ PROCEDE
- **O que a crítica diz:** §4.1 usa `Posicao::query()->findOrFail($posicaoId)`; o handler global
  só traduz `ErroAplicacao`, então o 404 sai no formato `{message}` do Laravel, não `{erro,
  mensagem}`, quebrando o DoD #5 e a §5.1 — o mesmo bug do commit `2b45a71`.
- **A favor de agir:** é fato puro, não opinião. O 404 inconsistente é justamente o que o
  repositório já consertou; reintroduzi-lo regride a API e derruba o teste de envelope.
- **Contraponto:** nenhum relevante — é correção de fidelidade ao código existente.
- **Conferência:** confirmado. `bootstrap/app.php:24` → `$exceptions->render(function (ErroAplicacao
  $e, ...))`. `ErroNaoEncontrado extends ErroAplicacao` (`ErroNaoEncontrado.php:6`), mas
  `findOrFail` lança `ModelNotFoundException`, que **não** é `ErroAplicacao` → cai no 404 padrão.
  O padrão correto já existe: `ServicoPosicoes::detalhar` faz `find($id) ?? throw new
  ErroNaoEncontrado('Posição não encontrada.')` (`ServicoPosicoes.php:56-57`).
- **Decisão:** trocar por `Posicao::query()->find($posicaoId) ?? throw new
  \App\Exceptions\ErroNaoEncontrado('Posição não encontrada.')`; **remover** a nota hedge de
  §4.1 ("confirme o mapeamento / troque por ?? throw") e a anotação `// → ErroNaoEncontrado` do
  exemplo — o mapeamento já é conhecido, a spec deve trazer o `?? throw` direto.

### A-1 — Tipo de retorno `Response` não fecha no PHPStan nível 8 · Veredito: ✅ PROCEDE
- **O que a crítica diz:** `responder()`/ações tipadas como `Response` retornam `StreamedResponse`
  (csv) e `JsonResponse` (json/pdf); o supertipo comum é `Symfony\...\Response`, não
  `Illuminate\Http\Response`, então o tipo da união viola o PHPStan nível 8 (DoD #9).
- **A favor de agir:** fato verificável; o build de tipagem fica vermelho e quem implementar só
  descobre ao rodar o phpstan. O DoD #9 exige nível 8 verde.
- **Contraponto:** nenhum — é correção de assinatura.
- **Conferência:** confirmado. `PosicaoController.php:17` importa `use Illuminate\Http\Response;`
  e `destroy(): Response` usa esse tipo. `JsonResponse` e `StreamedResponse` estendem o
  `Response` do **Symfony**; o exportador (§4.4) declara `: StreamedResponse`.
- **Decisão:** tipar `responder()` e as 4 ações como `Symfony\Component\HttpFoundation\Response`
  (import explícito). Fixar como decisão (estender D-707 ou nota em §4.3) para o implementador
  não repetir o `Illuminate\Http\Response` dos outros controllers.

### A-2 — `posicao_id => sometimes|required` não garante 422 no `historico-mtm` · Veredito: ✅ PROCEDE
- **O que a crítica diz:** com `sometimes`, uma chamada sem `posicao_id` pula a regra → sem 422;
  o controller chama `historicoMtm(0)` → `find(0)` → 404 "Posição não encontrada". Parâmetro
  obrigatório do contrato (§5.2.5) vira 404 (recurso inexistente) em vez de 422 (entrada inválida).
- **A favor de agir:** a mecânica está correta e há **contradição interna na própria spec**:
  D-703 diz "`posicao_id` `required|integer` (só no histórico)", mas o código de §4.3 escreve
  `['sometimes','required','integer']` no `RelatorioRequest` compartilhado — que não enforce
  required em rota nenhuma. Resolver alinha a spec consigo mesma; o custo é baixo.
- **Contraponto:** impacto pequeno no MVP (cliente que esquece o parâmetro recebe um 4xx de
  qualquer jeito). **Recusar** qualquer solução pesada: não precisa de máquina de regra
  condicional por rota nem de validação espalhada.
- **Conferência:** confirmado o comportamento de `sometimes` e o default `0` de
  `request->integer()`. Com B-1 aplicado, o caminho ainda dá 404 (`find(0) ?? throw`).
- **Decisão (núcleo aceito, forma mínima):** tornar `posicao_id` **de fato obrigatório** no
  caminho do histórico — caminho mais limpo é um `HistoricoMtmRequest` dedicado
  (`required|integer`), idiomático e trivial; manter o `RelatorioRequest` para os outros 3.
  Cobrir com teste `historico-mtm` sem `posicao_id` → **422**. (Marquei ✅ e não ◐ porque o
  núcleo é barato e elimina a contradição D-703×§4.3; o "em parte" é só recusar over-engineering.)

### M-1 — Carga base+overlay diverge do motor e (alega) desperdiça query · Veredito: ◐ PROCEDE EM PARTE
- **O que a crítica diz:** §4.1 faz `Posicao::query()->get()->merge(Futuro::query()->get())->keyBy('id')`
  (e idem NDF); como `newFromBuilder` já hidrata a subclasse, o `merge` re-busca as linhas só
  para eager-loadar relações-filhas, custando "2 queries sobre o mesmo conjunto" contra o §9.1;
  o motor resolve por subclasse, sem overlay.
- **A favor de agir:** a **inconsistência de idioma** é real e legítima — `MotorMtm.php:32-36`
  monta a coleção a partir de `collect()` e dá `merge` **por subclasse** (Futuro/Ndf/Opcao/Otc),
  cada uma com seu `with(...)`. Ter dois padrões para o mesmíssimo "carregar posições polimórficas
  com eager loading" é custo de manutenção e leitura.
- **Contraponto (recuso a justificativa de performance):** reconferido — o base+overlay da spec
  faz **2 queries** (base + a subclasse que precisa de relação-filha; as demais já vêm hidratadas
  da base via `newFromBuilder`), enquanto o padrão do motor faz **4 queries** (uma por
  instrumento). Logo o base+overlay é **mais econômico em nº de queries**, não menos; a alegação
  de "desperdiça query / contra o orçamento §9.1" está **invertida**. (A única redundância é
  re-buscar as linhas de FUTURO/NDF — barata e irrelevante no N do MVP.)
- **Conferência:** `MotorMtm.php:31-36` confirma o padrão por subclasse a partir de `collect()`.
  `Posicao.php:67-82` confirma a hidratação polimórfica que torna o overlay desnecessário para os
  instrumentos sem relação-filha eager.
- **Decisão:** **alinhar ao padrão do motor por consistência/legibilidade** (um único idioma de
  carga polimórfica no codebase) — montar a coleção por subclasse com o eager loading de cada uma.
  Mas **registrar na spec** que a motivação é consistência, **não** economia de query (a spec não
  deve repetir a premissa errada da crítica). Alternativa aceitável: manter o base+overlay e
  documentar por que diverge — porém, por DRY de padrão, prefiro alinhar ao motor.

### M-2 — Série soma `mtm_valor`, mas o KPI de RN-018 soma `pl_acumulado` · Veredito: ✅ PROCEDE
- **O que a crítica diz:** §4.1 `plDiario` calcula o escalar `Σ pl_acumulado` (RN-018), mas a
  série usa `SUM(mtm_valor)` e o DTO expõe `serie:[{...pl_acumulado}]` vindo de `mtm_valor`;
  como diferem pelo realizado (RN-023), o último ponto da curva não bate com o cartão "P&L
  acumulado".
- **A favor de agir:** é o único achado que toca o **caminho feliz do usuário** num sistema de
  risco — gráfico e KPI com o mesmo rótulo "acumulado" mostrando números diferentes corrói a
  confiança no relatório. RN-018/RN-023 confirmam que `pl_acumulado = mtm_valor + realizado*câmbio`
  (`requisitos.md:253`, `:1060`, `:1036`).
- **Contraponto:** sem reduções os números coincidem, então no dataset inicial o erro pode passar
  despercebido — mas isso é argumento *a favor* de corrigir agora, não contra (a divergência
  aparece silenciosamente quando o primeiro FUTURO for reduzido).
- **Conferência:** confirmado nas RNs. **Ressalva técnica para o implementador:** trocar
  `SUM(mtm_valor)` por `SUM(pl_acumulado)` na série **reduz mas não zera** o mismatch, porque a
  série agrega por `data_calculo` sobre **todas** as posições daquele dia, enquanto o KPI é o
  snapshot (último `<= data`) só das **ABERTA**. Populações diferentes → último ponto ainda pode
  não bater exatamente.
- **Decisão:** padronizar de forma **honesta**: ou (a) a série passa a `SUM(pl_acumulado)` e o
  rótulo do último ponto deixa de prometer igualdade com o KPI (documentar a diferença de
  população), ou (b) renomear explicitamente as grandezas (série "MtM por dia" × KPI "P&L
  acumulado") para não sugerir que são a mesma curva. Registrar a decisão na spec (estender D-704).

### M-3 — `formato=pdf` → 422 conflita com o contrato §5.2.5 e usa código errado · Veredito: ◐ PROCEDE EM PARTE
- **O que a crítica diz:** §5.2.5 lista `formato=json|csv|pdf` como aceito; a spec aceita `pdf` no
  `in:` e depois responde **422** (`formato_indisponivel`). 422 acusa erro do **cliente**, mas a
  entrada é válida e documentada — quem não suporta é o **servidor** → o código certo é **501**.
- **A favor de agir:** confirmado que `requisitos.md:916` declara `pdf` aceito. Aceitar-na-validação
  e-recusar-no-controller é contraditório dentro da spec, e 501 é semanticamente mais honesto; a
  correção é de 1 linha (`response()->json([...], 501)`).
- **Contraponto:** é **polimento de borda**. PDF está integralmente fora do MVP (D-707) e nenhuma
  tela/cliente do MVP pede `pdf`; o 422 já é "degradação previsível e documentada" (§8). O usuário
  real não tropeça nisto. Não bloqueia código nem CI.
- **Conferência:** §5.2.5 confirma `pdf` no contrato. O envelope §5.1 (`ErroAplicacao::envelope`)
  é `{erro, mensagem}` com status configurável — um 501 manual no formato do envelope é trivial.
- **Decisão (aceito o princípio, prioridade baixa):** adotar a opção (a) da crítica — **501 Not
  Implemented** no envelope §5.1, mantendo `pdf` no `in:` como "previsto, ainda não implementado"
  (coerente com §5.2.5). Ajustar D-707 e o DoD #6. Pode entrar junto com as demais correções, mas
  não é pré-requisito de implementação.

### BX-1 — Populações diferentes de P&L diário × acumulado · Veredito: ◐ (já coberto na spec)
- **O que a crítica diz:** `plDiario` (RN-017) soma `variacao_dia` de **todas** as posições com
  `data_calculo = data`; o acumulado (RN-018) soma só as **ABERTA** (snapshot) — correto, mas sutil;
  sugere comentário para o implementador não "uniformizar" os filtros.
- **A favor de agir:** o risco de o implementador alinhar os dois filtros por engano é real.
- **Contraponto:** **a spec já documenta** explicitamente essa diferença em D-704 ("data exata"
  para RN-017 × "último `<= data` das ABERTA" para RN-018) e nas RNs `requisitos.md:1059-1060`.
  Não é lacuna da spec.
- **Decisão:** nenhuma mudança de spec necessária; no máximo um comentário inline no código na hora
  de `/implementar-especificacao 7`. Coberto.

### BX-2 — `unidade_mista` sem fórmula · Veredito: ✅ PROCEDE
- **O que a crítica diz:** §4.2 introduz `unidade_mista` no `paraArray()` de `ExposicaoProduto` mas
  não define como é computado; sugere `true` quando `mix['NDF']>0 E (mix['FUTURO']>0 OU mix['OTC']>0)`.
- **A favor de agir:** sem fórmula, o teste do campo não é determinístico e dois implementadores
  divergem. Definir é barato e fecha o DoD #4 (que cita `unidade_mista`).
- **Contraponto:** nenhum relevante; é fechar uma frouxidão da própria spec.
- **Conferência:** coerente com D-705/D-705a — só NDF (nocional em moeda) mistura unidade com
  FUTURO/OTC (contratos/quantidade física); OPCAO é `quantidade=1` (não soma grandeza com sentido),
  logo não deve disparar `unidade_mista` sozinha.
- **Decisão:** fixar na spec a regra: `unidade_mista = mix['NDF'] > 0 && (mix['FUTURO'] > 0 ||
  mix['OTC'] > 0)`. Registrar em §4.2/D-705a.

### BX-3 — `DISTINCT ON` é Postgres-only · Veredito: ❌ NÃO PROCEDE (sem ação)
- **O que a crítica diz:** garantir que o ambiente de teste use `postgres_test`, senão a suíte de
  snapshot quebra no SQLite.
- **A favor de agir:** a dependência de Postgres é real (a query de snapshot usa `DISTINCT ON`).
- **Contraponto:** **já garantido e já documentado.** O CLAUDE.md roda a suíte com
  `-e DB_HOST=postgres_test`, e a própria spec lista isto em §3 (pré-requisitos) e na tabela de
  riscos §8. Não há gap a corrigir.
- **Decisão:** nenhuma ação — já coberto pelo ambiente de teste e pela spec.

### BX-4 — Rota `/` (Dashboard) pode colidir com a home do starter kit · Veredito: ◐ (verificação de implementação)
- **O que a crítica diz:** confirmar a rota raiz atual de `routes/web.php` antes de sobrescrever.
- **A favor de agir:** colisão de rota raiz é um erro comum e barato de evitar.
- **Contraponto:** **já está em §8** da spec ("Verificar a rota raiz existente antes de sobrescrever").
  Não é achado novo; é um lembrete de implementação, não mudança de spec.
- **Decisão:** nenhuma mudança de spec; verificar `routes/web.php` ao implementar (login → dashboard,
  §6.2). Coberto.

### BX-5 — `historicoMtm` carrega todo o histórico sem janela · Veredito: ⏳ ADIAR (Fase 12)
- **O que a crítica diz:** para 1 ano (§9.1) é OK, mas registrar que paginação/janela pode ser
  necessária quando a base inchar.
- **A favor de agir:** previsível que séries longas precisem de janela/paginação.
- **Contraponto:** fora do escopo da Fase 7 — performance/escala formal é **Fase 12**
  (`pontos_de_atencao.md`), e a spec já delimita §9.1 como atendido para o MVP sem load test.
- **Decisão:** registrar como nota em `specs/future/pontos_de_atencao.md` (janela do histórico) e
  seguir sem mudança nesta fase.

## 2. Observações fora da crítica

- **Coerência do `erro` no 422/501 do `formato`.** O envelope canônico
  (`ErroAplicacao::envelope`) usa `erro` como **código em CAIXA** (`ERRO_NAO_ENCONTRADO`,
  `ERRO_APLICACAO`). O exemplo de §4.3 responde `['erro' => 'formato_indisponivel']` (minúsculo,
  fora do exception). Não é bloqueador, mas ao mexer em M-3 vale padronizar o código do erro com o
  resto da API (ex.: `FORMATO_INDISPONIVEL`) para o cliente não ver dois estilos de `erro`.
- **RN-019 literal × nocional (D-705).** `requisitos.md:1061` diz "soma de `quantidade × sinal`",
  e a spec usa `quantidadeExposicao()` (nocional para NDF). A crítica **elogiou** isso (com razão,
  é o idioma *fat model*), então não é divergência a reabrir — apenas registro que é um
  **refinamento de implementação** sobre a RN, não uma reescrita da regra; mantém-se.

## 3. O que de fato corrigir na spec (recomendação priorizada)

| # | Achado | Veredito | Ação na spec |
|---|---|---|---|
| 1 | B-1 | ✅ | `find() ?? throw new ErroNaoEncontrado(...)` no `historicoMtm`; remover nota hedge e a anotação `§5.1`. |
| 2 | A-1 | ✅ | Tipar `responder()`/ações como `Symfony\...\Response`; fixar como decisão (estender D-707). |
| 3 | A-2 | ✅ | `posicao_id` obrigatório de fato no histórico (`HistoricoMtmRequest` dedicado); teste 422 sem o parâmetro; alinhar D-703×§4.3. |
| 4 | M-2 | ✅ | Série "acumulado" coerente com RN-018 (`SUM(pl_acumulado)` **ou** renomear grandezas); documentar a diferença de população; estender D-704. |
| 5 | BX-2 | ✅ | Fixar `unidade_mista = mix['NDF']>0 && (mix['FUTURO']>0 \|\| mix['OTC']>0)` em §4.2/D-705a. |
| 6 | M-1 | ◐ | Alinhar carga ao padrão por subclasse do motor (consistência), **sem** repetir a premissa de "economia de query" (é o contrário). |
| 7 | M-3 | ◐ | `formato=pdf` → **501** no envelope §5.1; manter `pdf` no `in:`; ajustar D-707/DoD #6. Prioridade baixa. |
| 8 | BX-1 | ◐ | Nenhuma — já em D-704; só comentário inline ao implementar. |
| 9 | BX-4 | ◐ | Nenhuma — já em §8; verificar `routes/web.php` ao implementar. |
| 10 | BX-5 | ⏳ | Registrar janela do histórico em `pontos_de_atencao.md` (Fase 12). |
| 11 | BX-3 | ❌ | Nenhuma — `postgres_test` já garantido (CLAUDE.md/§3/§8). |

## 4. Veredito de custo-benefício

Vale reescrever a spec, mas o conjunto é enxuto. **Pré-requisitos que bloqueiam código/CI:**
**B-1** (404 fora do envelope derruba o teste de §5.1 e o DoD #5) e **A-1** (PHPStan nível 8
vermelho, DoD #9) — baratos e inegociáveis. **Pré-requisito de qualidade do produto:** **M-2**
(gráfico × KPI divergentes num relatório de risco). **Polimento barato que remove ambiguidade da
própria spec:** **A-2** (contradiz D-703) e **BX-2** (`unidade_mista` sem fórmula) — entram junto.
**M-1** e **M-3** são alinhamentos de consistência/semântica que podem ir na mesma revisão, mas
não bloqueiam a implementação; ao incorporar **M-1**, corrigir a justificativa (consistência, não
economia de query). Os baixos restantes (BX-1/3/4) já estão cobertos pela spec/ambiente e BX-5 vai
para `pontos_de_atencao.md`.

**Em uma frase:** corrigir **B-1, A-1, A-2, M-2 e BX-2 antes de implementar**; **M-1 e M-3** entram
na mesma reescrita como polimento (com a ressalva sobre a premissa de query em M-1); o restante é
nota ou já está coberto.
