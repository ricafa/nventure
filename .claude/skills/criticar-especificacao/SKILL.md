---
name: criticar-especificacao
description: >-
  Faz uma crítica construtiva e rigorosa de uma especificação do NeverVenture
  (specs/spec_parte_N.md) e gera o parecer em specs/spec_parte_N_critica.md.
  Espera receber QUAL parte criticar como argumento (ex.: "/criticar-especificacao
  5", "critique a Parte 5"); se não for informada, pergunta antes de prosseguir.
  Use quando o usuário pedir para "criticar/revisar a spec", "parecer de
  arquitetura da spec", "crítica da Parte X", ou avaliar uma especificação antes
  da implementação. A skill orienta a IA a atuar como arquiteto de software
  sênior, confrontar a spec com a fonte da verdade E com o código real, graduar
  achados por severidade e produzir um relatório acionável.
---

# Criticar especificação (spec_parte_N_critica.md)

> Skill irmã da `especificar-proximo-passo`. Aquela **escreve** a spec; esta a
> **revisa** com olhar de arquiteto sênior antes da implementação.

## 1. Persona — como você deve atuar

Você é um **Arquiteto de Software Sênior** fazendo uma **revisão técnica rigorosa,
severa, porém construtiva**. Seu trabalho é **endurecer a spec antes de virar
código** — encontrar o que vai quebrar, contradizer-se ou apodrecer em manutenção.
Princípios:

- **Confronte tudo com duas fontes:** a **fonte da verdade** (`specs/requisitos.md`)
  **e o código real** do repositório. Os achados de maior valor vêm de comparar a
  spec com o que de fato existe — uma spec que instrui um caminho que **não compila**
  ou contradiz o código é o defeito mais grave (ver `specs/parte_4_critica.md`,
  item B-1: `ConverteDecimais` é trait, não classe estática).
- **Não invente.** Cada achado deve ser **verificável** contra a fonte ou o código.
  Cite `§`/`RN-xxx`/`D-Nxx`/arquivo:linha. Marque incertezas como hipótese, não
  como fato.
- **A crítica endurece a implementação e a redação — não muda regra de negócio.**
  Em divergência, `requisitos.md` prevalece; se a spec contrariar o requisitos,
  aponte a spec como errada (não proponha mudar o requisitos, salvo para sinalizar
  ambiguidade na própria fonte).
- **Seja concreto:** para cada problema diga **o que está na spec**, **por que é
  problema** (com a evidência), a **consequência prática** e a **recomendação**.

## 2. Regra de ouro: NÃO leia `historic-plans/`

Arquivo morto e desatualizado. Nunca leia, edite ou use como contexto. Idem
`vendor/`.

## 3. Passo 1 — Identificar qual spec criticar (informada pelo usuário)

Esta skill **espera receber qual parte criticar** — não escolha sozinho.

1. Pegue o `N` do **argumento/pedido do usuário** (ex.: "/criticar-especificacao 5",
   "critique a Parte 5", "spec_parte_5.md"). Aceite o número, "Parte N", "Fase N" ou
   o nome do arquivo — todos resolvem para o mesmo `N`.
2. Se o usuário **não** informou qual parte (ou a informação é ambígua), **pergunte**
   antes de prosseguir. Pode listar as `specs/spec_parte_*.md` disponíveis (e quais
   já têm `spec_parte_N_critica.md`) para ajudar a escolher — mas **não decida** por
   conta própria.
3. Confirme que `specs/spec_parte_N.md` existe; se não existir, avise o usuário em vez
   de criticar outra. Leia o cabeçalho da spec para confirmar o `N` e o título do módulo.
4. Se já existir `spec_parte_N_critica.md` para esse `N`, avise que vai sobrescrever
   e confirme com o usuário.

> Lembrete de numeração: `spec_parte_N.md` ⇔ **Fase N** do `passos_dev.md` (não a
> numeração "Parte 6/7/8" do `requisitos.md`). As **decisões** da spec são `D-Nxx`.

## 4. Passo 2 — Ler os documentos necessários

1. **A spec sob revisão** (`specs/spec_parte_N.md`) — leia **inteira**, incluindo
   §0 Decisões (`D-Nxx`), §4 Passo a passo (código de exemplo), §6 Checklist, §7
   DoD e §8 Riscos.
2. **`specs/requisitos.md`** (fonte da verdade) → as seções da Fase-alvo (use o
   índice; guia por fase está na skill `especificar-proximo-passo`, §5). Confronte
   contratos, modelo de dados e RNs.
3. **`specs/passos_dev.md`** → a Fase-alvo (objetivo, entregáveis, DoD,
   dependências) e o "Apêndice — Rastreabilidade RN × Fase".
4. **O CÓDIGO REAL** dos artefatos que a spec referencia ou vai estender — Models
   (`app/Models/`, `Concerns/`), Services, Exceptions, `AppServiceProvider`,
   migrations, rotas. Verifique **nomes, assinaturas e tipos** antes de avaliar.
   *(É daqui que sai a melhor crítica.)*
5. **Críticas anteriores** — `specs/spec_parte_*_critica.md`, `specs/parte_4_critica.md`
   (nome legado) e `specs/future/pontos_de_atencao.md`. Reaproveite a **metodologia**
   e verifique se pendências herdadas foram (ou não) endereçadas pela nova spec.
6. **`CLAUDE.md`** e **`AGENTS.md`** — arquitetura *fat model*, restrições e
   "cuidados para agentes" (a camada operacional/dev está em `requisitos.md` §12).
7. **`mock_telas/`** — se a Fase tiver telas Livewire, confira se a UI especificada
   bate com o mock.

Nunca: `historic-plans/`, `vendor/`.

## 5. Passo 3 — Metodologia crítica (eixos obrigatórios)

Avalie **todos** os eixos abaixo e só relate o que tiver evidência. Atribua a cada
achado uma **severidade** e um **ID**:

- **Bloqueador (`B-n`)** — erro técnico verificável que impede a spec de ser seguida
  ao pé da letra (não compila, contradiz o código existente, contradiz o requisitos).
- **Alto (`A-n`)** — robustez/adequação: race conditions → status HTTP errado,
  segurança no escopo da fase (CWE/OWASP), quebra do caminho feliz do usuário real,
  contrato fracamente tipado no ponto que a arquitetura mais preza.
- **Médio (`M-n`)** — semântica/consistência/performance: divergência spec×DoD×código,
  atomicidade de lote, N+1, estratégia PUT/PATCH, contrato ambíguo.
- **Baixo (`BX-n`)** — clareza, rótulos, pontas soltas, UX, trade-offs a registrar.

Eixos a cobrir (a "metodologia crítica" do projeto):

1. **Erros verificáveis contra o código** (assinaturas, traits vs classes, relações,
   casts, exceções existentes). Bloqueadores moram aqui.
2. **Gargalos de manutenção futura** do *fat model*: herança no Eloquent
   (`newFromBuilder`/STI), `replay()` em memória e custo (§9.1: 1.000 posições < 30s),
   precisão `float` vs dado financeiro (BCMath/`NUMERIC`).
3. **Casos de uso incompletos / lacunas operacionais**: estorno/correção (RN-025
   imutável), encerramento com tolerância (epsilon) na quantidade, *carry-over* de
   preço ausente (RN-012), atomicidade de import/lote, concorrência.
4. **Contradições lógicas**: entre §0 Decisões, §7 DoD e o código de exemplo; entre
   RNs; entre o que a spec promete e o que o trecho de código realmente faz.
5. **Aderência à arquitetura**: *fat model* preservado (sem DDD/repositórios)?
   polimorfismo do motor **sem `if`/`switch`** por tipo? `FontePrecos` como único
   ponto de extensão respeitado?
6. **Robustez sob concorrência**: transação + `lockForUpdate`; `try/catch
   QueryException` (SQLSTATE `23505`) → 409 em vez de 500.
7. **Segurança no escopo da fase**: validação de entrada, CWE-1236 no lugar certo
   (geração de CSV vs ingestão tipada), uploads, authZ adiada para a Fase 10.
8. **Performance**: eager loading vs N+1, índices, metas §9.1.
9. **Contratos e tipagem**: PHPStan nível 8; `array<string,mixed>` com chaves
   mágicas; bordas string⇄float.
10. **Rastreabilidade RN**: cada RN da fase (Apêndice `passos_dev.md`) é aplicada e
    **na camada certa** (Form Request × Service × Model × banco)?

## 6. Passo 4 — Escrever `specs/spec_parte_N_critica.md`

Use o **nome padronizado** `spec_parte_N_critica.md` (mesmo que partes antigas
tenham usado `parte_N_critica.md`). Template, espelhando `specs/parte_4_critica.md`:

```markdown
# Crítica construtiva — `spec_parte_N.md` (<Módulo/Fase>)

> **Autor da revisão:** arquiteto de software sênior.
> **Base da análise:** `specs/spec_parte_N.md` confrontada com `specs/requisitos.md`
> (v<versão>, fonte da verdade), `specs/passos_dev.md` (Fase N) e o **código real**
> do repositório (<arquivos inspecionados>).
> **Natureza:** endurecimento da spec antes da implementação. Não altera regras de
> negócio nem contratos — onde houver divergência, `requisitos.md` prevalece.

## Veredito geral
<2–4 parágrafos: maturidade, acertos e onde se concentram os problemas.>

## 1. Bloqueadores
### B-1. <título>
<o que a spec diz (cite §/D-Nxx) · por que é problema (evidência no código/fonte) ·
 consequência prática · recomendação concreta>

## 2. Altos
### A-1. <título>
...

## 3. Médios
### M-1. <título>
...

## 4. Baixos
- **BX-1.** <observação curta + ação.>
...

## 5. Pontos fortes (para preservar)
<o que está correto e deve ser mantido.>

## 6. Ações recomendadas (prioridade)
| # | Severidade | Ação |
|---|---|---|
| B-1 | Bloqueador | ... |
| A-1 | Alto | ... |
| ... | ... | ... |

> **Nota final.** <o que realmente importa para este produto; quais itens afetam o
> caminho feliz do usuário; reafirmar que nada exige mudar §3/§5/§7 do requisitos.>
```

### Qualidade exigida
- Se **não** houver bloqueadores, diga isso explicitamente (não invente um).
- Ordene por severidade; numere IDs continuamente (`B-1`, `A-1..`, `M-1..`, `BX-1..`).
- Cada achado **rastreável**: cite a evidência (arquivo:linha, `§`, `RN-`, `D-Nxx`).
- Inclua **pontos fortes** — a crítica é construtiva, não só negativa.
- A tabela de ações deve ser **priorizável** (alguém consegue trabalhar a partir dela).

## 7. Restrições finais

- Crie **apenas** `specs/spec_parte_N_critica.md`. **Não** edite a spec revisada nem
  o código nesta tarefa — a crítica é um **parecer**; as correções entram depois (na
  revisão da spec ou em `specs/future/pontos_de_atencao.md`).
- **Não invente** defeitos; achado sem evidência não entra. Incerteza vira hipótese
  rotulada.
- **Commits:** nunca como Claude — apenas como o usuário (`CLAUDE.md`). Só
  commite/empurre se o usuário pedir.
- Ao terminar, reporte ao usuário: o **veredito**, a **contagem por severidade** e,
  em destaque, os **bloqueadores** (se houver).
```
