# Spec — Parte 7: Relatórios (Parte 9)

> **Equivale à Fase 7 do `passos_dev.md`.** Estreia as **4 visões consolidadas da mesa de
> risco** — posição aberta, P&L diário/acumulado, exposição líquida e histórico de MtM —
> consumindo o histórico `mtm_diario` que o motor (Fase 6) produz. Leitura agregada pura:
> os números financeiros **já existem**; esta fase só os **soma, agrupa e serializa**.
>
> **Fonte da verdade:** `specs/requisitos.md` (v1.7) — §5.2.5 (API + `formato`),
> §7.4 (RN-016..019), §6.1 (telas 2/8/9/10), §6.2 (ciclo diário), §9.1 (relatórios < 5 s).
> Roteiro: `specs/passos_dev.md` (Fase 7). Em divergência, `requisitos.md` prevalece.
>
> **Natureza:** especificação executável — descreve **o que entregar**, as **decisões
> fixadas** e os critérios de aceite (DoD). **Não** altera regras de negócio, modelo de
> dados nem contratos de API (já definidos em `requisitos.md`). RN-016..019 são aplicadas
> **como escritas**; refinamentos (nocional, BCMath, status histórico) permanecem fora do MVP.
>
> **Convenção de numeração:** as decisões desta parte são `D-7xx` (Fase 7), seguindo a
> convenção das specs anteriores (`spec_parte_6.md` = `D-6xx`).

## 0. Decisões desta parte (fixadas)

