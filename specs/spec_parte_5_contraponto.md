# Contraponto à crítica — `spec_parte_5_critica.md` (Módulo Posições — Fase 5)

> **Autor da arbitragem:** tech lead / product owner (decisão de escopo e prioridade).
> **Base:** `specs/spec_parte_5_critica.md` ponderada contra `specs/spec_parte_5.md`,
> `specs/requisitos.md` (fonte da verdade — §3.2.3–3.2.8, §4.2–4.5, §5.2.3, §7.1/§7.1a),
> `specs/passos_dev.md` (Fase 5, escopo/DoD) e o **código real reconferido**:
> `database/migrations/{create_posicao,create_posicao_futuro,create_posicao_movimentacao}_table.php`,
> `app/Models/Futuro.php`, `app/Models/Concerns/ReproduzMovimentacoes.php`.
> **Natureza:** decide **o que vale a pena corrigir** na spec antes de reescrevê-la.
> Não altera regras de negócio nem a crítica; arbitra entre elas. Em divergência,
> `requisitos.md` prevalece.

## Veredito geral

A crítica é **justa, bem ancorada e, em sua maior parte, procede**. Reconferi os dois
bloqueadores diretamente no esquema e ambos se sustentam: `posicao_futuro` tem **apenas**
`posicao_id`, `preco_entrada` e `codigo_contrato` — **não existe `preco_medio`**
(migration `...100400`, linhas 12-14), e `criado_por` é `VARCHAR(60) NOT NULL` sem default
em `posicao` (`...100300`, linha 25) **e** em `posicao_movimentacao` (`...100500`, linha 20).
São defeitos de fato: a spec, seguida ao pé da letra, gera 500 em **toda** movimentação e
**todo** cadastro. Aqui a margem de arbítrio é nula — corrigir é pré-requisito.

Onde o contraponto agrega não é derrubando achados (quase não há o que derrubar), e sim
**calibrando duas coisas**: (1) a justificativa de **A-2/D-504** — abolir o cache de
`preco_medio` está certo, mas a *intenção* de performance de D-504 **não é boba**: ela
responde a um risco real registrado em `pontos_de_atencao.md`; o correto é dizer "prematuro
e no lugar errado", não "errado em conceito"; e (2) **M-2**, onde a crítica acerta o
problema mas a solução ("um Form Request por instrumento") arrisca **over-engineering** para
um MVP — regras condicionais por `instrumento` num só Request resolvem com menos superfície.

**Placar: 13 ✅ · 2 ◐ · 0 ❌ · 1 ⏳ (já contemplado).** Conjunto mínimo que **bloqueia a
implementação** e precisa entrar na spec antes de codar: **B-1, B-2, A-1, A-2, A-3, M-1,
M-3, M-5**. O resto é polimento barato que pode (e deve) ir junto, sem inflar escopo.

---

## 1. Achado a achado

### B-1 — `preco_medio` em coluna inexistente · Veredito: ✅ PROCEDE
- **O que a crítica diz:** D-504/§4.1 mandam `$posicao->futuro->update(['preco_medio' => …])`,
  mas `posicao_futuro` não tem essa coluna (§3.2.4).
- **A favor de agir:** é erro de fato — `update` em coluna inexistente lança `QueryException`
  → 500 em toda movimentação. Criar a coluna violaria o §3 (a própria spec proíbe).
- **Contraponto:** nenhum relevante. Não é questão de gosto; não roda.
- **Conferência:** confirmado — `create_posicao_futuro_table.php` linhas 12-14 só têm
  `posicao_id`/`preco_entrada`/`codigo_contrato`; `Futuro::precoMedio()` deriva via `replay()`
  (linhas 43-48), nunca lê coluna persistida.
- **Decisão:** remover o cache de `preco_medio` de D-504 e §4.1; manter `preco_medio`
  **derivado**. (Ver A-2 para o tratamento do que sobra de D-504.)

### B-2 — `criado_por` (`NOT NULL`) não preenchido · Veredito: ✅ PROCEDE
- **O que a crítica diz:** todo `create()` de `posicao`/`posicao_movimentacao` na spec omite
  `criado_por`, que é `NOT NULL` sem default → `23502` → 500.
- **A favor de agir:** confirmado no esquema; é a primeira fase a tocar tabelas com
  `criado_por`, então nada existente cobre isso. Sem definir a origem do valor, nenhum
  cadastro roda.
