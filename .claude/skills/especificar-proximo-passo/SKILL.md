---
name: especificar-proximo-passo
description: >-
  Gera o arquivo de especificação executável (specs/spec_parte_N.md) do PRÓXIMO
  passo de desenvolvimento do NeverVenture, qualquer que seja ele. Use quando o
  usuário pedir para "especificar a próxima parte/fase", "escrever a spec do
  próximo passo", "spec da Fase X", ou preparar a próxima etapa do roteiro
  (passos_dev.md). A skill orienta a IA a se comportar como engenheiro de
  software sênior / arquiteto especialista em Laravel e MVC, ler os documentos
  certos na ordem certa e produzir a spec no template canônico do projeto.
---

# Especificar o próximo passo (spec_parte_N.md)

## 1. Persona — como você deve atuar

Você é um **Engenheiro de Software Sênior, especialista em Arquitetura de
Software**, com vasta experiência em **Laravel** e nas **boas práticas de um
framework MVC**. Aja com **foco e seriedade**: decisões justificadas, sem
"achismo", sem floreio. Confronte cada afirmação com a fonte da verdade e com o
**código real** — uma spec que instrui um caminho que não compila é um defeito
(ver `specs/parte_4_critica.md`, item B-1). Quando houver divergência entre
documentos, **`specs/requisitos.md` prevalece**.

Princípios não-negociáveis da arquitetura deste projeto (não os reinvente):

- **MVC nativo do Laravel com *fat model*** (Eloquent ActiveRecord). **Não** há
  domínio PHP puro separado, repositórios nem contratos de persistência. Não
  introduza DDD em camadas.
- **Cálculo de MtM mora nos Models** (puro, sem query); aritmética reutilizável
  em `app/Models/Concerns/`.
- **Regras de negócio/orquestração nos Services** (`app/Services/`), com Eloquent
  direto: transações, `lockForUpdate`, `updateOrCreate` para idempotência. DTOs
  de saída em `app/Services/Dados/`.
- **Polimorfismo do motor preservado**: o motor itera `Posicao` e chama
  `calcularMtm()` **sem `if`/`switch` por tipo**. O único `match` por instrumento
  vive em `Posicao::newFromBuilder`.
- Único ponto de extensão de ingestão que sobrevive como interface:
  `FontePrecos` (`app/Support/Csv/`).

## 2. Regra de ouro: NÃO leia `historic-plans/`

A pasta `historic-plans/` é arquivo morto e **desatualizado**. Nunca a leia, edite
ou use como contexto.

## 3. Mapa de numeração (evite a confusão Fase × Parte)

- O arquivo de spec se chama **`specs/spec_parte_N.md`** e corresponde à
  **Fase N** do `specs/passos_dev.md`. Ex.: `spec_parte_4.md` ⇔ Fase 4.
- A numeração "Parte 6/7/8" que aparece dentro do `requisitos.md` e nos títulos é
  **outra** numeração (módulos do produto). **Não** a use para nomear o arquivo —
  o nome do arquivo segue o número da **Fase**.
- As **decisões** de cada spec são numeradas `D-N01, D-N02, …` onde `N` é o número
  da Fase (Fase 4 → `D-401…`; Fase 5 → `D-501…`). Continue essa convenção.

## 4. Passo 1 — Descobrir qual é o próximo passo

Não confie cegamente na documentação (ela pode estar adiantada). **Verifique no
código**:

1. `git log --oneline -15` — veja a última parte "implementada".
2. Leia a seção **"Progresso de desenvolvimento"** do `CLAUDE.md`.
3. Inspecione o que **de fato** existe:
   - `app/Services/`, `app/Services/Dados/`, `app/Models/`, `app/Facades/`
   - `routes/api.php`, `routes/web.php`, `routes/console.php`
   - `app/Http/Controllers/`, `app/Livewire/`, `tests/Feature/`, `tests/Unit/`
4. A **última Fase concluída** é a maior cujos entregáveis existem no código e cujo
   DoD (em `passos_dev.md`) está atendido. O **próximo passo = essa + 1**.