| # | Tema | Decisão |
|---|---|---|
| **D-701** | Camada: um Service de leitura | `app/Services/ServicoRelatorios.php` concentra as 4 consultas agregadas (query builder/Eloquent direto) e devolve **read models** de `app/Services/Dados/`. **Não recalcula MtM** — os valores (`mtm_valor`, `variacao_dia`, `pl_acumulado`, `preco_mercado`) vêm prontos de `mtm_diario` (Fase 6). A única aritmética viva é **soma/agrupamento** no banco. O preço médio do FUTURO no relatório de posição aberta **reusa `Futuro::precoMedio()`** (Model, polimórfico) — não há fórmula duplicada (RN-016/§4.3.1). |
| **D-702** | "Último MtM disponível" (RN-016) | Para cada posição `ABERTA`, pega-se o MtM **mais recente com `data_calculo <= data`** (não estritamente `= data`): feriado/preço ausente deixa "buracos" no histórico (RN-012), e o snapshot deve usar o último valor conhecido. Implementado em **um único** `SELECT` com `DISTINCT ON (posicao_id) ... ORDER BY posicao_id, data_calculo DESC` (PostgreSQL), evitando N+1. Posição `ABERTA` **sem nenhum** MtM `<= data` (anterior ao 1º processamento) aparece com MtM `null`/`0` e é sinalizada. |
| **D-703** | Parâmetros e validação | `data` é **opcional** e default = **hoje** (§6.2; mock `D3.HOJE`). Validação por **Form Request**: `RelatorioRequest` (`data` `nullable|date`, `formato` `nullable|in:json,csv,pdf`, default `json`) para os três relatórios de data; **`HistoricoMtmRequest` dedicado** para o histórico, com `posicao_id` `required|integer` (de fato obrigatório — um `sometimes` no request compartilhado **não** garante 422 quando o parâmetro falta). Datas e ids inválidos → `422` nativo do Laravel; `historico-mtm` **sem** `posicao_id` → `422` (entrada inválida, não 404); posição inexistente no histórico → `404` via `ErroNaoEncontrado` (envelope §5.1). |
| **D-704** | Semântica de P&L (RN-017/018) | **P&L diário (RN-017)** = `Σ variacao_dia` das linhas `mtm_diario` com `data_calculo = data` (data **exata** — é o resultado *daquele* pregão). **P&L acumulado (RN-018)** = `Σ pl_acumulado` do **último MtM `<= data`** (reaproveita D-702) das posições com `status = ABERTA` (inclui o realizado das reduções, RN-023). A **série temporal** para o gráfico (mock `RelPLScreen`) é conveniência de UI: agregação por `data_calculo` na janela. A coluna de "acumulado" da série usa **`SUM(pl_acumulado)`** (coerente com RN-018), **não** `SUM(mtm_valor)` — os dois divergem pelo realizado das reduções (RN-023), e rotular a curva por `mtm_valor` faria o último ponto não bater com o cartão "P&L acumulado". **Ressalva documentada:** mesmo com `SUM(pl_acumulado)`, o último ponto da série agrega por `data_calculo` sobre **todas** as posições daquele dia, enquanto o KPI é o snapshot (último `<= data`) só das **ABERTA** — populações diferentes, então pode haver pequena diferença residual; o número **canônico** das RNs é sempre o **da data** (escalar), a série é ilustrativa. |
| **D-705** | Exposição líquida (RN-019) por **nocional polimórfico** | `GROUP BY produto_id` com `Σ (quantidadeExposicao × sinal)` sobre `status = ABERTA`, usando um **método polimórfico novo `Posicao::quantidadeExposicao(): float`** (padrão *fat model*, sem `if`/`switch` no Service): a **base** devolve `posicao.quantidade` (consolidada RN-024 — vale para **FUTURO** e **OTC**); **`Ndf` sobrescreve** devolvendo `posicao_ndf.valor_nocional` (RN-019 sobre o **nocional**, não sobre o campo base do payload). O sinal continua vindo de `Posicao::sinal()` (±1). **`OPCAO` fica de fora do tratamento por nocional (D-705a):** mantém a base `quantidade = 1` (RN-004e); exposição direcional de opção depende de **delta/Greeks** (não calculado no MVP), então somar `quantidade × lado` das pernas produziria um número sem significado direcional. O MtM por produto usa o último MtM `<= data` (D-702). |
| **D-705a** | Mismatch de unidade (documentado) | O "líquido por produto" da exposição **soma grandezas de unidades diferentes**: contratos/quantidade física do **FUTURO/OTC** e **nocional em moeda** do **NDF**. É **apples-to-oranges** por construção — aceito no MVP porque, na prática, cada produto tende a ter um tipo de instrumento dominante. O relatório (API e UI) **declara essa ressalva** e expõe o **mix de instrumentos por produto** (contagem por tipo) para que o gestor saiba o que está sendo somado. Normalização por unidade/fator de contrato e exposição direcional de opção (delta) ficam **fora do MVP**. |
| **D-706** | Status corrente, não histórico | Os relatórios filtram pelo **status atual** da posição (`ABERTA`); **não** reconstroem o status que a posição tinha "na data". Para uma `data` antiga, uma posição hoje `VENCIDA/ENCERRADA` **não** entra em posição-aberta/exposição daquela data. Limitação assumida do MVP (reprocesso de datas antigas é raro, §12.6) — registrada como risco. |
| **D-707** | `formato`: `json`+`csv` agora, `pdf` diferido | `json` (default) via read model `paraArray()` (números **sem aspas**, §5.1). `csv` via **exportador** que **reaproveita o endurecimento anti-formula-injection (CWE-1236)** da Fase 4 (mesmos prefixos perigosos `= + - @ \t \r`), entregue como `StreamedResponse` (`text/csv`, `Content-Disposition: attachment`). `pdf` **permanece no contrato** (`in:json,csv,pdf`, §5.2.5) mas responde **`501 Not Implemented`** no envelope §5.1 (`"erro":"FORMATO_INDISPONIVEL"`) — entrada **válida e documentada** que o **servidor** ainda não suporta (501), não erro do cliente (não 422). Geração de PDF (dependência extra) fica para a fase de hardening/entrega; empurrá-la mantém o recorte enxuto sem quebrar o contrato. **Tipo de retorno (PHPStan nível 8):** como o controller devolve `StreamedResponse` (csv) **e** `JsonResponse` (json/pdf), cujo supertipo comum é o **`Symfony\Component\HttpFoundation\Response`** (não o `Illuminate\Http\Response`), `responder()` e as 4 ações são tipadas como `Symfony\Component\HttpFoundation\Response`. |
| **D-708** | Telas Livewire (mock) | 4 telas injetando `ServicoRelatorios` (sem auto-chamada HTTP, padrão D-610): **Dashboard** (`/`, §6.1#2 — P&L total do dia, nº de posições abertas, status da última execução do motor), **Posição aberta** (`/relatorios/posicao-aberta`), **P&L** (`/relatorios/pl` — gráfico + tabela) e **Exposição líquida** (`/relatorios/exposicao`). Espelham `mock_telas/screens.jsx` (`DashboardScreen`) e `screens3.jsx` (`RelPosicaoAberta/RelPL/RelExposicao`). |
| **D-709** | AuthZ por perfil deferida | Rotas API sob `auth:sanctum` e web sob `auth`, **sem** distinção de perfil — consistente com D-612/D-402. A restrição por perfil (§9.2) entra na **Fase 10**. Registrar a ressalva. |
| **D-710** | Read models flat em `Dados/` | `LinhaPosicaoAberta`/`RelatorioPosicaoAberta`, `ResumoPL`, `ExposicaoProduto`, `PontoHistoricoMtm` — DTOs `final` com `paraArray()` flat (§5.1). O Eloquent/agregação é extraído na borda do Service e **não vaza** para HTTP/UI. Conversão `(float)` na borda (os casts `decimal:` devolvem string). |

## 1. Objetivo e escopo

**Objetivo:** entregar as 4 visões consolidadas da mesa de risco a partir do histórico
`mtm_diario`, expostas por **API REST** (§5.2.5, com `formato=json|csv`), por **telas
Livewire** (Dashboard + 3 relatórios) e cobertas por **feature tests** que batem com cálculo
manual (DoD da Fase 7).

**Dentro do escopo**
- `app/Services/ServicoRelatorios.php` — 4 consultas agregadas (RN-016..019) + histórico de MtM.
- **`Posicao::quantidadeExposicao()`** (novo, polimórfico) com override em `Ndf` — exposição por
  **nocional** no NDF, base no FUTURO/OTC, `1` na OPCAO (D-705/D-705a).
- Read models em `app/Services/Dados/`: `RelatorioPosicaoAberta`/`LinhaPosicaoAberta`,
  `ResumoPL`, `ExposicaoProduto`, `PontoHistoricoMtm`.
- **API REST** §5.2.5 em `app/Http/Controllers/Api/V1/RelatorioController.php`
  (`posicaoAberta`, `plDiario`, `exposicaoLiquida`, `historicoMtm`) + `RelatorioRequest` + `HistoricoMtmRequest`.
- **Exportador CSV** (`app/Support/Csv/ExportadorCsv.php`) com endurecimento CWE-1236 (D-707).
- **Telas Livewire**: `Dashboard` + `RelPosicaoAberta` + `RelPL` + `RelExposicao` (D-708) e rotas web.
- Feature tests RN-016..019 (números conferidos contra planilha) + histórico + `formato=csv`.

**Fora do escopo (outras fases)**
- **Geração de PDF** (`formato=pdf`) — diferida (D-707); `pdf` segue no contrato §5.2.5 e o endpoint responde `501` por ora.
- **Seed/dataset de demonstração** (que tornará os relatórios "ricos") — **Fase 8** (§6.2).
- **Reconstrução de status histórico** ("as of date" fiel) — fora do MVP (D-706).
- **Exposição direcional da OPCAO** (delta/Greeks) e **normalização por unidade de contrato** —
  fora do MVP; a exposição da OPCAO permanece pela base (`quantidade = 1`, D-705a).
- **Decomposição preço × câmbio da `variacao_dia`** e **BCMath/Money** — críticas reafirmadas
  como fora do MVP (`pontos_de_atencao.md`).
- **AuthZ por perfil** (OPERADOR/GESTOR/ADMIN, §9.2) — **Fase 10** (D-709/D-402).
- **Performance < 5 s para 1 ano de histórico** (§9.1): a consulta `DISTINCT ON` + índices já
  existentes (`idx_mtm_posicao_data`, `idx_mtm_data`) atendem o MVP; *load test* formal é Fase 12.

## 2. Mapa de arquivos × responsabilidade

| Arquivo | Camada | Responsabilidade |
|---|---|---|
| `app/Services/ServicoRelatorios.php` | aplicação | 4 consultas agregadas (RN-016..019) + `historicoMtm`. Devolve read models. Reusa `Futuro::precoMedio()` para o PM do FUTURO (D-701). **(novo)** |
| `app/Services/Dados/LinhaPosicaoAberta.php` | DTO | Uma linha do relatório de posição aberta (posição + último MtM `<= data`; PM se FUTURO). **(novo)** |
| `app/Services/Dados/RelatorioPosicaoAberta.php` | DTO | Coleção de `LinhaPosicaoAberta` + totais (MtM consolidado, Δ dia). **(novo)** |
| `app/Services/Dados/ResumoPL.php` | DTO | P&L diário (RN-017) + acumulado (RN-018) na data + série para o gráfico. **(novo)** |
| `app/Services/Dados/ExposicaoProduto.php` | DTO | Por produto: comprado, vendido, líquido (`Σ quantidade×sinal`), MtM, nº posições (RN-019). **(novo)** |
| `app/Services/Dados/PontoHistoricoMtm.php` | DTO | Um ponto da série `historico-mtm` de uma posição. **(novo)** |
| `app/Http/Controllers/Api/V1/RelatorioController.php` | HTTP | 4 endpoints §5.2.5; negocia `formato` (json/csv/pdf→501); retorno tipado `Symfony\...\Response` (A-1). **(novo)** |
| `app/Http/Requests/RelatorioRequest.php` | HTTP | Valida `data`/`formato` dos 3 relatórios de data (D-703). **(novo)** |
| `app/Http/Requests/HistoricoMtmRequest.php` | HTTP | Valida `posicao_id` `required` + `formato` do histórico (D-703, A-2). **(novo)** |
| `app/Support/Csv/ExportadorCsv.php` | suporte | Serializa `array<string,scalar>[]` em CSV com sanitização CWE-1236 (D-707). **(novo)** |
| `app/Livewire/Relatorios/Dashboard.php` (+ view) | UI | Dashboard do dia (§6.1#2). **(novo)** |
| `app/Livewire/Relatorios/PosicaoAberta.php` (+ view) | UI | Tabela consolidada + agrupar por produto/tipo (D-708). **(novo)** |
| `app/Livewire/Relatorios/PL.php` (+ view) | UI | Gráfico de evolução + detalhe por posição (D-708). **(novo)** |
| `app/Livewire/Relatorios/Exposicao.php` (+ view) | UI | Comprado vs. vendido por produto (D-708). **(novo)** |
| `routes/api.php` | HTTP | 4 rotas `/relatorios/*` sob `auth:sanctum`. **(editado)** |
| `routes/web.php` | web | Rotas `/` (Dashboard) e `/relatorios/*` (Livewire) sob `auth`. **(editado)** |
| `app/Models/Posicao.php` | Model | **Já existe.** Ganha `quantidadeExposicao(): float` (base = `quantidade`, RN-024). **(editado)** |
| `app/Models/Ndf.php` | Model | **Já existe.** Sobrescreve `quantidadeExposicao()` → `valor_nocional` (D-705). **(editado)** |
| `app/Models/MtmDiario.php`, `Futuro.php`, `Opcao.php`, `Otc.php`, `Produto.php` | Model | **Já existem.** Reusados (relações, `precoMedio()`, `sinal()`); `Futuro/Opcao/Otc` herdam `quantidadeExposicao()` da base. **(reuso)** |

## 3. Pré-requisitos

- Fases 1–6 verdes. Em especial: **Fase 6 (Motor MtM)** populando `mtm_diario`
  (`mtm_valor`, `variacao_dia`, `pl_acumulado`, `preco_mercado`, `data_calculo`).
- Models e cálculo prontos: `Futuro::precoMedio()/quantidadeAtual()` (replay puro),
  `Posicao::sinal()`, relações `posicao.produto`, `mtm_diario.posicao`.
- Índices de `mtm_diario`: `idx_mtm_posicao_data (posicao_id, data_calculo)` e
  `idx_mtm_data (data_calculo)`; índice parcial `idx_posicao_status WHERE status='ABERTA'`.
- Exceções/`bootstrap/app.php` mapeando o envelope §5.1 (`ErroNaoEncontrado`/`ErroValidacao`).
- Banco **PostgreSQL** (a consulta de snapshot usa `DISTINCT ON`, específico do Postgres).
- `mock_telas/screens.jsx` (`DashboardScreen`) e `screens3.jsx` (relatórios) como referência de UI.

## 4. Passo a passo

### 4.0 Visão geral

```
GET /relatorios/posicao-aberta?data=  → RN-016  → RelatorioPosicaoAberta
GET /relatorios/pl-diario?data=       → RN-017+018 → ResumoPL
GET /relatorios/exposicao-liquida?data= → RN-019 → ExposicaoProduto[]
GET /relatorios/historico-mtm?posicao_id= →        PontoHistoricoMtm[]
  cada um aceita ?formato=json (default) | csv | pdf(→501)
```

O coração é **um SELECT de snapshot** reusado por posição-aberta, P&L acumulado e exposição:
o último `mtm_diario` de cada posição com `data_calculo <= data` (D-702).

### 4.1 `ServicoRelatorios` — snapshot e agregações (RN-016..019)

```php
namespace App\Services;

use App\Exceptions\ErroNaoEncontrado;
use App\Models\{Futuro, MtmDiario, Ndf, Opcao, Otc, Posicao};
use App\Services\Dados\{ExposicaoProduto, LinhaPosicaoAberta, PontoHistoricoMtm, RelatorioPosicaoAberta, ResumoPL};
use Illuminate\Support\Facades\DB;

class ServicoRelatorios
{
    /**
     * Último mtm_diario (<= data) de cada posição ABERTA, em UM SELECT (D-702).
     * DISTINCT ON é específico do PostgreSQL; a ORDER BY casa com idx_mtm_posicao_data.
     *
     * @return \Illuminate\Support\Collection<int, object> keyBy posicao_id
     */
    private function snapshot(string $data): \Illuminate\Support\Collection
    {
        return collect(DB::select(<<<'SQL'
            SELECT DISTINCT ON (m.posicao_id)
                   m.posicao_id, m.mtm_valor, m.variacao_dia, m.pl_acumulado,
                   m.preco_mercado, m.data_calculo
              FROM mtm_diario m
              JOIN posicao p ON p.id = m.posicao_id
             WHERE p.status = 'ABERTA' AND m.data_calculo <= ?
             ORDER BY m.posicao_id, m.data_calculo DESC
        SQL, [$data]))->keyBy('posicao_id');
    }

    /** RN-016: posições ABERTA + último MtM disponível; PM do FUTURO reusa o Model (D-701). */
    public function posicaoAberta(string $data): RelatorioPosicaoAberta
    {
        $snap = $this->snapshot($data);

        // Carga polimórfica POR SUBCLASSE (mesmo idioma do MotorMtm, D-608): cada query hidrata
        // sua subclasse com o eager loading que precisa. FUTURO traz futuro+movimentacoes (PM via
        // replay); os demais só produto. Motivação = consistência de idioma no codebase, NÃO
        // economia de query (por subclasse são 4 SELECTs — aceitável no N do MVP).
        $posicoes = collect()
            ->merge(Futuro::query()->with(['produto', 'futuro', 'movimentacoes'])->where('status', 'ABERTA')->where('instrumento', 'FUTURO')->get())
            ->merge(Ndf::query()->with('produto')->where('status', 'ABERTA')->where('instrumento', 'NDF')->get())
            ->merge(Opcao::query()->with('produto')->where('status', 'ABERTA')->where('instrumento', 'OPCAO')->get())
            ->merge(Otc::query()->with('produto')->where('status', 'ABERTA')->where('instrumento', 'OTC')->get());

        $linhas = $posicoes->map(function (Posicao $p) use ($snap) {
            $m = $snap->get($p->id);

            return new LinhaPosicaoAberta(
                posicaoId:     (int) $p->id,
                produtoId:     (int) $p->produto_id,
                produtoNome:   $p->produto->nome,
                instrumento:   $p->instrumento,
                lado:          $p->lado,
                quantidade:    Posicao::paraFloat($p->quantidade),
                precoMedio:    $p instanceof Futuro ? $p->precoMedio() : null,   // RN-016 (só FUTURO)
                precoMercado:  $m ? (float) $m->preco_mercado : null,
                dataVencimento: $p->data_vencimento->format('Y-m-d'),
                mtm:           $m ? (float) $m->mtm_valor : 0.0,
                variacaoDia:   $m ? (float) $m->variacao_dia : 0.0,
                temMtm:        $m !== null,
            );
        })->values();

        return new RelatorioPosicaoAberta($data, $linhas->all());
    }

    /** RN-017 (diário, data exata) + RN-018 (acumulado, último <= data das ABERTA). */
    public function plDiario(string $data): ResumoPL
    {
        $plDiario = (float) MtmDiario::query()
            ->where('data_calculo', $data)
            ->sum('variacao_dia');                                  // RN-017

        $plAcumulado = (float) $this->snapshot($data)
            ->sum(fn ($m) => (float) $m->pl_acumulado);             // RN-018

        // Série para o gráfico (conveniência de UI): P&L diário e acumulado por data_calculo.
        // "acumulado" usa SUM(pl_acumulado) — coerente com RN-018 (D-704), NÃO SUM(mtm_valor)
        // (que ignora o realizado das reduções e divergiria do cartão "P&L acumulado").
        $serie = MtmDiario::query()
            ->selectRaw('data_calculo, SUM(variacao_dia) AS pl_dia, SUM(pl_acumulado) AS pl_acum')
            ->where('data_calculo', '<=', $data)
            ->groupBy('data_calculo')->orderBy('data_calculo')->get();

        return ResumoPL::montar($data, $plDiario, $plAcumulado, $serie);
    }

    /**
     * RN-019: Σ (quantidadeExposicao × sinal) por produto, sobre ABERTA. A quantidade é
     * polimórfica (D-705): base = posicao.quantidade (FUTURO/OTC); Ndf = valor_nocional;
     * Opcao = 1 (sem tratamento por nocional, D-705a). Σ em PHP para usar os métodos do
     * Model (defesa em profundidade); o N de posições abertas é pequeno no MVP.
     *
     * @return list<ExposicaoProduto>
     */
    public function exposicaoLiquida(string $data): array
    {
        $snap = $this->snapshot($data);
        $acc = [];                                                  // produto_id => ExposicaoProduto (mutável)

        // Carga polimórfica POR SUBCLASSE (idioma do MotorMtm, D-608). NDF precisa de `ndf` para
        // o nocional (Ndf::quantidadeExposicao() — senão N+1); os demais só produto.
        $posicoes = collect()
            ->merge(Futuro::query()->with('produto')->where('status', 'ABERTA')->where('instrumento', 'FUTURO')->get())
            ->merge(Ndf::query()->with(['produto', 'ndf'])->where('status', 'ABERTA')->where('instrumento', 'NDF')->get())
            ->merge(Opcao::query()->with('produto')->where('status', 'ABERTA')->where('instrumento', 'OPCAO')->get())
            ->merge(Otc::query()->with('produto')->where('status', 'ABERTA')->where('instrumento', 'OTC')->get());

        foreach ($posicoes as $p) {
            $e = $acc[$p->produto_id] ??= ExposicaoProduto::vazia((int) $p->produto_id, $p->produto->nome);
            $q = $p->quantidadeExposicao();                         // D-705: nocional p/ NDF, base p/ o resto
            $p->sinal() > 0 ? $e->somarComprado($q) : $e->somarVendido($q);
            $e->somarMtm($snap->get($p->id) ? (float) $snap->get($p->id)->mtm_valor : 0.0);
            $e->contar($p->instrumento);                            // D-705a: registra o mix de instrumentos
        }

        return array_values($acc);
    }

    /**
     * Série temporal de MtM de UMA posição (gráfico/sparkline). 404 se a posição não existe.
     *
     * @return list<PontoHistoricoMtm>
     */
    public function historicoMtm(int $posicaoId): array
    {
        Posicao::query()->find($posicaoId)
            ?? throw new ErroNaoEncontrado('Posição não encontrada.');  // envelope §5.1

        return MtmDiario::query()->where('posicao_id', $posicaoId)
            ->orderBy('data_calculo')->get()
            ->map(fn (MtmDiario $m) => PontoHistoricoMtm::deModel($m))
            ->all();
    }
}
```

> **Sem `if` por instrumento no laço de relatório:** o único `instanceof Futuro` é para
> decidir **exibir** o preço médio (RN-016 manda PM só para FUTURO) — não é cálculo de MtM
> (que continua no Model). `sinal()` é polimórfico na base. Polimorfismo do motor intacto.

> **404 no envelope §5.1 — `find() ?? throw`, não `findOrFail`:** o handler global
> (`bootstrap/app.php`) só registra `render` para `ErroAplicacao`. `findOrFail` lança
> `Illuminate\Database\Eloquent\ModelNotFoundException` (que **não** é `ErroAplicacao`), então o
> 404 vazaria no formato padrão do Laravel (`{message}`), fora do envelope §5.1 — exatamente o bug
> que o commit `2b45a71` corrigiu. Por isso o padrão canônico do repositório (`ServicoPosicoes`/
> `ServicoProdutos`) é `find($id) ?? throw new ErroNaoEncontrado(...)`, seguido aqui.

### 4.1a Nocional polimórfico — `quantidadeExposicao()` (D-705)

A exposição por nocional **não** é um `if`/`switch` no relatório: é um **método novo na
hierarquia de Models** (mesmo padrão de `calcularMtm()`/`plRealizado()`). A base devolve a
quantidade consolidada; só o `Ndf` sobrescreve. **`Futuro`, `Otc` e `Opcao` herdam a base** —
em particular a `Opcao` herda `quantidade = 1` (RN-004e), ficando **fora** do tratamento por
nocional (D-705a), pois exposição direcional de opção exige delta (não calculado no MVP).

```php
// App\Models\Posicao (base) — default: a coluna consolidada (FUTURO/OTC; OPCAO = 1)
public function quantidadeExposicao(): float
{
    return self::paraFloat($this->quantidade);                  // RN-024
}

// App\Models\Ndf — sobrescreve: a exposição do NDF é o nocional (em moeda), não o campo base
public function quantidadeExposicao(): float
{
    return self::paraFloat($this->ndf->valor_nocional);         // RN-019 sobre o nocional
}
```

> **Por que método no Model e não coluna/Service:** mantém o polimorfismo (novo instrumento =
> novo override, o Service não muda) e evita duplicar a noção de "tamanho econômico" da posição.
> O `Ndf::quantidadeExposicao()` lê `$this->ndf` — **exige eager loading de `ndf`** no Service
> (feito em `exposicaoLiquida`) para não gerar N+1.

### 4.2 Read models (`app/Services/Dados/`)

```php
final class LinhaPosicaoAberta
{
    public function __construct(
        public int $posicaoId,
        public int $produtoId,
        public string $produtoNome,
        public string $instrumento,
        public string $lado,
        public float $quantidade,
        public ?float $precoMedio,     // só FUTURO (RN-016)
        public ?float $precoMercado,   // null se ainda sem MtM <= data
        public string $dataVencimento,
        public float $mtm,
        public float $variacaoDia,
        public bool $temMtm,
    ) {}

    /** @return array<string, mixed> */
    public function paraArray(): array { /* chaves snake_case, números sem aspas (§5.1) */ }
}
```

`RelatorioPosicaoAberta` agrega `LinhaPosicaoAberta[]` e expõe `totalMtm`/`totalVariacao`
(somas) + `paraArray()` com `{data, total_mtm, total_variacao, posicoes: [...]}`.

`ResumoPL::montar(...)` devolve `{data, pl_diario, pl_acumulado, serie: [{data, pl_dia, pl_acumulado}]}`.

`ExposicaoProduto` é mutável durante a agregação (`somarComprado/Vendido/Mtm`, `contar(string
$instrumento)`, `vazia()`); `liquido()` = `comprado - vendido`. O `contar()` acumula o **mix de
instrumentos** (`{FUTURO:n, NDF:n, OPCAO:n, OTC:n}`, espelhando o mock `RelExposicaoScreen`).
`paraArray()` → `{produto_id, produto, comprado, vendido, liquido, mtm, posicoes, mix, unidade_mista}`,
onde `unidade_mista` sinaliza o mismatch de unidade (D-705a) ao cliente. **Fórmula determinística**
(para teste reproduzível): `unidade_mista = mix['NDF'] > 0 && (mix['FUTURO'] > 0 || mix['OTC'] > 0)`
— i.e. soma nocional em moeda (NDF) com contratos/quantidade física (FUTURO/OTC). A OPCAO
(`quantidade = 1`, D-705a) **não** dispara o flag sozinha, pois não soma grandeza com sentido direcional.

`PontoHistoricoMtm::deModel(MtmDiario)` →
`{data_calculo, preco_mercado, mtm_valor, variacao_dia, pl_acumulado}` (todos `(float)`).

### 4.3 API REST (§5.2.5) — `RelatorioController` + `RelatorioRequest`

```php
use Symfony\Component\HttpFoundation\Response;   // supertipo de StreamedResponse (csv) E JsonResponse — A-1/D-707

class RelatorioController extends Controller
{
    public function __construct(private readonly ServicoRelatorios $rel) {}

    public function posicaoAberta(RelatorioRequest $request): Response
    {
        $dados = $this->rel->posicaoAberta($request->data());
        return $this->responder($request, $dados->paraArray(), $dados->paraLinhasCsv(), 'posicao-aberta');
    }

    public function plDiario(RelatorioRequest $request): Response { /* idem ResumoPL */ }
    public function exposicaoLiquida(RelatorioRequest $request): Response { /* idem ExposicaoProduto[] */ }

    // Form Request DEDICADO (A-2): posicao_id é required de fato; ausência → 422 (não 404).
    public function historicoMtm(HistoricoMtmRequest $request): Response
    {
        $pontos = $this->rel->historicoMtm($request->integer('posicao_id'));
        // ... json/csv via responder() (histórico não tem 'data', só posicao_id)
    }

    /** Negocia o formato (D-707): json (flat §5.1), csv (stream endurecido), pdf → 501. */
    private function responder(RelatorioRequest $r, array $json, array $linhasCsv, string $arquivo): Response
    {
        return match ($r->formato()) {
            'csv' => app(ExportadorCsv::class)->resposta($linhasCsv, "{$arquivo}.csv"),
            // pdf está no contrato (§5.2.5) mas o SERVIDOR ainda não o implementa → 501, não 422.
            'pdf' => response()->json(
                ['erro' => 'FORMATO_INDISPONIVEL', 'mensagem' => 'Exportação em PDF ainda não suportada.'],
                Response::HTTP_NOT_IMPLEMENTED,   // 501
            ),
            default => response()->json($json),
        };
    }
}
```

**Dois Form Requests (D-703):**
- `RelatorioRequest` (posição-aberta/pl/exposição): `['data' => ['nullable','date'], 'formato' =>
  ['nullable','in:json,csv,pdf']]`; helpers `data()` (`?? today()->toDateString()`) e `formato()` (`?? 'json'`).
- `HistoricoMtmRequest` (histórico): `['posicao_id' => ['required','integer'], 'formato' =>
  ['nullable','in:json,csv,pdf']]` + helper `formato()`. `required` **de fato** garante `422` quando
  o parâmetro falta (um `sometimes` no request compartilhado não garantiria — viraria `404`).

Rotas em `routes/api.php` (grupo `auth:sanctum`):

```php
// §5.2.5 Relatórios (AuthZ por perfil é Fase 10, D-709)
Route::get('relatorios/posicao-aberta', [RelatorioController::class, 'posicaoAberta']);
Route::get('relatorios/pl-diario', [RelatorioController::class, 'plDiario']);
Route::get('relatorios/exposicao-liquida', [RelatorioController::class, 'exposicaoLiquida']);
Route::get('relatorios/historico-mtm', [RelatorioController::class, 'historicoMtm']);
```

### 4.4 `ExportadorCsv` — CSV endurecido (D-707, CWE-1236)

```php
namespace App\Support\Csv;

use Symfony\Component\HttpFoundation\StreamedResponse;

class ExportadorCsv
{
    /** @var list<string> */
    private const PERIGOSOS = ['=', '+', '-', '@', "\t", "\r"];     // CWE-1236 (mesma lista do importador)

    /** @param list<array<string, scalar|null>> $linhas (1ª linha define o cabeçalho) */
    public function resposta(array $linhas, string $nomeArquivo): StreamedResponse
    {
        return response()->streamDownload(function () use ($linhas) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");                            // BOM p/ Excel pt-BR
            if ($linhas !== []) {
                fputcsv($out, array_keys($linhas[0]));
                foreach ($linhas as $linha) {
                    fputcsv($out, array_map($this->sanitizar(...), array_values($linha)));
                }
            }
            fclose($out);
        }, $nomeArquivo, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function sanitizar(string|int|float|null $v): string
    {
        $s = (string) $v;
        return $s !== '' && in_array($s[0], self::PERIGOSOS, true) ? "'".$s : $s;  // prefixa aspa (neutraliza fórmula)
    }
}
```

### 4.5 Telas Livewire (D-708)

- **Dashboard** (`/`): KPIs do dia — `pl_diario`/`pl_acumulado` (de `plDiario(hoje)`), nº de
  posições `ABERTA`, e **status da última execução** (`MotorExecucao::latest('iniciado_em')`,
  reusando a Fase 6). Atalhos para os 3 relatórios. Espelha `DashboardScreen`.
- **PosicaoAberta** (`/relatorios/posicao-aberta`): seletor de `data`, alternância
  **agrupar por produto/tipo**, totais e tabela (PM só para FUTURO). Espelha `RelPosicaoAbertaScreen`.
- **PL** (`/relatorios/pl`): cartões (acumulado, do dia, melhor/pior pregão), gráfico da série e
  detalhe por posição. Espelha `RelPLScreen`.
- **Exposicao** (`/relatorios/exposicao`): tabela comprado×vendido×líquido por produto + tiles
  NET LONG/SHORT, **coluna de mix de instrumentos** e um aviso quando `unidade_mista=true`
  (líquido soma contratos + nocional, D-705a). Espelha `RelExposicaoScreen` (que já mostra o mix).

Todas injetam `ServicoRelatorios` no `mount()`/método e chamam o Service direto (D-708/D-610).
Rotas em `routes/web.php` (grupo `auth`); `/` (Dashboard) substitui/!complementa a home atual.

## 5. Estrutura esperada após a Parte 7

```
app/
  Models/
    Posicao.php                               (editado: + quantidadeExposicao())
    Ndf.php                                   (editado: override quantidadeExposicao() → nocional)
  Services/
    ServicoRelatorios.php                     (novo)
    Dados/
      LinhaPosicaoAberta.php                   (novo)
      RelatorioPosicaoAberta.php               (novo)
      ResumoPL.php                             (novo)
      ExposicaoProduto.php                     (novo)
      PontoHistoricoMtm.php                    (novo)
  Http/
    Controllers/Api/V1/RelatorioController.php (novo)
    Requests/RelatorioRequest.php              (novo)
    Requests/HistoricoMtmRequest.php           (novo)
  Support/Csv/ExportadorCsv.php                (novo)
  Livewire/Relatorios/
    Dashboard.php  PosicaoAberta.php  PL.php  Exposicao.php   (novos)
resources/views/livewire/relatorios/
    dashboard.blade.php  posicao-aberta.blade.php  pl.blade.php  exposicao.blade.php (novos)
routes/api.php   (editado: 4 rotas /relatorios/*)
routes/web.php   (editado: / + /relatorios/*)
tests/Feature/RelatoriosTest.php               (novo)
```

## 6. Arquivos a entregar (checklist)

- [ ] `ServicoRelatorios` com `snapshot/posicaoAberta/plDiario/exposicaoLiquida/historicoMtm`.
- [ ] 5 read models em `Dados/` com `paraArray()` flat (§5.1, números sem aspas).
- [ ] `RelatorioController` (4 ações, retorno `Symfony\...\Response`) + `RelatorioRequest` +
      `HistoricoMtmRequest` (posicao_id `required`) + 4 rotas API sob `auth:sanctum`.
- [ ] `ExportadorCsv` com sanitização CWE-1236 e BOM; `formato=pdf` → `501` (envelope §5.1).
- [ ] 4 componentes Livewire + views + rotas web sob `auth` (Dashboard em `/`).
- [ ] `tests/Feature/RelatoriosTest.php`: RN-016 (posição aberta + PM FUTURO + último MtM ≤ data),
      RN-017 (Σ variacao_dia na data), RN-018 (Σ pl_acumulado das ABERTA), RN-019 (Σ qtd×sinal por
      produto), histórico de MtM, `historico-mtm` **sem** `posicao_id` → 422, `formato=csv`
      (cabeçalho + sanitização), `formato=pdf` → 501, `data` ausente → hoje, 401 sem token.
- [ ] `./vendor/bin/pint --test` e `phpstan analyse` (nível 8) **verdes**.
- [ ] `composer test` **verde** (incluindo a suíte nova).

## 7. Definition of Done (critérios de aceite)

1. **RN-016** — `GET /relatorios/posicao-aberta?data=` lista todas as posições `ABERTA` com o
   **último MtM ≤ data**; FUTURO traz `preco_medio` (vindo de `Futuro::precoMedio()`); posição
   sem MtM aparece com `tem_mtm=false`/valores 0.
2. **RN-017** — `pl_diario` = soma de `variacao_dia` de **`data_calculo = data`** (data exata).
3. **RN-018** — `pl_acumulado` = soma do `pl_acumulado` do último MtM ≤ data das posições `ABERTA`
   (inclui realizado — RN-023).
4. **RN-019** — `exposicao-liquida` agrupa por produto e devolve `Σ (quantidadeExposicao × sinal)`
   (comprado, vendido, líquido) + MtM por produto, onde a quantidade é **polimórfica**: nocional
   para **NDF** (`valor_nocional`), base para **FUTURO/OTC**, `1` para **OPCAO** (D-705/D-705a). A
   resposta traz `mix` (contagem por instrumento) e `unidade_mista` sinalizando o mismatch de unidade.
5. **Histórico** — `GET /relatorios/historico-mtm?posicao_id=` devolve a série ordenada por data;
   `posicao_id` **ausente** → `422` (entrada inválida, via `HistoricoMtmRequest`); `posicao_id`
   inexistente → `404` no envelope §5.1 (via `find() ?? throw ErroNaoEncontrado`, não `findOrFail`).
6. **`formato`** — `json` (default, flat, números sem aspas), `csv` (download endurecido CWE-1236
   com BOM e cabeçalho), `pdf` → `501` (`FORMATO_INDISPONIVEL`, no envelope §5.1; `pdf` segue no contrato §5.2.5).
7. **Telas** — Dashboard + 3 relatórios renderizam com dados reais (Service injetado, sem HTTP),
   fiéis ao mock; protegidas por `auth`.
8. **Cada relatório bate com cálculo manual** num cenário de aceite com histórico semeado (a
   planilha/fixture de teste confere os 4 números) — DoD da Fase 7.
9. **Camadas e arquitetura** — leitura agregada no Service; nenhum recálculo de MtM; PM do FUTURO
   reusado do Model; `pint`/`phpstan`/suíte verdes.

## 8. Riscos e pontos a verificar

| Risco | Mitigação / ação |
|---|---|
| **`DISTINCT ON` é PostgreSQL-only** | O projeto já roda Postgres 15 (CLAUDE.md). A query de snapshot está isolada em `snapshot()`; se o ambiente de teste usar SQLite, manter Postgres no CI (já é o caso) ou substituir por subconsulta correlacionada equivalente. Cobrir com teste que cria 2 datas e confere que pega a **mais recente ≤ data**. |
| **N+1 no relatório de posição aberta** | Snapshot em **um** SELECT (D-702) + eager loading de `produto`/`futuro`/`movimentacoes`. O `precoMedio()` do FUTURO faz replay em memória sobre relação já carregada — aceito no MVP; denormalização do PM é Fase 12 (`pontos_de_atencao.md`). |
| **Buraco de MtM (feriado/preço ausente) infla/zera o snapshot** | D-702 usa **último ≤ data**, não `= data` exato — evita "sumir" com a posição num dia sem preço. Posição **nunca** processada vira `tem_mtm=false` (sinalizada, não erro). Teste com data anterior ao 1º MtM. |
| **Exposição por nocional (NDF) vs. base** | `Posicao::quantidadeExposicao()` polimórfico (D-705): NDF usa `valor_nocional`, FUTURO/OTC a base. `Ndf` exige eager loading de `ndf` (senão N+1). Teste: NDF de nocional 1.000.000 entra na exposição como 1.000.000, não como o campo base. |
| **OPCAO sem exposição direcional** | D-705a: OPCAO fica com `quantidade = 1` (sem delta no MVP) — somar `quantidade × lado` das pernas não representa direção. Documentado; refinamento (Greeks) é fase futura. Teste confirma que OPCAO contribui ±1 e aparece no `mix`. |
| **Mismatch de unidade no líquido por produto** | D-705a: o "líquido" pode somar contratos (FUTURO) com nocional em moeda (NDF). Aceito no MVP (produto costuma ter tipo dominante); a resposta expõe `mix` e `unidade_mista=true` para o gestor saber o que está somado. Não inventar normalização de unidade (fora do MVP). |
| **Status corrente ≠ status na data (RN-016/019 "as of")** | D-706: relatórios de datas antigas usam status atual; reconstrução histórica fora do MVP. Documentado; aceitável pois reprocesso de datas antigas é raro (§12.6). |
| **`variacao_dia` mistura preço e câmbio** | Limitação herdada (RN-015/crítica §3): o relatório **lê** o número já consolidado; decomposição preço×câmbio é fora do MVP. Registrado. |
| **CSV/Excel — injeção de fórmula (CWE-1236)** | `ExportadorCsv` reusa a lista de prefixos perigosos do importador (Fase 4) e prefixa aspa; teste com célula iniciando em `=`/`+`/`-`/`@`. |
| **`formato=pdf` quebra cliente que espera 200** | Contrato §5.2.5 lista `pdf`; D-707 responde `501 Not Implemented` **no envelope §5.1** (erro tipado) — código que acusa gap do **servidor**, não do cliente (não 422); degradação previsível e documentada até a fase de entrega. |
| **Precisão `float` nas somas** | D-MVC-2 mantido; somas no banco (`SUM`) e `(float)` na borda. Arredondamento de exibição na UI/Resource; BCMath/Money fora do MVP — risco aceito. |
| **`/` (Dashboard) colide com a home atual** | Verificar a rota raiz existente antes de sobrescrever; se houver welcome/redirect, apontar para o Dashboard sob `auth` (login → dashboard, §6.2). |

## 9. Referências

- `specs/requisitos.md` — §5.2.5 (API + `formato`), §7.4 (RN-016..019), §6.1 (telas 2/8/9/10),
  §6.2 (ciclo diário), §9.1 (relatórios < 5 s), §5.1 (envelope de erro).
- `specs/passos_dev.md` — Fase 7 (Relatórios) e Apêndice de rastreabilidade (RN-016..019 → Fase 7).
- `specs/spec_parte_6.md` — motor que popula `mtm_diario` (consumido aqui); moldes de Service/DTO/HTTP.
- `specs/spec_parte_4.md` / `app/Support/Csv/ImportadorPrecosCsv.php` — endurecimento CWE-1236 reusado no CSV.
- `specs/future/pontos_de_atencao.md` — críticas (replay/performance, float, `variacao_dia` suja,
  quantidade de OPCAO/NDF) reafirmadas como fora do MVP / fases futuras.
- `mock_telas/screens.jsx` (`DashboardScreen`) e `screens3.jsx` (`RelPosicaoAberta/RelPL/RelExposicao`) — UI de referência.
- `CLAUDE.md` — arquitetura *fat model*, comandos Docker/teste, "nunca commite como Claude".

---
**Fim do documento.** Próxima etapa: **Fase 8 — Seed & dados de demonstração** (`passos_dev.md`),
que monta um portfólio realista com histórico de MtM (produto → preço → posição → movimentação →
motor) para tornar estes 4 relatórios "ricos" em desenvolvimento, demonstração e UAT.