- **Contraponto:** o único cuidado é **não** transformar isso num pedido de antecipar a Fase
  10 (auth real). A crítica já prevê isso (fallback `?? 'sistema'`); aceitar **com** essa
  ressalva é o certo.
- **Conferência:** confirmado — `create_posicao_table.php` linha 25 e
  `create_posicao_movimentacao_table.php` linha 20 (`string(...,60)`, sem `->default()`/
  `->nullable()`).
- **Decisão:** Service injeta `criado_por` a partir do contexto de auth, com **fallback
  documentado** até a Fase 10; Form Requests **não** aceitam o campo do cliente
  (anti-spoofing). Adicionar item ao checklist §6 e um teste que falharia hoje.

### A-1 — Cadastro dos 4 instrumentos não especificado · Veredito: ✅ PROCEDE
- **O que a crítica diz:** o §4 só mostra `movimentarFuturo`/`criarAbertura`/`remover`; falta
  `criarFuturo` (transação mãe+filha+ABERTURA, RN-020), `criarNdf/Opcao/Otc` e a tabela
  RN-001..006 × camada.
- **A favor de agir:** é o **objetivo declarado** da fase (§1) e o que `passos_dev.md` (Fase 5)
  detalha; sem isso ~70% do trabalho fica sem contrato. A separação Form Request × Service
  foi o ponto elogiado na Parte 4 e não foi transposta.
- **Contraponto:** uma spec é guia, não código completo — não precisa esmiuçar cada `criar*`
  linha a linha. Mas o **`criarFuturo` transacional** (a base de tudo, e o que o
  `uq_mov_abertura` pressupõe) e a **tabela RN × camada** são o mínimo inegociável.
- **Conferência:** confirmado contra o §4 da spec (só há `movimentarFuturo`/`criarAbertura`/
  `remover`).
- **Decisão:** especificar o `criarFuturo` transacional (mãe → `posicao_futuro` → `ABERTURA`
  com `data_movimentacao = data_entrada`) e a tabela RN-001..006 × camada, deixando
  **explícito que RN-006 (indexador do OTC existe) é checagem no Service** (lookup no banco),
  não Form Request. Os demais `criar*` podem ser enxutos.

### A-2 — D-504 cria dupla fonte de verdade · Veredito: ✅ PROCEDE (com ressalva de mérito)
- **O que a crítica diz:** persistir `preco_medio` (derivado, RN-021) duplica a fonte da
  verdade e contradiz o motor §4.4, que recalcula via `replay()`; manter só `quantidade`.
- **A favor de agir:** confirmado — `Futuro::calcularMtm()` chama `precoMedio()`/
  `quantidadeAtual()` → `replay()`; o motor **não consome** `preco_medio` persistido. Cache de
  número financeiro derivado é exatamente o risco que `pontos_de_atencao.md` §3 sinaliza.
- **Contraponto (mérito de D-504):** a *intenção* de D-504 — evitar reprocessar `replay()` em
  massa na leitura do motor (meta §9.1: 1.000 posições < 30 s) — **é legítima**, não um
  capricho. A crítica está certa em remover **agora**, mas o rótulo justo é "denormalização
  **prematura e no lugar errado**", não "ideia errada". Se a Fase 6/12 medir gargalo real, a
  denormalização volta — com migration própria, coluna de fato e **teste de consistência
  cache⇄replay**.
- **Conferência:** confirmado (Futuro.php 43-65; ReproduzMovimentacoes 18-43).
- **Decisão:** abolir o cache de `preco_medio`; **manter** a consolidação de
  `quantidade`/`status` (coluna real + RN-024/RN-022 — correto e obrigatório). Registrar a
  denormalização de performance como hipótese para a Fase 6/12 em `pontos_de_atencao.md`, para
  não se perder a intenção válida de D-504.

### A-3 — Replay sobre relação estagnada após o INSERT · Veredito: ✅ PROCEDE
- **O que a crítica diz:** após `$posicao->movimentacoes()->create(...)`, a relação já
  acessada fica estagnada; um `replay()` sobre ela calcula estado **sem** a nova movimentação.
- **A favor de agir:** o `replay()` opera sobre `$this->movimentacoes` **já carregada**
  (Futuro.php linha 32 / docbloc linha 30). É o pior tipo de bug financeiro: passa no teste
  simples, falha no encadeamento real. A correção é barata (recarregar) e a validação de
  RN-022 **antes** do `create`, sob lock, é a ordem correta.
