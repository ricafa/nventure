# Crítica construtiva — `spec_parte_5.md` (Módulo Posições — Fase 5)

> **Autor da revisão:** arquiteto de software sênior.
> **Base da análise:** `specs/spec_parte_5.md` confrontada com `specs/requisitos.md`
> (v1.7, fonte da verdade — §3.2.3–3.2.8, §4.2–4.5, §5.2.3, §7.1/§7.1a), `specs/passos_dev.md`
> (Fase 5) e o **código real** do repositório: `app/Models/Posicao.php`,
> `app/Models/Futuro.php`, `app/Models/PosicaoFuturo.php`, `app/Models/Movimentacao.php`,
> `app/Models/Concerns/{ReproduzMovimentacoes,ConverteDecimais}.php`,
> `app/Services/{ServicoProdutos,ServicoPrecos}.php`, `app/Exceptions/*`,
> `bootstrap/app.php`, `routes/api.php` e as migrations de `posicao`, `posicao_futuro`
> e `posicao_movimentacao`. Reaproveita a metodologia de `specs/parte_4_critica.md` e
> verifica pendências herdadas de `specs/future/pontos_de_atencao.md`.
> **Natureza:** endurecimento da spec antes da implementação. Não altera regras de
> negócio, modelo de dados nem contratos de API — onde houver divergência,
> `requisitos.md` prevalece.

## Veredito geral

A spec acerta no esqueleto e nas travas de concorrência: as decisões D-501 (transação +
`lockForUpdate`), D-502 (deleção condicionada à ausência de MtM) e D-503 (catch de
SQLSTATE `23505`) estão alinhadas ao padrão já consolidado na Parte 4
(`ServicoProdutos`/`ServicoPrecos`) e ao `CLAUDE.md`. A separação Service/DTO e a adesão
ao *fat model* (replay no Model, sem `if`/`switch` por tipo) são corretas.

Porém, **a parte mais importante da fase — o cadastro dos 4 instrumentos
(RN-001..006) — não está especificada em código**. O §4 só exemplifica `movimentarFuturo`,
`criarAbertura` e `remover`; o objetivo central (`POST /posicoes/futuro|ndf|opcao|otc`,
RN-020 com a transação mãe+filha+ABERTURA) aparece apenas enumerado no checklist. Isso
deixa a maior parte do esforço sem contrato.

Há **dois bloqueadores verificáveis contra o esquema**: (B-1) D-504 manda gravar
`preco_medio` numa coluna que **não existe** em `posicao_futuro`; (B-2) os `create()` de
`posicao` e `posicao_movimentacao` não preenchem `criado_por`, que é `NOT NULL` sem
default — **toda inserção da fase falharia** com `QueryException` (500). Some-se a isso um
problema de correção no replay pós-insert (A-3) e a denormalização prematura de D-504, que
contradiz o motor da §4.4 (A-2). Nenhum desses itens exige mudar §3/§5/§7 do `requisitos.md`.

---

## 1. Bloqueadores

### B-1. D-504 grava `preco_medio` numa coluna inexistente em `posicao_futuro`

A spec, em **D-504** (§0) e no §4.1 (`$posicao->futuro->update(['preco_medio' => ...])`),
manda "consolidar `preco_medio` (na filha `PosicaoFuturo`) a cada movimentação". Mas o
esquema real de `posicao_futuro` tem **apenas** três colunas:

```php
// database/migrations/2026_06_19_100400_create_posicao_futuro_table.php
$table->unsignedInteger('posicao_id')->primary();
$table->decimal('preco_entrada', 18, 6);
$table->string('codigo_contrato', 20);
```

Não há `preco_medio` — coerente com `requisitos.md` §3.2.4, que define `preco_entrada`
como "**Não** é o preço médio — o preço médio é derivado de `posicao_movimentacao`
(RN-021)". O Model `PosicaoFuturo` (`app/Models/PosicaoFuturo.php`) casta só
`preco_entrada`.

