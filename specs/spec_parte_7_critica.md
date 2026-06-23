# Crítica construtiva — `spec_parte_7.md` (Relatórios — Fase 7)

> **Autor da revisão:** arquiteto de software sênior.
> **Base da análise:** `specs/spec_parte_7.md` confrontada com `specs/requisitos.md`
> (v1.7, fonte da verdade — §5.2.5, §7.4 RN-016..019, §6.1, §9.1, §5.1),
> `specs/passos_dev.md` (Fase 7) e o **código real**: `bootstrap/app.php`,
> `app/Exceptions/ErroNaoEncontrado.php`, `app/Services/ServicoProdutos.php` /
> `ServicoPosicoes.php` (padrão 404), `app/Models/Posicao.php` (`newFromBuilder`),
> `app/Models/Ndf.php`/`Futuro.php`/`Perna.php`, `app/Services/Dados/PosicaoResumo.php`,
> `app/Http/Controllers/Api/V1/PosicaoController.php`, `app/Support/Csv/ImportadorPrecosCsv.php`.
> **Natureza:** endurecimento da spec antes da implementação. Não altera regras de
> negócio nem contratos — onde houver divergência, `requisitos.md` prevalece.

## Veredito geral

A spec é madura, bem estruturada e fiel ao recorte do MVP. Os acertos centrais são
sólidos: leitura agregada sem recalcular MtM (D-701), snapshot do "último MtM ≤ data"
em **um** `SELECT` com `DISTINCT ON` (D-702) e — após a revisão da exposição — o
**nocional polimórfico** (`quantidadeExposicao()`, D-705) com a OPCAO honestamente
excluída (D-705a) e o mismatch de unidade documentado. Isso é exatamente o padrão
*fat model* do projeto, e a rastreabilidade RN-016..019 está correta na camada certa.

Os problemas concentram-se em **dois pontos de borda HTTP** que o código de exemplo
erra contra o repositório: (1) o 404 do `historico-mtm` via `findOrFail`, que reintroduz
**exatamente o bug que o commit `2b45a71` corrigiu** (404 fora do envelope §5.1), e
(2) a tipagem de retorno do controller que negocia `json|csv`, que não fecha no
PHPStan nível 8 exigido pelo DoD. Há ainda uma inconsistência semântica entre o número
canônico de P&L acumulado (RN-018) e a série do gráfico, e um padrão de carga
polimórfica duplicada que diverge do motor (Fase 6). Nenhum desses toca regra de
negócio — são correções de implementação/redação. **1 bloqueador, 2 altos, 3 médios.**

## 1. Bloqueadores

### B-1. `historicoMtm` usa `findOrFail` → 404 **fora** do envelope §5.1 (contradiz código e DoD)

**O que a spec diz.** §4.1: `Posicao::query()->findOrFail($posicaoId); // → ErroNaoEncontrado (§5.1)`
e a nota em §4.1 "o handler global já traduz `ModelNotFoundException`/`ErroNaoEncontrado`".
O DoD #5 afirma: "`posicao_id` inexistente → `404` no envelope §5.1".