- **Contraponto:** parcial e honesto — se `movimentacoes` **nunca** foi tocada antes do
  `create`, o primeiro acesso faz lazy-load fresco (já com a nova linha). Ou seja, o bug é
  **condicional** à ordem de acesso. Isso **reduz a probabilidade**, mas não justifica
  depender de sorte de ordenação num valor de P&L: recarregar explicitamente é defesa correta
  e trivial.
- **Conferência:** confirmado no Model.
- **Decisão:** após o `create`, recarregar (`$posicao->load('movimentacoes')` ou reconsulta com
  eager loading dentro da transação) antes de derivar/consolidar; validar RN-022 (redução >
  saldo) **antes** do `create`, lendo a quantidade sob lock. Cobrir com teste de "duas
  movimentações na mesma sequência".

### M-1 — `EstadoMovimentacao` some do checklist; retorno errado · Veredito: ✅ PROCEDE
- **O que a crítica diz:** §5.2.3 contrata o **estado recalculado** como resposta de
  `POST /movimentacoes`, mas a assinatura é `movimentarFuturo(): Movimentacao` e
  `EstadoMovimentacao` não aparece em §5/§6.
- **A favor de agir:** confirmado — spec linha 71 retorna `Movimentacao`; o controller teria
  de recompor o estado por fora, contrariando o contrato. Incoerência §0/§2 (cita o DTO) ×
  §5/§6 (omitem).
- **Contraponto:** nenhum relevante; é coerência de contrato, custo baixo.
- **Decisão:** incluir `EstadoMovimentacao` em §5/§6 e fazer o Service retornar esse DTO
  (montado de `precoMedio()/quantidadeAtual()/plRealizado()` da posição **recarregada** —
  ver A-3).

### M-2 — `SalvarPosicaoRequest` único para 4 payloads · Veredito: ◐ PROCEDE EM PARTE
- **O que a crítica diz:** um Request para FUTURO/NDF/OPCAO(`pernas[]`)/OTC é frágil; ramificar
  por rota/instrumento.
- **A favor de agir:** o problema é real — validar `pernas` só p/ OPCAO, `valor_nocional` só
  p/ NDF e `contraparte` condicional ao `mercado` (RN-003) num só Request é frágil; a Parte 4
  tropeçou em algo análogo (M-5).
- **Contraponto:** a **solução** sugerida pode virar over-engineering. Quatro classes de Form
  Request para um MVP de 4 instrumentos é superfície a mais; Laravel resolve bem com
  **regras condicionais por `instrumento`** (`Rule::requiredIf`, `sometimes`) num único
  Request, ou no máximo separar só o OPCAO (que tem `pernas[]`). O que **não** pode faltar é
  fixar **onde cada RN-001..006 é validada**.
- **Conferência:** confirmado no §2 da spec (Request único previsto).
- **Decisão (parcial):** aceitar o **requisito** de definir a validação por instrumento e a
  matriz RN × camada; **não** impor 4 classes — deixar a escolha (condicional vs. split)
  aberta ao implementador, recomendando condicional para reduzir superfície.

### M-3 — Igualdade de `float` no encerramento (RN-022) · Veredito: ✅ PROCEDE
- **O que a crítica diz:** comparar `qtd == 0` / `reducao > qtd` em `float` é frágil
  ("fechamento zumbi"); usar `arredonda(...,4)` + epsilon e fonte única de quantidade.
- **A favor de agir:** o §8 da spec **reconhece** o problema mas o §4 não o concretiza; o
  helper de arredondamento já existe; é um sistema financeiro — precisão importa.
- **Contraponto:** nenhum relevante; é endurecimento barato de algo que a própria spec já
  admite ser risco.
- **Conferência:** `reproduzir()` devolve `qtd` como `float` cru (ReproduzMovimentacoes 28-42).
- **Decisão:** especificar `arredonda($qtd, 4)` (epsilon 1e-4, casando com `NUMERIC(18,4)`)
  antes de comparar com zero/saldo, e fixar `posicao.quantidade` (consolidada sob lock) como
  **fonte única** da decisão de encerrar.

### M-4 — Justificativa de "race condition" em D-503 · Veredito: ◐ PROCEDE EM PARTE
- **O que a crítica diz:** num `criarFuturo` o `posicao_id` é recém-gerado na mesma transação;
  não há corrida concorrente inserindo `ABERTURA` — o valor real de `uq_mov_abertura` é impedir
  uma **segunda** ABERTURA, não uma corrida de criação.