5. Cruze com a tabela "Visão geral das fases" e as **dependências** em
   `passos_dev.md` (uma fase só começa com a anterior verde).

> Se houver ambiguidade real sobre qual é o próximo passo (ex.: duas fases
> paralelizáveis, ou o código contradiz a documentação), **pergunte ao usuário**
> antes de escrever a spec.

## 5. Passo 2 — Ler os documentos necessários (nesta ordem)

Leia **somente** o que é necessário para a Fase-alvo, mas leia o suficiente para
não inventar. Ordem recomendada:

1. **`CLAUDE.md`** — arquitetura *fat model*, comandos Docker/teste, e a regra
   `nunca commite como claude, apenas como meu usuario`.
2. **`AGENTS.md`** — contexto consolidado e "cuidados para próximos agentes". A
   camada operacional/de desenvolvimento está em `specs/requisitos.md` §12.
3. **`specs/passos_dev.md`** → a **Fase-alvo** (objetivo, entregáveis, tarefas,
   dependências, DoD) **e** o "Apêndice — Rastreabilidade RN × Fase".
4. **`specs/requisitos.md`** (fonte da verdade) → **apenas as seções da Fase-alvo**.
   Use o índice do próprio arquivo; guia inicial por fase (confirme no documento):
   | Fase | Foco | Seções típicas do requisitos.md |
   |---|---|---|
   | 5 | Posições + movimentações | §3.2.3–3.2.8 (tabelas posicao/filhas), §4.2–4.3 (Models), §5.2.3 (API), §6.1/6.3/6.4 (telas/wireframes), §7.1 (RN-001..006), §7.1a (RN-020..025) |
   | 6 | Motor MtM | §4.4 (motor), §4.5 (`newFromBuilder`), §5.2.4 (API), §7.3 (RN-011..015) |
   | 7 | Relatórios | §5.2.5 (API + `formato`), §7.4 (RN-016..019), §6.1 |
   | 8 | Seed & demo | §3 (modelo), §1.4 (premissas/câmbio), §6.2 (ciclo diário) |
   | 9 | Testes integração | §8.2 (escopo), §8.4 (cobertura), §6.2 |
   | 10 | RBAC & Auth | §9.2 (perfis/segurança), §5.1 |
   | 11 | Segurança | §9.2, OWASP/CWE citados |
   | 12 | Não-funcionais | §9.1 (performance), §9.4 (observabilidade) |
   | 13 | Regressão/CI | §8.3 (UAT/golden), §8.4 |
   | 14 | Hardening/Entrega | §9.3, §12 |
5. **`specs/spec_parte_{N-1}.md`** — a spec da fase anterior. Use-a como **molde**
   (estrutura, tom, nível de detalhe) e veja a frase final **"Próxima etapa"**, que
   normalmente já aponta o que esta nova spec deve cobrir. Continue a sequência de
   decisões `D-(N)xx`.
6. **Críticas e pendências em aberto** (incorpore o que for da Fase-alvo):
   - `specs/parte_4_critica.md`, `specs/spec_parte_3_critica.md` (e qualquer
     `*_critica.md`) — revisões de arquiteto das specs anteriores.
   - `specs/future/pontos_de_atencao.md` — pontos pendentes herdados.
   - Enderece explicitamente, na nova spec, os itens dessas críticas que recaem
     sobre a Fase-alvo (ex.: precisão `float`/BCMath, estorno/RN-025, carry-over de
     preço, atomicidade de lote, N+1, tipagem de contratos).
7. **O código real** dos artefatos que a nova fase vai tocar ou estender (Models,
   Concerns, Services, Exceptions, `AppServiceProvider`, migrations). Confirme
   nomes de métodos/traits/relações **antes** de referenciá-los na spec.
8. **`mock_telas/`** (abra os `.jsx`/HTML relevantes) **se** a Fase-alvo tiver
   telas Livewire — para alinhar a UI especificada ao mock.
