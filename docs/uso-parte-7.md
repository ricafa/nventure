# Guia de uso — Relatórios (Parte 7)

As **4 visões consolidadas da mesa de risco** entregues na Parte 7, lendo o histórico
`mtm_diario` que o motor (Parte 6) produz: **posição aberta**, **P&L diário/acumulado**,
**exposição líquida** e **histórico de MtM** — pela **API REST** (`?formato=json|csv|pdf`)
e pelas **telas web (Livewire)**.

> **Fonte da verdade dos contratos:** `specs/requisitos.md` §5.2.5 (API + `formato`),
> §7.4 (RN-016..019), §6.1 (telas), §5.1 (envelope de erro). Decisões de implementação:
> `specs/spec_parte_7.md` (decisões `D-7xx`).

---

## 1. Pré-requisitos

- Stack no ar: `docker compose up -d` (app em `http://localhost:8000`).
- **Banco PostgreSQL** (a consulta de snapshot usa `DISTINCT ON`, específico do Postgres).
- Histórico em `mtm_diario`: cadastre produto/preço (Parte 4) → posição (Parte 5) →
  dispare o motor (Parte 6, `/motor` ou `POST /api/v1/motor/processar`) para uma ou mais
  datas. Sem MtM, os relatórios respondem normalmente, porém vazios.
- Para a **API**, autenticação Sanctum (mesma ressalva da Parte 4: emissão de token é
  Fase 10; hoje o caminho humano é pelas telas, e a API é exercitada nos testes via
  `Sanctum::actingAs(...)`). **AuthZ por perfil** também é Fase 10 (D-709).

---

## 2. Conceitos que valem para os 3 relatórios de data

- **Parâmetro `data` (opcional).** Default = **hoje**. Cada relatório de data usa o
  **último MtM com `data_calculo <= data`** de cada posição `ABERTA` (snapshot, D-702):
  feriado/preço ausente não "some" com a posição — usa-se o último valor conhecido.
  Posição `ABERTA` ainda **sem nenhum** MtM até a data aparece com `tem_mtm=false` e zeros.
- **Status corrente (D-706).** Os relatórios filtram pelo **status atual** (`ABERTA`); não
  reconstroem o status "na data". Para uma `data` antiga, uma posição hoje `VENCIDA`/
  `ENCERRADA` não entra. Limitação assumida do MVP.
- **`formato` (opcional).** `json` (default, números **sem aspas**), `csv` (download
  endurecido contra injeção de fórmula — CWE-1236 — com BOM UTF-8) e `pdf` → **`501`**
  (`FORMATO_INDISPONIVEL`): previsto no contrato, ainda não implementado.

---

## 3. API REST

Prefixo **`/api/v1`**, tudo sob `auth:sanctum`.

### 3.1 Posição aberta — RN-016
```
GET /relatorios/posicao-aberta?data=YYYY-MM-DD&formato=json
```
Lista as posições `ABERTA` com o último MtM `<= data`. O **preço médio** vem só para o
**FUTURO** (de `Futuro::precoMedio()`, replay das movimentações). Resposta:
```json
{
  "data": "2026-06-19",
  "total_mtm": 580.0,
  "total_variacao": -470.0,
  "posicoes": [
    {
      "posicao_id": 12, "produto_id": 3, "produto": "Milho B3",
      "instrumento": "FUTURO", "lado": "COMPRADO", "quantidade": 10.0,
      "preco_medio": 100.0, "preco_mercado": 108.0, "data_vencimento": "2026-12-31",
      "mtm": 80.0, "variacao_dia": 30.0, "tem_mtm": true
    }
  ]
}
```
`preco_medio` é `null` fora do FUTURO; `tem_mtm=false` (com `mtm=0`, `preco_mercado=null`)
sinaliza posição sem MtM até a data.

### 3.2 P&L diário e acumulado — RN-017 / RN-018
```
GET /relatorios/pl-diario?data=YYYY-MM-DD
```
- `pl_diario` = `Σ variacao_dia` das linhas com `data_calculo = data` (**data exata** — o
  resultado *daquele* pregão).
- `pl_acumulado` = `Σ pl_acumulado` do snapshot das `ABERTA` (inclui o realizado das
  reduções, RN-023).
- `serie` = série para o gráfico, por `data_calculo` até a data. A coluna `pl_acumulado` da
  série usa `SUM(pl_acumulado)` (coerente com RN-018). **Ressalva (D-704):** a série agrega
  todas as posições do dia, o KPI é o snapshot só das `ABERTA` — o número **canônico** é
  sempre o escalar da data; a série é ilustrativa.