- **A favor de agir:** o raciocínio procede; o texto de D-503 descreve uma corrida que não se
  materializa nesse fluxo.
- **Contraponto:** é correção **de redação**, não de comportamento — o `try/catch` 23505 deve
  **ficar** (defesa em profundidade barata e correta). Valor real baixo, custo quase zero.
- **Conferência:** `uq_mov_abertura` é índice único parcial em `posicao_movimentacao(posicao_id)
  WHERE tipo='ABERTURA'` (migration linha 31) — confirma a leitura da crítica.
- **Decisão (parcial):** manter o catch; reescrever a justificativa de D-503 para "garante
  unicidade da ABERTURA / impede abertura duplicada", sem invocar corrida inexistente.

### M-5 — Ação `encerrar` sem semântica · Veredito: ✅ PROCEDE
- **O que a crítica diz:** `encerrar` está na DoD (item 1) e em `passos_dev.md`, mas o §4 não
  o define; falta dizer estados de origem, efeito sobre MtM e distinção de VENCIDA.
- **A favor de agir:** está **no escopo** (DoD/`passos_dev`), logo não é gold-plating — é
  entregável sem contrato. Para FUTURO o encerramento vem por redução total (RN-022); então
  `encerrar` serve sobretudo a NDF/OPCAO/OTC (sem movimentações).
- **Contraponto:** nenhum — não dá para entregar a DoD sem definir isto.
- **Conferência:** confirmado (§7 item 1 cita encerramento; §4 não traz código).
- **Decisão:** especificar `encerrar` como transição de status **idempotente**
  (provavelmente só `ABERTA → ENCERRADA`), distinta do encerramento automático do FUTURO por
  redução total; registrar o comportamento se já houver MtM.

### M-6 — D-506 chama `ConverteDecimais` (trait) como classe · Veredito: ✅ PROCEDE
- **O que a crítica diz:** `ConverteDecimais` é trait dos Models; chamá-lo estaticamente de um
  Service não compila — reincidência do B-1 da Parte 4.
- **A favor de agir:** confirmado — é trait (`app/Models/Concerns/`); o `Futuro` o usa via
  `self::paraFloat()` (Futuro.php linha 36). Risco de repetir um erro já catalogado.
- **Contraponto:** no fluxo da Parte 5 o Service em geral **nem precisa** converter — recebe
  `float` pronto dos métodos do Model. Então é mais correção de **redação de D-506** do que de
  código; custo mínimo.
- **Conferência:** confirmado (trait + uso `self::` em Futuro/ReproduzMovimentacoes).
- **Decisão:** reescrever D-506 para deixar claro que o Service obtém `float` pelos **métodos
  dos Models** (que encapsulam o trait via `self::`), sem chamar o trait diretamente.

### BX-1 — Estrutura §5 omite `PosicaoResource`/`EstadoMovimentacao` · Veredito: ✅ PROCEDE
- Housekeeping de coerência §2/§5/§6; custo trivial. **Decisão:** sincronizar as três seções.

### BX-2 — `doesntExist()` (D-502) vs `exists()` (§4.2) · Veredito: ✅ PROCEDE (trivial)
- **Contraponto honesto:** é puramente cosmético — ambos compilam e são equivalentes.
  Aceitar só para padronizar a redação; sem impacto funcional. **Decisão:** padronizar o texto.

### BX-3 — Checklist não enumera os 9 endpoints da §5.2.3 · Veredito: ✅ PROCEDE
- Cheap; evita esquecer rota (`routes/api.php` hoje só tem produtos/preços). **Decisão:**
  enumerar os 9 endpoints (4 `POST /posicoes/{tipo}`, `encerrar`, `DELETE`, `GET`/`POST
  /movimentacoes`, `GET /{id}`).

### BX-4 — Paginação ausente em `/posicoes` · Veredito: ✅ PROCEDE (decidir agora)
- **A favor:** §9.1 exige listagem paginada (50/pág, < 500 ms); decidir o **contrato** agora
  evita quebra depois (mesma pendência herdada da Parte 4, BX-6).
- **Contraponto:** poderia ser ⏳, mas a decisão de contrato é barata e a omissão custa
  retrabalho. **Decisão:** `index` nasce paginado (envelope de paginação no contrato).