**Consequência prática:** `$posicao->futuro->update(['preco_medio' => …])` lança
`QueryException` (coluna inexistente) → **500** em toda movimentação. Criar a coluna
mudaria o modelo de dados §3 — o que a própria spec proíbe (cabeçalho: "não altera modelo
de dados").

**Recomendação:** remover o cache de `preco_medio` de D-504 e §4.1. A `quantidade` deve
continuar sendo consolidada (é coluna real em `posicao` **e** é mandatória pela RN-024); o
`preco_medio` permanece **derivado** via `Futuro::precoMedio()`/`replay()`, como já
implementado. Ver também A-2 (a premissa de "leitura O(1) pelo motor" também não procede).

### B-2. `criado_por` (`NOT NULL`) não é preenchido nos `create()` → toda inserção falha

`posicao.criado_por` e `posicao_movimentacao.criado_por` são `VARCHAR(60) NOT NULL` **sem
default**:

```php
// create_posicao_table.php           $table->string('criado_por', 60);
// create_posicao_movimentacao_table.php  $table->string('criado_por', 60);
```

(confirmado também em `requisitos.md` §3.2.3 e §3.2.4a). Todos os `create()` exibidos na
spec omitem o campo: §4.1 `$posicao->movimentacoes()->create($dados)` e
`criarAbertura(...->create($dadosAbertura + ['tipo' => 'ABERTURA']))`; o `criarFuturo`
(não mostrado) também precisaria de `posicao.criado_por`. Os payloads da §5.2.3 **não**
trazem `criado_por` (é campo de auditoria, vem do usuário autenticado).

**Consequência prática:** `not_null_violation` (SQLSTATE `23502`) → `QueryException` não
tratada → **500** em todo cadastro e toda movimentação. É a primeira fase a tocar tabelas
com `criado_por` (produto/preço da Parte 4 só têm `criado_em`), por isso o ponto é inédito
e não está coberto por nada existente.

**Consequência agravante:** a autenticação/`auth:sanctum` só emite usuário real na Fase 10
(D-402, `routes/api.php`). Até lá não há `auth()->user()` consumível, então a spec precisa
**definir a origem** do valor (ex.: `Auth::user()?->login ?? 'sistema'` como placeholder
temporário, a ser endurecido na Fase 10), e os Form Requests **não** devem aceitar
`criado_por` do cliente (anti-spoofing de auditoria — §2.3).

**Recomendação:** o Service injeta `criado_por` em todo `create()` de `posicao` e
`posicao_movimentacao`, a partir do contexto de autenticação (com fallback documentado
enquanto a Fase 10 não chega). Adicionar item ao checklist §6 e um teste que falharia hoje.

---

## 2. Altos

### A-1. O cadastro dos 4 instrumentos (objetivo central, RN-001..006) não está especificado

O §1 declara como objetivo "cadastro completo dos 4 instrumentos" e `passos_dev.md`
(Fase 5) detalha `criarFuturo` (mãe + filha + `ABERTURA` na **mesma transação**, RN-020) e
as validações RN-001..006/RN-004a..e. Mas o §4 "Passo a passo" só traz código de
`movimentarFuturo`, `criarAbertura` e `remover`. **Não há** exemplo de `criarFuturo`,
`criarNdf`, `criarOpcao` (com pernas), `criarOtc`, nem onde cada RN é aplicada:

- RN-001 (quantidade > 0), RN-002 (vencimento > entrada): Form Request.
- RN-003 (BALCAO exige contraparte; BOLSA não): Form Request condicional.
- RN-004a (≥ 1 perna), RN-004b (≥ 4 pernas suportadas), RN-004e (mãe `quantidade=1`,
  `lado` informativo): Form Request + persistência das pernas.
- RN-005 (nocional NDF > 0): Form Request.
- **RN-006 (indexador do OTC corresponde a um produto cadastrado): exige lookup no banco**
  → é regra de **Service**, não de Form Request.

**Consequência:** o desenvolvedor fica sem contrato para ~70% do trabalho da fase; a
transação atômica da RN-020 (que `uq_mov_abertura` pressupõe) não tem desenho. A Parte 4
foi elogiada justamente pela separação Form Request × Service (parte_4_critica, "Pontos
fortes"); aqui essa separação não foi transposta.

**Recomendação:** especificar, ainda que enxuto, (1) o `criarFuturo` transacional
(mãe → filha `posicao_futuro` → `ABERTURA` com `data_movimentacao = data_entrada`,
RN-020), (2) os demais três `criar*`, e (3) a tabela RN × camada (Form Request × Service)
nos moldes da Parte 4, deixando explícito que RN-006 é checagem de existência no Service.

### A-2. D-504 cria dupla fonte de verdade e contradiz o motor da §4.4

Mesmo descontado o B-1 (coluna inexistente), a **justificativa** de D-504 não procede: ela
afirma que "o motor (Fase 6) lerá esses campos em O(1), sem reprocessar `replay()`". Mas o
motor **da fonte da verdade** (`requisitos.md` §4.4) faz eager loading de
`futuro.movimentacoes` e chama `$posicao->calcularMtm()`, que em `Futuro`
(`app/Models/Futuro.php`) deriva tudo de `precoMedio()`/`quantidadeAtual()` → `replay()`.
Ou seja, o motor especificado **não consome** um `preco_medio` persistido; ele recalcula.

Persistir `preco_medio` (um valor **derivado**, RN-021) ao lado da derivação cria duas
fontes de verdade para um número financeiro — exatamente o risco que `pontos_de_atencao.md`
(§3) alerta sobre o *fat model*. Se um dia divergirem (bug no consolidado, migração,
correção manual), o P&L fica inconsistente e silenciosamente errado.

**Distinção importante:** atualizar `posicao.quantidade` é **correto e obrigatório**
(coluna real + RN-024). O problema é exclusivamente o cache de `preco_medio`.

**Recomendação:** manter a consolidação de `quantidade`/`status` (RN-024/RN-022) e **abolir**
o cache de `preco_medio`. Se, mais adiante, a performance do motor (§9.1, 1.000 posições <
30 s) exigir denormalização, isso é decisão da Fase 6/12, com migração própria e teste de
consistência cache⇄replay — não se antecipa aqui sobre coluna inexistente.

### A-3. Replay/recompute sobre relação `movimentacoes` estagnada após o `INSERT`

Em `movimentarFuturo` (§4.1), o fluxo é: `findOrFail` (com lock) → `create($dados)` →
recomputar estado. Mas `Posicao::lockForUpdate()->findOrFail()` **não** carrega
`movimentacoes`, e após `$posicao->movimentacoes()->create(...)` a relação (se já tiver
sido acessada) fica **estagnada** — não inclui a movimentação recém-criada. Um
`Futuro::replay()` rodando sobre essa coleção calcularia `quantidade`/`preco_medio`/
`realizado` **sem a nova movimentação**, gravando estado errado (e podendo deixar a RN-022
passar uma redução excedente).

**Consequência:** valor consolidado incorreto exatamente no caminho feliz — o tipo de bug
financeiro mais perigoso (passa nos testes simples, falha no encadeamento real).

**Recomendação:** após o `create`, recarregar explicitamente
(`$posicao->load('movimentacoes')` ou reconsultar com eager loading dentro da transação)
antes de chamar `replay()`/derivar o estado. Especificar isso no §4.1 e cobrir com um teste
de "duas movimentações na mesma requisição/sequência". A validação da RN-022
(redução > saldo) deve ocorrer **antes** do `create`, lendo a quantidade já sob lock.

---

## 3. Médios

### M-1. `EstadoMovimentacao` está no escopo mas some do checklist/estrutura; `movimentarFuturo` devolve o tipo errado

D-505 (§0) e o mapa §2 citam o DTO `EstadoMovimentacao`, e a §5.2.3 da fonte define que a
resposta de `POST /posicoes/{id}/movimentacoes` é o **estado recalculado**
(`quantidade_atual`, `preco_medio`, `pl_realizado`, `status`). Porém: a estrutura §5 e o
checklist §6 listam só `PosicaoResumo` e `PosicaoDetalhe` (omitem `EstadoMovimentacao`), e
a assinatura `movimentarFuturo(...): Movimentacao` (§4.1) **retorna a movimentação**, não o
estado. O controller precisaria recomputar o estado por fora.

**Recomendação:** incluir `EstadoMovimentacao` na estrutura §5 e no checklist §6, e fazer o
Service retornar esse DTO (montado a partir de `precoMedio()/quantidadeAtual()/plRealizado()`
da posição já recarregada — ver A-3), que é o que a §5.2.3 contrata.

### M-2. `SalvarPosicaoRequest` único para 4 payloads polimórficos

O §2 prevê um único `SalvarPosicaoRequest` para FUTURO, NDF, OPCAO (com `pernas[]`) e OTC —
schemas muito diferentes (nocional, indexador, array de pernas). É o mesmo problema que
`parte_4_critica.md` M-5 levantou para PUT/PATCH: uma classe não alterna regras sozinha.
Validar `pernas` só quando OPCAO, `valor_nocional` só quando NDF, `contraparte` condicional
ao `mercado` (RN-003) num só Request fica frágil.

**Recomendação:** ramificar por rota/instrumento (um Request por `criar*`, ou regras
condicionais por `instrumento`) e fixar na spec onde cada RN-001..006 é validada.

### M-3. RN-022 (encerramento por quantidade zero) e a igualdade de `float`

A §8 (riscos) reconhece o "fechamento zumbi" (`0.0001` de resto), mas o §4 não concretiza.
`ReproduzMovimentacoes::reproduzir()` devolve `qtd` como `float` cru; comparar `qtd == 0`
para encerrar (RN-022) ou `reducao > qtd` para rejeitar (RN-022 → 422) é frágil em ponto
flutuante.

**Recomendação:** especificar o uso de `ConverteDecimais::arredonda($qtd, 4)` (helper já
existente) antes de comparar com zero/saldo, com tolerância explícita (epsilon de 1e-4,
casando com `NUMERIC(18,4)`). Definir também **qual** é a fonte da quantidade na decisão de
encerrar: `posicao.quantidade` (consolidada sob lock, RN-024) — uma só, para não divergir
do replay.

### M-4. O enquadramento de "race condition" em D-503 é dúbio

D-503 justifica o catch de `23505` em `uq_mov_abertura` como tratamento de "race conditions
no pré-SELECT" durante a criação de um futuro. Mas, num `criarFuturo`, o `posicao_id` é
**recém-gerado** na mesma transação — não há um segundo processo inserindo `ABERTURA` para
esse id concorrentemente; o cenário de corrida descrito não se materializa. O valor **real**
de `uq_mov_abertura` é impedir uma **segunda** `ABERTURA` (ex.: alguém tentar criar abertura
via `movimentarFuturo`/reprocessamento), não uma corrida de criação.

**Recomendação:** manter o catch (defesa em profundidade barata e correta), mas reescrever a
justificativa de D-503 para "garante unicidade da ABERTURA / impede abertura duplicada",
sem invocar uma corrida inexistente.

### M-5. Ação `encerrar` sem semântica definida

O §1, a DoD (item 1) e `passos_dev.md` exigem `encerrar` (ação, `POST /posicoes/{id}/encerrar`),
mas o §4 não traz código nem regra. Para FUTURO o encerramento acontece por redução total
(RN-022); então `encerrar` serve sobretudo a NDF/OPCAO/OTC, que **não têm movimentações**.
Falta definir: que estados de origem são permitidos (ABERTA → ENCERRADA?), o que acontece
se já houver MtM, e se há diferença para VENCIDA (RN-014, Fase 6).

**Recomendação:** especificar `encerrar` (transição de status idempotente, provavelmente só
de `ABERTA` para `ENCERRADA`), distinguindo-o do encerramento automático por redução total
do FUTURO.

### M-6. D-506 repete o risco do B-1 da Parte 4 (`ConverteDecimais` é trait)

D-506 diz que "a formatação para `float` fica na borda do `Service`, respeitando
`ConverteDecimais` dos Models". `ConverteDecimais` é **trait** (`app/Models/Concerns/`) —
chamá-lo estaticamente de um Service (`ConverteDecimais::paraFloat(...)`) **não compila**,
exatamente o B-1 de `parte_4_critica.md`. No fluxo da Parte 5, o Service em geral não
precisa converter: recebe `float` já pronto dos métodos do Model
(`$futuro->precoMedio()`, etc.).

**Recomendação:** reescrever D-506 para deixar claro que o Service obtém os `float` pelos
**métodos dos Models** (que já encapsulam `ConverteDecimais` via `self::`), e não chamando o
trait diretamente — evitando reincidir no B-1.

---

## 4. Baixos

- **BX-1.** A estrutura de arquivos §5 omite `app/Http/Resources/PosicaoResource.php`
  (listado no §2) e `app/Services/Dados/EstadoMovimentacao.php` (D-505). Sincronizar §2/§5/§6.
- **BX-2.** D-502 usa `mtmDiarios()->doesntExist()` e o §4.2 usa `mtmDiarios()->exists()`
  com `throw` — equivalentes, mas padronizar a redação. (A relação `mtmDiarios()` existe em
  `Posicao`, ok.)
- **BX-3.** O checklist §6 ("Endpoints `/api/v1/posicoes`") não enumera os **9** endpoints da
  §5.2.3 (4 `POST /posicoes/{tipo}`, `encerrar`, `DELETE`, `GET`/`POST /movimentacoes`,
  `GET /{id}`); `routes/api.php` hoje só tem produtos/preços e precisará dessas rotas.
- **BX-4.** Paginação ausente em `/posicoes` — §9.1 exige listagem paginada (50/página,
  < 500 ms). Mesma pendência de contrato herdada de `parte_4_critica.md` BX-6: decidir agora
  se o `index` já nasce paginado evita quebra de contrato depois.
- **BX-5.** AuthZ (GESTOR remove posições, §9.2) está adiada para a Fase 10; registrar na spec
  que `DELETE`/`encerrar` ainda não restringem por perfil (coerente com D-402).
- **BX-6.** "Estorno de movimentação" permanece fora de escopo (§1/§8), alinhado à RN-025 e à
  ressalva de `pontos_de_atencao.md` §2 — manter a pendência registrada, sem ação nesta fase.

## 5. Pontos fortes (para preservar)

- **D-501** (transação + `lockForUpdate` antes de recomputar) é a trava certa para a RN-022
  e segue o `CLAUDE.md`/§12.7.
- **D-502** (deleção só sem MtM, senão 409 → encerrar) é coerente com a imutabilidade
  (RN-025) e com a auditoria (§2.3).
- **D-503** (catch de `QueryException`/`23505` → `ErroConflito`) reaproveita fielmente o
  padrão já validado em `ServicoProdutos`/`ServicoPrecos` e o envelope §5.1 de
  `bootstrap/app.php`.
- **Consolidar `quantidade`** em `posicao` (parte de D-504/RN-024) é correto e mandatório.
- Adesão ao *fat model*: o cálculo continua no Model (`Futuro::replay`/`precoMedio`), o
  Service só orquestra — sem `if`/`switch` por tipo, preservando o polimorfismo.

## 6. Ações recomendadas (prioridade)

| # | Severidade | Ação |
|---|---|---|
| B-1 | Bloqueador | Remover o cache de `preco_medio` (D-504/§4.1): coluna não existe em `posicao_futuro` (§3.2.4); manter `preco_medio` **derivado** via `replay()`. |
| B-2 | Bloqueador | Preencher `criado_por` (`NOT NULL`) em todo `create()` de `posicao`/`posicao_movimentacao`, a partir do auth (fallback documentado até a Fase 10); não aceitar do cliente. |
| A-1 | Alto | Especificar `criarFuturo` (transação mãe+filha+ABERTURA, RN-020) e `criarNdf/Opcao/Otc`, com a tabela RN-001..006 × camada (Form Request × Service; RN-006 no Service). |
| A-2 | Alto | Abolir a denormalização de `preco_medio`: contradiz o motor §4.4 (que deriva via replay) e cria dupla fonte de verdade. Manter só `quantidade`/`status`. |
| A-3 | Alto | Recarregar `movimentacoes` após o `INSERT` antes do `replay()`/derivação; validar RN-022 antes do `create`, sob lock. |
| M-1 | Médio | Incluir `EstadoMovimentacao` em §5/§6 e fazer `movimentarFuturo` retornar o estado da §5.2.3, não `Movimentacao`. |
| M-2 | Médio | Ramificar `SalvarPosicaoRequest` por instrumento; fixar onde cada RN-001..006 é validada. |
| M-3 | Médio | Concretizar `arredonda(...,4)` + epsilon na decisão de encerrar/rejeitar (RN-022); fonte única de quantidade. |
| M-4 | Médio | Reescrever a justificativa de D-503 (unicidade da ABERTURA, não "race" na criação). |
| M-5 | Médio | Especificar a ação `encerrar` (estados permitidos; distinção do encerramento automático do FUTURO). |
| M-6 | Médio | Reescrever D-506: Service obtém `float` pelos métodos do Model, sem chamar o trait `ConverteDecimais` estaticamente (evita reincidir no B-1 da Parte 4). |
| BX-1..6 | Baixo | Sincronizar §2/§5/§6; enumerar endpoints/rotas; decidir paginação de contrato; registrar authZ adiada e estorno fora de escopo. |

> **Nota final.** Nada aqui exige mudar §3 (modelo de dados), §5 (contratos) ou §7 (regras)
> do `requisitos.md` — são endurecimentos de implementação e de redação da spec. O que mais
> afeta o caminho feliz do operador real são **B-1** e **B-2** (sem eles, nenhum cadastro ou
> movimentação roda — falham com 500) e **A-1** (o grosso da fase — o cadastro dos 4
> instrumentos — está sem contrato). **A-2/A-3** evitam o pior tipo de defeito num sistema
> financeiro: P&L silenciosamente errado por estado consolidado inconsistente.