**Por que é problema (evidência).** `bootstrap/app.php:24` registra `->render()` **somente**
para `ErroAplicacao` (`if ($request->expectsJson() || $request->is('api/*'))`).
`ModelNotFoundException` **não** é `ErroAplicacao` (`ErroNaoEncontrado extends ErroAplicacao`,
mas `findOrFail` lança `Illuminate\Database\Eloquent\ModelNotFoundException`). Não há
`render` para ela → o Laravel devolve o 404 **padrão** `{ "message": "..." }`, não
`{ "erro", "mensagem" }`. Este é **literalmente o bug do commit `2b45a71`** ("Antes o 404
vazava no formato padrão do Laravel ({message}); agora responde { erro, mensagem }"), que
trocou `findOrFail` por `find() ?? throw` em `ServicoPosicoes`/`ServicoProdutos`
(`ServicoProdutos.php:31-32`, `ServicoPosicoes.php:56-57`).

**Consequência.** O código de exemplo, seguido ao pé da letra, produz 404 em formato
**diferente** do resto da API — quebra o DoD #5 e a consistência §5.1, e o teste de 404
do envelope falharia.

**Recomendação.** Seguir o padrão já canônico no repositório:
```php
Posicao::query()->find($posicaoId)
    ?? throw new \App\Exceptions\ErroNaoEncontrado('Posição não encontrada.');
```
Remover a nota hedge "confirme o mapeamento / troque por ?? throw" — o mapeamento **já é
conhecido** (não traduz `ModelNotFoundException`), então a spec deve trazer o `find() ?? throw`
direto, não como alternativa. Corrigir também a anotação `// → ErroNaoEncontrado` no exemplo.

## 2. Altos

### A-1. Tipo de retorno do controller que negocia `formato` não fecha no PHPStan nível 8

**O que a spec diz.** §4.3: `private function responder(...): Response` e os métodos públicos
`posicaoAberta(...): Response`, retornando em `match`: `app(ExportadorCsv::class)->resposta(...)`
(um `StreamedResponse`, §4.4), `response()->json(..., 422)` (pdf) e `response()->json($json)` (json).

**Por que é problema (evidência).** `ExportadorCsv::resposta()` é declarado
`: StreamedResponse` (`Symfony\Component\HttpFoundation\StreamedResponse`, §4.4). `response()->json()`
devolve `Illuminate\Http\JsonResponse`. O supertipo comum de `StreamedResponse` e
`JsonResponse` é **`Symfony\Component\HttpFoundation\Response`**, **não** `Illuminate\Http\Response`
(`JsonResponse`/`StreamedResponse` estendem o `Response` do Symfony, não o do Illuminate).
Se `Response` no exemplo for `Illuminate\Http\Response` (como nos outros controllers, p.ex.
`PosicaoController::destroy(): Response`), a união viola o tipo — erro de PHPStan nível 8
(exigido pelo Checklist §6 e DoD #9) e potencial `TypeError` em runtime.

**Consequência.** A suíte/CI de tipagem não passa com o código como exemplificado; quem
implementar vai descobrir só ao rodar o phpstan.

**Recomendação.** Tipar o retorno do `responder()` e das ações como
`Symfony\Component\HttpFoundation\Response` (ou `\Illuminate\Http\Response|\Symfony\...\StreamedResponse`
explícito). Fixar isso como decisão (ex.: estender D-707) para o implementador não tropeçar.

### A-2. `RelatorioRequest` com `posicao_id => sometimes|required` não garante 422 no `historico-mtm`

**O que a spec diz.** §4.3: `'posicao_id' => ['sometimes','required','integer']` em um **único**
`RelatorioRequest` compartilhado pelos 4 endpoints; o controller faz
`$this->rel->historicoMtm($request->integer('posicao_id'))`.

**Por que é problema.** `sometimes` só valida o campo **se presente**. Numa chamada
`GET /relatorios/historico-mtm` **sem** `posicao_id`, a regra é pulada → não há 422; o
controller chama `historicoMtm(0)` (default de `integer()`), que cai no `find(0)` e
lança 404 "Posição não encontrada". Ou seja: **requisição malformada do cliente vira 404**
(recurso inexistente) em vez de **422** (entrada inválida) — semântica HTTP errada para um
parâmetro obrigatório do contrato §5.2.5 (`historico-mtm?posicao_id=X`).

**Consequência.** Mistura "faltou parâmetro" com "posição não existe"; dificulta o cliente
distinguir o próprio erro de um id válido porém ausente.

**Recomendação.** Tornar `posicao_id` **obrigatório de fato** no caminho do histórico —
ou um Form Request dedicado (`HistoricoMtmRequest` com `required|integer`), ou regra
condicional por rota. Cobrir com teste: `historico-mtm` sem `posicao_id` → **422**.

## 3. Médios

### M-1. Carga polimórfica duplicada (`base + overlay`) diverge do padrão do motor e desperdiça query

**O que a spec diz.** §4.1 `posicaoAberta` e `exposicaoLiquida`:
`Posicao::query()->...->get()->merge(Futuro::query()->...->get())->keyBy('id')` (e idem com `Ndf`).

**Por que é problema (evidência).** `Posicao::newFromBuilder` (`Posicao.php:67-82`) **já**
hidrata a subclasse correta por `instrumento`. Logo `Posicao::query()->where('status','ABERTA')->get()`
**já retorna** instâncias `Futuro`/`Ndf`/`Opcao`/`Otc`. O `merge(Futuro::query()...)` **re-busca**
as mesmas linhas de futuro só para ter `futuro`/`movimentacoes` eager-loaded, e o `keyBy('id')`
faz o overlay sobrescrever — funciona, mas custa **2 queries sobre o mesmo conjunto** e é mais
difícil de ler. O motor (Fase 6, `MotorMtm::processarDia`) resolveu o mesmo problema **sem**
overlay: faz `merge` **por subclasse** (`Futuro::...->get()` ∪ `Ndf::...->get()` ∪ ...), cada uma
com seu eager loading, sem carregar a base inteira antes.

**Consequência.** Inconsistência de padrão entre Fase 6 e Fase 7 para o mesmíssimo problema;
trabalho redundante de banco (contra o orçamento §9.1, ainda que pequeno no MVP).

**Recomendação.** Adotar o **mesmo padrão do motor** — montar a coleção por subclasse
(`Futuro` com `['produto','futuro','movimentacoes']`; `Ndf` com `['produto','ndf']`; `Opcao`/`Otc`
com `produto`), sem a carga base + overlay. Alinha as duas fases e remove a query duplicada.

### M-2. Série do gráfico soma `mtm_valor`, mas o número canônico de RN-018 soma `pl_acumulado`

**O que a spec diz.** §4.1 `plDiario`: o escalar `$plAcumulado = Σ pl_acumulado` (snapshot, RN-018),
mas a **série** usa `selectRaw('... SUM(mtm_valor) AS pl_acum ...')`; o DTO `ResumoPL` expõe
`serie: [{data, pl_dia, pl_acumulado}]` (§4.2) onde esse `pl_acumulado` vem de `SUM(mtm_valor)`.

**Por que é problema.** `mtm_valor` e `pl_acumulado` **diferem pelo P&L realizado** das reduções
(RN-023: `pl_acumulado = mtmBrl + plRealizado*cambio`). O último ponto da série (`SUM(mtm_valor)`)
**não baterá** com o cartão "P&L acumulado" (`Σ pl_acumulado`) sempre que houver realizado —
para o usuário, gráfico e KPI mostrando números diferentes com o mesmo rótulo "acumulado".
A escolha de `mtm_valor` parece herdada do mock (`RelPLScreen` faz `plAcum = Σ mtm_valor`), que
provavelmente é anterior à consolidação de RN-023.

**Consequência.** Inconsistência visível entre o headline (RN-018) e a curva; relatório de P&L
acumulado pouco confiável quando há reduções.

**Recomendação.** Padronizar a série de "acumulado" em `SUM(pl_acumulado)` (consistente com RN-018),
**ou** renomear explicitamente as duas grandezas (ex.: série "MtM por dia" vs KPI "P&L acumulado")
para não sugerir igualdade. Registrar a decisão na spec.

### M-3. `formato=pdf` → `422` conflita com o contrato §5.2.5 (que lista `pdf`) e usa código semanticamente errado

**O que a spec diz.** D-707/§4.3: `pdf` é aceito pelo `RelatorioRequest` (`in:json,csv,pdf`) mas o
controller responde **`422`** `{"erro":"formato_indisponivel"}`.

**Por que é problema.** §5.2.5 declara "Todos os relatórios aceitam o parâmetro opcional
`formato=json|csv|pdf`" — um cliente lendo o contrato espera `pdf` **suportado**. Além disso,
`422 Unprocessable Entity` semanticamente acusa **erro do cliente** (entrada inválida), mas aqui
a entrada é **válida e documentada**; quem não suporta é o **servidor** → o código correto seria
**`501 Not Implemented`**. Aceitar `pdf` na validação (`in:...,pdf`) e depois rejeitá-lo é
contraditório dentro da própria spec.

**Consequência.** Contrato ambíguo (aceita-mas-recusa) e código HTTP que culpa o cliente por um
gap do servidor.

**Recomendação.** Escolher uma postura coerente: (a) **`501 Not Implemented`** com o envelope
§5.1 (mantém `pdf` no contrato como "previsto, ainda não implementado"); ou (b) remover `pdf` do
`in:...` e responder 422 só como "formato inválido" até a fase de hardening. Preferir (a) — alinha
com §5.2.5 e usa o código certo. Ajustar D-707 e o DoD #6.

## 4. Baixos

- **BX-1.** **Populações diferentes de P&L diário × acumulado.** `plDiario` (RN-017) soma
  `variacao_dia` de **todas** as posições com MtM na data (`data_calculo = data`), enquanto o
  acumulado (RN-018) soma só as **ABERTA** (snapshot). Está **correto** conforme as RNs ("todas"
  vs "abertas"), mas é sutil — vale um comentário no código/§4.1 para o implementador não
  "uniformizar" os dois filtros por engano.
- **BX-2.** **`unidade_mista` sem fórmula.** §4.2 introduz `unidade_mista` no `paraArray()` de
  `ExposicaoProduto`, mas não define **como** é computado. Sugerir a regra explícita: `true` quando
  o produto tem `mix['NDF'] > 0` **e** (`mix['FUTURO'] > 0` ∨ `mix['OTC'] > 0`) — i.e. soma nocional
  (moeda) com contratos. Fixar para o teste ser determinístico.
- **BX-3.** **`DISTINCT ON` é Postgres-only.** Já está na tabela de riscos, mas reforço: garantir
  que o ambiente de teste use `postgres_test` (CLAUDE.md já o faz) — a suíte de snapshot quebra em
  SQLite. Sem ação se o CI mantém Postgres.
- **BX-4.** **Rota `/` (Dashboard) pode colidir com a home do starter kit.** Já listado em §8;
  confirmar a rota raiz atual de `routes/web.php` antes de sobrescrever (login → dashboard, §6.2).
- **BX-5.** **`historicoMtm` carrega todo o histórico sem janela.** Para 1 ano (§9.1) é OK, mas
  registrar que uma paginação/limite por janela pode ser necessária quando a base inchar (Fase 12).

## 5. Pontos fortes (para preservar)

- **Nocional polimórfico (D-705/§4.1a):** `quantidadeExposicao()` na base + override só no `Ndf`
  é a forma idiomática do *fat model* (espelha `calcularMtm()`/`plRealizado()`); novo instrumento
  = novo override, Service não muda. Consistente com `Ndf::calcularMtm` que já lê `valor_nocional`.
- **OPCAO excluída com honestidade (D-705a):** reconhecer que exposição direcional de opção exige
  delta (fora do MVP) e manter `quantidade = 1` é melhor que inventar um número sem significado.
- **Mismatch de unidade documentado (D-705a):** expor `mix` e `unidade_mista` em vez de esconder a
  soma apples-to-oranges é a decisão certa para um sistema de risco.
- **Snapshot único (D-702):** `DISTINCT ON (posicao_id) ... ORDER BY posicao_id, data_calculo DESC`
  casa com `idx_mtm_posicao_data` e mata o N+1 do "último MtM ≤ data"; tratar buraco de feriado com
  `<= data` (não `= data`) é correto.
- **Reuso de cálculo:** PM do FUTURO via `Futuro::precoMedio()` (sem duplicar fórmula) e CSV
  reaproveitando o endurecimento CWE-1236 do importador (mesma lista de prefixos) — DRY e seguro.
- **Deferrals coerentes:** PDF, status histórico, BCMath e decomposição preço×câmbio empurrados com
  rastreabilidade às críticas herdadas (`pontos_de_atencao.md`).

## 6. Ações recomendadas (prioridade)

| # | Severidade | Ação |
|---|---|---|
| B-1 | Bloqueador | Trocar `findOrFail` por `find() ?? throw new ErroNaoEncontrado(...)` no `historicoMtm`; remover a nota hedge e corrigir a anotação `§5.1`. |
| A-1 | Alto | Tipar `responder()`/ações como `Symfony\...\Response` (ou união explícita) para fechar PHPStan nível 8; fixar como decisão. |
| A-2 | Alto | `posicao_id` obrigatório de fato no `historico-mtm` (Form Request dedicado ou regra por rota); teste de 422 sem o parâmetro. |
| M-1 | Médio | Montar a coleção **por subclasse** (padrão do motor), eliminando a carga base + overlay e a query duplicada. |
| M-2 | Médio | Série "acumulado" em `SUM(pl_acumulado)` (consistente com RN-018) **ou** renomear as grandezas; registrar a decisão. |
| M-3 | Médio | `formato=pdf` → **`501`** no envelope §5.1 (mantém o contrato §5.2.5) ou tirar `pdf` do `in:`; ajustar D-707/DoD #6. |
| BX-1..5 | Baixo | Comentar populações de P&L; definir fórmula de `unidade_mista`; reforçar Postgres no CI; checar rota `/`; nota de janela no histórico. |

> **Nota final.** Nada aqui exige mudar `requisitos.md` (§3/§5/§7) — RN-016..019 seguem aplicadas
> como escritas, e o nocional polimórfico é refinamento de **implementação**, não de regra. O único
> achado que afeta o caminho feliz do usuário é **M-2** (gráfico × KPI de P&L acumulado divergentes),
> que vale corrigir antes de a tela ir ao ar. **B-1** e **A-1** são baratos e impedem,
> respectivamente, um 404 fora de padrão e um build de tipagem vermelho — corrigi-los na revisão da
> spec deixa a Fase 7 pronta para `/implementar-especificacao 7` sem surpresas.