### BX-5 — AuthZ (GESTOR remove) adiada p/ Fase 10 · Veredito: ✅ PROCEDE (registrar)
- Apenas documentar que `DELETE`/`encerrar` ainda não restringem por perfil (coerente com
  D-402). **Decisão:** registrar a ressalva na spec.

### BX-6 — Estorno fora de escopo · Veredito: ⏳ JÁ CONTEMPLADO (sem ação)
- A spec **já** declara o estorno fora de escopo (§1 e §8, alinhado à RN-025 e a
  `pontos_de_atencao.md` §2). A crítica aqui só confirma. **Decisão:** nenhuma — manter o
  registro como está; é a sequência correta, não uma lacuna.

## 2. Observações fora da crítica

Nenhum achado relevante que a crítica tenha deixado passar. A crítica está alinhada à fonte
da verdade e ao código; não há ponto em que ela conflite com `requisitos.md`. Registro apenas
que **B-2 (`criado_por`)** é o de maior risco silencioso de ser subestimado na pressa, por ser
campo de auditoria "invisível" no caminho feliz — convém o teste que falha hoje, como a
crítica sugere, para travá-lo.

## 3. O que de fato corrigir na spec (recomendação priorizada)

| # | Achado | Veredito | Ação na spec |
|---|---|---|---|
| 1 | B-1 | ✅ | Remover cache de `preco_medio` (D-504/§4.1); mantê-lo derivado via `replay()`. |
| 2 | B-2 | ✅ | Injetar `criado_por` no Service (fallback até Fase 10); Form Request não aceita do cliente; teste que falha hoje. |
| 3 | A-1 | ✅ | Especificar `criarFuturo` transacional (mãe+filha+ABERTURA, RN-020) + tabela RN-001..006 × camada (RN-006 no Service). |
| 4 | A-2 | ✅ | Abolir denormalização de `preco_medio`; manter `quantidade`/`status`; registrar perf como hipótese p/ Fase 6/12. |
| 5 | A-3 | ✅ | Recarregar `movimentacoes` após `create`; validar RN-022 antes do `create`, sob lock. |
| 6 | M-1 | ✅ | Incluir `EstadoMovimentacao` em §5/§6; `movimentarFuturo` retorna o estado da §5.2.3. |
| 7 | M-3 | ✅ | `arredonda(...,4)` + epsilon na decisão de encerrar; fonte única de quantidade. |
| 8 | M-5 | ✅ | Especificar `encerrar` (transição idempotente ABERTA→ENCERRADA; distinção do auto-encerramento do FUTURO). |
| 9 | M-2 | ◐ | Fixar **onde** cada RN é validada por instrumento; **não** impor 4 Request classes (preferir regras condicionais). |
| 10 | M-4 | ◐ | Manter o catch 23505; só reescrever a justificativa de D-503 (unicidade da ABERTURA). |
| 11 | M-6 | ✅ | Reescrever D-506: Service obtém `float` pelos métodos do Model, sem chamar o trait. |
| 12 | BX-1..5 | ✅ | Housekeeping: sincronizar §2/§5/§6, enumerar 9 endpoints, paginar `index`, registrar authZ adiada. |
| 13 | BX-6 | ⏳ | Nenhuma — estorno já está fora de escopo na spec. |

## 4. Veredito de custo-benefício

**Vale reescrever a spec — e quase tudo que a crítica aponta deve entrar.** Esta não é uma
crítica para filtrar agressivamente; ela é, no geral, correta. O filtro real é pequeno e
honesto: **M-2** não deve virar 4 classes de Form Request (over-engineering para o MVP) e
**A-2/M-4** são, em parte, ajustes de *redação/intenção* mais do que de conceito — em
especial, registrar que a ideia de performance de D-504 não é errada, só prematura.

**Bloqueiam a implementação (corrigir antes de codar):** B-1, B-2, A-1, A-2, A-3, M-1, M-3,
M-5. Sem B-1/B-2 nada roda (500); sem A-1 o grosso da fase fica sem contrato; A-2/A-3 evitam
P&L silenciosamente errado. **Polimento que pode ir junto:** M-2 (calibrado), M-4, M-6, BX-1..5.
**Sem ação:** BX-6. Em uma frase: **corrigir os 8 itens bloqueantes acima é pré-requisito da
Fase 5; o restante é barato e deve acompanhar a mesma reescrita.**