```json
{
  "data": "2026-06-19", "pl_diario": -470.0, "pl_acumulado": 580.0,
  "serie": [
    { "data": "2026-06-18", "pl_dia": 1050.0, "pl_acumulado": 1050.0 },
    { "data": "2026-06-19", "pl_dia": -470.0, "pl_acumulado": 580.0 }
  ]
}
```

### 3.3 Exposição líquida — RN-019
```
GET /relatorios/exposicao-liquida?data=YYYY-MM-DD
```
Agrupa por produto: `Σ (quantidadeExposicao × sinal)` (comprado, vendido, líquido) + MtM.
A quantidade é **polimórfica** (D-705): **nocional** para o NDF (`valor_nocional`), a
quantidade base para FUTURO/OTC, `1` para a OPCAO (sem exposição direcional no MVP, D-705a).
`mix` traz a contagem por instrumento; `unidade_mista=true` avisa que o líquido soma
contratos (FUTURO/OTC) com nocional em moeda (NDF) — `mix['NDF']>0 && (mix['FUTURO']>0 ||
mix['OTC']>0)`.
```json
{
  "data": "2026-06-19",
  "produtos": [
    {
      "produto_id": 3, "produto": "Milho B3", "comprado": 10.0, "vendido": 4.0,
      "liquido": 6.0, "mtm": 80.0, "posicoes": 2,
      "mix": { "FUTURO": 2, "NDF": 0, "OPCAO": 0, "OTC": 0 }, "unidade_mista": false
    }
  ]
}
```

### 3.4 Histórico de MtM de uma posição
```
GET /relatorios/historico-mtm?posicao_id=X
```
Série temporal de uma posição (gráfico/sparkline), ordenada por `data_calculo`.
- `posicao_id` é **obrigatório**: ausência → **`422`** (entrada inválida).
- `posicao_id` inexistente → **`404`** no envelope §5.1 (`{"erro":"ERRO_NAO_ENCONTRADO"}`).
```json
{
  "posicao_id": 12,
  "pontos": [
    { "data_calculo": "2026-06-18", "preco_mercado": 105.0, "mtm_valor": 50.0, "variacao_dia": 50.0, "pl_acumulado": 50.0 },
    { "data_calculo": "2026-06-19", "preco_mercado": 108.0, "mtm_valor": 80.0, "variacao_dia": 30.0, "pl_acumulado": 80.0 }
  ]
}
```

### 3.5 Exportação CSV e PDF
```
GET /relatorios/posicao-aberta?data=2026-06-19&formato=csv   → download text/csv (BOM, CWE-1236)
GET /relatorios/exposicao-liquida?formato=pdf                → 501 FORMATO_INDISPONIVEL
```
O CSV prefixa `'` em células que começam com `= + - @` (tab/CR) para neutralizar fórmulas
ao abrir no Excel/Sheets. `formato` fora de `json|csv|pdf` → `422`.

---

## 4. Telas web (Livewire)

Todas sob sessão `auth`; injetam `ServicoRelatorios` direto (sem auto-chamada HTTP).

- **Dashboard** (`/dashboard`, home autenticada): P&L do dia/acumulado, nº de posições
  abertas e status da última execução do motor, com atalhos para os 3 relatórios.
- **Posição aberta** (`/relatorios/posicao-aberta`): seletor de data, alternância
  **por produto / por tipo**, totais e tabela (PM só no FUTURO).
- **P&L** (`/relatorios/pl`): cartões (acumulado, do dia, melhor/pior pregão), a série
  temporal e o detalhe por posição.
- **Exposição** (`/relatorios/exposicao`): comprado×vendido×líquido por produto, **mix de
  instrumentos** e aviso quando `unidade_mista=true`.

---

## 5. Fora do escopo desta parte

- **PDF** (`formato=pdf`) — diferido (D-707); segue no contrato e responde `501`.
- **Seed/dataset de demonstração** — Fase 8.
- **Exposição direcional da OPCAO** (delta/Greeks) e **normalização por unidade de
  contrato** — fora do MVP.
- **AuthZ por perfil** (OPERADOR/GESTOR/ADMIN) — Fase 10.
- **Reconstrução de status "as of date"** e **performance < 5 s para 1 ano** (load test) —
  fora do recorte; ver `specs/future/pontos_de_atencao.md`.