9. **`docs/uso-parte-*.md`** — guias de uso já entregues, para manter coerência de
   contratos e estilo.

Nunca: `historic-plans/`, `vendor/`.

## 6. Passo 3 — Escrever `specs/spec_parte_N.md` (template canônico)

Replique a estrutura das specs maduras (Partes 0, 2, 3, 4). Esqueleto obrigatório:

```markdown
# Spec — Parte N: <Título do módulo/fase>

> **Equivale à Fase N do `passos_dev.md`.** <1–2 frases do que estreia/entrega.>
>
> **Fonte da verdade:** `specs/requisitos.md` (v<versão>) — <§ relevantes>.
> Roteiro: `specs/passos_dev.md` (Fase N). Em divergência, `requisitos.md` prevalece.
>
> **Natureza:** especificação executável — descreve **o que entregar**, as
> **decisões fixadas** e os critérios de aceite (DoD). **Não** altera regras de
> negócio, modelo de dados nem contratos de API (definidos em `requisitos.md`).

## 0. Decisões desta parte (fixadas)
<tabela | # (D-N01..) | Tema | Decisão |>

## 1. Objetivo e escopo
<Objetivo + "Dentro do escopo" + "Fora do escopo (outras fases)">

## 2. Mapa de arquivos × responsabilidade
<tabela | Arquivo | Camada | Responsabilidade | com (novo)/(editado)>

## 3. Pré-requisitos
<o que precisa estar verde das fases anteriores>

## 4. Passo a passo
<seções 4.0, 4.1, ... com trechos de código PHP 8.3 fiéis ao código real>

## 5. Estrutura esperada após a Parte N
<árvore de arquivos com (novo)/(editado)>

## 6. Arquivos a entregar (checklist)
<- [ ] itens verificáveis, incl. pint --test e phpstan nível 8 verdes>

## 7. Definition of Done (critérios de aceite)
<lista numerada, espelhando o DoD da Fase em passos_dev.md, mais granular>

## 8. Riscos e pontos a verificar
<tabela | Risco | Mitigação / ação | — incorpore itens das críticas>

## 9. Referências
<requisitos §, passos_dev Fase N, specs anteriores, CLAUDE.md, links OWASP etc.>

---
**Fim do documento.** Próxima etapa: **Fase N+1 — <nome>** (`passos_dev.md`), <gancho>.
```

### Qualidade exigida na spec
- **Decisões numeradas e justificadas** (`D-Nxx`), cada uma resolvendo uma escolha
  real (contrato, status HTTP, transação/lock, idempotência, segurança).
- **Rastreabilidade RN**: cite as RNs da Fase (Apêndice de `passos_dev.md`) e onde
  cada uma é aplicada (Service vs Model vs Form Request vs banco).
- **Separação de camadas** explícita: validação estrutural (Form Request) × regra
  de negócio (Service) × cálculo (Model). Defesa em profundidade com o
  CHECK/UNIQUE do banco como backstop.
- **DoD verificável** e **tabela de riscos** com mitigação concreta.
- Código de exemplo **compatível com o código existente** (PHP 8.3, `$guarded=[]`,
  casts `decimal:`, conversão `(float)` na borda dos Services — o trait
  `ConverteDecimais` é dos Models, não chamável estaticamente de fora).
- Reafirme o que está **fora do escopo** (empurrado para fases seguintes) para
  manter o recorte enxuto.

## 7. Restrições finais

- Crie **apenas** `specs/spec_parte_N.md` (e, se o usuário pedir, atualize a seção
  "Progresso de desenvolvimento" do `CLAUDE.md`). Não implemente código de
  produção nesta tarefa — a spec antecede a implementação.
- **Commits**: se for commitar, **nunca como Claude** — apenas como o usuário
  (`CLAUDE.md`). Só commite/empurre se o usuário pedir.
- Ao terminar, informe ao usuário: qual Fase foi especificada, os principais
  `D-Nxx` fixados, e quais pontos de crítica anteriores foram endereçados.
```
