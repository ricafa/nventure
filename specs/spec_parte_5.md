# Spec — Parte 5: Módulo Posições (Parte 7)

> **Equivale à Fase 5 do `passos_dev.md`.** Estreia o módulo de Posições, permitindo o cadastro dos 4 instrumentos (FUTURO, NDF, OPCAO, OTC) e a orquestração transacional de movimentações de FUTURO (aumento, redução, redução total).
>
> **Fonte da verdade:** `specs/requisitos.md` (v1.7) — §3.2.3–3.2.8, §4.2–4.3, §5.2.3, §6.1/6.3/6.4, §7.1, §7.1a. Roteiro: `specs/passos_dev.md` (Fase 5). Em divergência, `requisitos.md` prevalece.
>
> **Natureza:** especificação executável — descreve **o que entregar**, as **decisões fixadas** e os critérios de aceite (DoD). **Não** altera regras de negócio, modelo de dados nem contratos de API.
>
> **Revisão (contraponto):** esta versão incorpora o parecer de `specs/spec_parte_5_contraponto.md` (que arbitrou `specs/spec_parte_5_critica.md`): abolição do cache de `preco_medio` (coluna inexistente), preenchimento de `criado_por`, especificação do `criarFuturo` transacional e da ação `encerrar`, recarga da relação após o `INSERT`, retorno do estado recalculado e endurecimento da comparação de quantidade em ponto flutuante.

## 0. Decisões desta parte (fixadas)

| # | Tema | Decisão |
|---|---|---|
| **D-501** | Transação e Lock Pessimista | `ServicoMovimentacoes::movimentarFuturo` opera sob `DB::transaction` e captura `Posicao::lockForUpdate()` antes de validar a RN-022 e recomputar o estado via `replay()`. Evita corrida na RN-022 (redução excedente). |
| **D-502** | Deleção vs Encerramento | `DELETE /posicoes/{id}` deleta a posição **somente** se `mtmDiarios()->exists()` for falso. Se já foi processada pelo motor, devolve `409 Conflict`. O fluxo correto pós-MtM passa a ser a ação **Encerrar** (D-507). |
| **D-503** | Unicidade da `ABERTURA` (`uq_mov_abertura`) | O `INSERT` da movimentação inicial (`ABERTURA`) em `criarFuturo` fica em `try/catch QueryException`. Se violar o `uq_mov_abertura` (SQLSTATE 23505), lança `ErroConflito` (409). O índice único parcial garante **exatamente uma `ABERTURA` por posição** (RN-020) e impede uma segunda abertura por reprocessamento — é defesa em profundidade barata, não tratamento de uma corrida na criação (o `posicao_id` é recém-gerado na mesma transação). |
| **D-504** | Consolidação de `quantidade`/`status` (sem cache de PM) | `ServicoMovimentacoes` consolida **apenas** `quantidade` e `status` na mãe `Posicao` a cada movimentação (colunas reais, RN-024/RN-022). O **preço médio NÃO é persistido**: `posicao_futuro` não tem coluna `preco_medio` (§3.2.4) e o PM é **derivado** via `Futuro::precoMedio()`/`replay()` (RN-021). Persistir um valor derivado criaria dupla fonte de verdade; uma eventual denormalização de performance é hipótese da Fase 6/12, com migration e teste de consistência `cache⇄replay` próprios (registrada em `pontos_de_atencao.md`). |
| **D-505** | DTOs de Saída | `PosicaoResumo` (listagem, mascara detalhes), `PosicaoDetalhe` (carrega mãe+filha completa) e `EstadoMovimentacao` (estado recalculado pós-movimentação, §5.2.3) blindam a UI/HTTP da forma como os Eloquent Models estruturam as tabelas. |
| **D-506** | Borda de conversão de decimais | O `float` nativo (Alternativa A) é adotado via casts e métodos dos Models. O `Service` obtém os `float` **pelos métodos dos Models** (`precoMedio()`, `quantidadeAtual()`, `plRealizado()`), que já encapsulam `ConverteDecimais` via `self::`. O `Service` **não** chama o trait `ConverteDecimais` estaticamente (não compila — é trait, não classe); na borda usa `round($v, 4)` nativo quando precisa formatar/comparar. |
| **D-507** | Origem de `criado_por` e ação `Encerrar` | `posicao.criado_por` e `posicao_movimentacao.criado_por` são `NOT NULL` sem default: o **Service** injeta o valor em **todo** `create()`, a partir do contexto de autenticação, com **fallback documentado** `Auth::user()?->email ?? 'sistema'` até a Fase 10 endurecer o perfil real (D-402). Os Form Requests **não** aceitam `criado_por` do cliente (anti-spoofing de auditoria, §2.3). A ação `POST /posicoes/{id}/encerrar` é uma transição de status **idempotente** `ABERTA → ENCERRADA`, distinta do encerramento automático do FUTURO por redução total (RN-022) e da `VENCIDA` (RN-014, Fase 6). |
| **D-508** | Validação polimórfica por instrumento | A validação dos 4 payloads usa **regras condicionais por `instrumento`** (`Rule::requiredIf`, `sometimes`) — preferencialmente um `SalvarPosicaoRequest` por rota/`criar*`, evitando uma única classe que "alterna sozinha" todas as RNs e também o over-engineering de abstrações desnecessárias. A matriz **RN × camada** (§4.0) fixa onde cada RN-001..006 é validada; em especial, **RN-006 (indexador do OTC existe) é checagem no Service** (lookup no banco), não Form Request. |

## 1. Objetivo e escopo

**Objetivo:** Permitir o cadastro completo de posições dos 4 instrumentos suportados, além das movimentações específicas para posições `FUTURO`, mantendo consistência com travas transacionais e restrições de deleção.

**Dentro do escopo**
- `app/Services/ServicoPosicoes.php` — Cadastro dos 4 tipos de instrumentos (`criarFuturo`/`criarNdf`/`criarOpcao`/`criarOtc`; RN-001 a RN-006, RN-004a..e, RN-003, RN-006), listagem paginada, exclusão condicionada (D-502) e encerramento (D-507).
- `app/Services/ServicoMovimentacoes.php` — Abertura e movimentações de FUTURO (RN-020 a RN-025), incluindo recálculo derivado de preço médio/realização e transações com lock (D-501/D-504).
- **API REST** §5.2.3 em `app/Http/Controllers/Api/V1/PosicaoController.php`.
- **Telas Livewire**: `/posicoes` (Listagem, Detalhe, modal Movimentar) e `/posicoes/nova`.
- **Form Requests** com validação condicional por instrumento (D-508).
- DTOs `PosicaoResumo`, `PosicaoDetalhe`, `EstadoMovimentacao`.

**Fora do escopo (outras fases)**
- **Motor MtM**: Fica para a Fase 6 (inclui o cálculo de `VENCIDA`, RN-014).
- **Relatórios**: Visões consolidadas ficam para a Fase 7.
- **Autorização por perfil (AuthZ)**: a restrição de GESTOR para `DELETE`/`encerrar` (§9.2) fica para a Fase 10 (D-402). Nesta fase as rotas ficam sob `auth:sanctum` sem distinção de perfil — registrar a ressalva.
- Operações de `ESTORNO` de movimentações. Embora apontado na análise crítica de arquitetura, foge do escopo do MVP e permanece como restrição imutável (RN-025).

## 2. Mapa de arquivos × responsabilidade

| Arquivo | Camada | Responsabilidade |
|---|---|---|
| `app/Services/ServicoPosicoes.php` | aplicação | Cadastro dos 4 instrumentos (`criar*`), listagem paginada, deleção segura (D-502), encerramento (D-507). RN-006 (lookup de indexador). |
| `app/Services/ServicoMovimentacoes.php` | aplicação | Movimentações de FUTURO, RN-021..RN-025, travas (D-501), consolidação `quantidade`/`status` (D-504) e idempotência da ABERTURA (D-503). |
| `app/Services/Dados/PosicaoResumo.php` | DTO | Representação simplificada para listagem na UI. |
| `app/Services/Dados/PosicaoDetalhe.php` | DTO | Agregação completa de Posição + Filhas para visualização. |
| `app/Services/Dados/EstadoMovimentacao.php` | DTO | Estado recalculado retornado por `POST /movimentacoes` (§5.2.3). |
| `app/Http/Controllers/Api/V1/PosicaoController.php` | HTTP | Endpoints REST da §5.2.3. |
| `app/Http/Requests/*PosicaoRequest.php` | HTTP | `SalvarFuturoRequest`/`SalvarNdfRequest`/`SalvarOpcaoRequest`/`SalvarOtcRequest` (ou um `SalvarPosicaoRequest` com regras condicionais por `instrumento`, D-508) e `MovimentarFuturoRequest`. **Não** aceitam `criado_por`. |
| `app/Http/Resources/PosicaoResource.php` | HTTP | Serialização em API JSON. |
| `app/Livewire/Posicoes/*` | UI | Telas Livewire de Listagem, Detalhe e Nova Posição. |

## 3. Pré-requisitos

- Fases 1-4 concluídas e código verde.
- Modelos M criados (`Posicao`, filhas, `Movimentacao`, `Perna`), e o trait de cálculo (`ReproduzMovimentacoes`/`ConverteDecimais`) implementado e testado.
- Exceções `ErroConflito`, `ErroValidacao`, `ErroNaoEncontrado` operantes e capturadas pelo envelope de erros (`bootstrap/app.php`, §5.1).

## 4. Passo a passo

### 4.0 Cadastro dos 4 instrumentos (RN-001..006) — objetivo central

O cadastro vive em `ServicoPosicoes`. A validação estrutural fica no Form Request (por instrumento, D-508) e as RNs que dependem do banco ficam no Service. Matriz RN × camada:

| RN | Regra | Camada |
|---|---|---|
| RN-001 | `quantidade > 0` (FUTURO/NDF/OTC; OPCAO mãe é fixada em 1) | Form Request |
| RN-002 | `data_vencimento > data_entrada` | Form Request |
| RN-003 | `mercado = BALCAO` exige `contraparte`; `BOLSA` não exige | Form Request (condicional ao `mercado`) |
| RN-004 | cada perna: `strike > 0`, `premio_pago >= 0` | Form Request (`pernas.*`) |
| RN-004a | estrutura de opção com ≥ 1 perna | Form Request (`pernas` array `min:1`) |
| RN-004b | sem máximo de pernas; suportar ≥ 4 (butterfly/condor) | Form Request (sem `max`) |
| RN-004c | cada perna tem `quantidade` e `lado` próprios | Form Request + persistência das pernas |
| RN-004e | OPCAO: mãe com `quantidade = 1`, `lado` informativo | Form Request (força `quantidade=1`) / Service |
| RN-005 | NDF: `valor_nocional > 0` | Form Request |
| **RN-006** | **OTC: `indexador` corresponde a um produto cadastrado** | **Service (lookup no banco)** |
| RN-020 | cadastro de FUTURO cria `ABERTURA` automática na mesma transação | Service (`criarFuturo`, D-503) |

**`criarFuturo` (transação mãe → filha → `ABERTURA`, RN-020):**

```php
public function criarFuturo(array $dados, ServicoMovimentacoes $mov): Posicao
{
    return DB::transaction(function () use ($dados, $mov) {
        $criadoPor = $this->criadoPor();   // D-507; nunca vem do cliente

        $posicao = Posicao::query()->create([
            'produto_id'     => $dados['produto_id'],
            'instrumento'    => 'FUTURO',
            'mercado'        => $dados['mercado'],
            'lado'           => $dados['lado'],
            'quantidade'     => $dados['quantidade'],
            'data_entrada'   => $dados['data_entrada'],
            'data_vencimento'=> $dados['data_vencimento'],
            'contraparte'    => $dados['contraparte'] ?? null,
            'observacoes'    => $dados['observacoes'] ?? null,
            'criado_por'     => $criadoPor,
        ]);

        $posicao->futuro()->create([
            'preco_entrada'   => $dados['preco_entrada'],
            'codigo_contrato' => $dados['codigo_contrato'],
        ]);

        // RN-020: ABERTURA automática, mesma transação, data_movimentacao = data_entrada.
        $mov->criarAbertura($posicao, [
            'data_movimentacao' => $dados['data_entrada'],
            'quantidade'        => $dados['quantidade'],
            'preco'             => $dados['preco_entrada'],
            'criado_por'        => $criadoPor,
        ]);

        return $posicao->load('futuro', 'movimentacoes');
    });
}

/** Origem do autor da auditoria. Fase 10 endurece com perfil real (D-402/D-507). */
private function criadoPor(): string
{
    return Auth::user()?->email ?? 'sistema';
}
```

`criarNdf`, `criarOpcao` (persiste a mãe `posicao_opcao` + N `posicao_opcao_perna`) e `criarOtc` seguem o mesmo molde transacional, **sempre injetando `criado_por`**. NDF/OPCAO/OTC **não** geram movimentações (RN-020). Em `criarOtc`, antes de persistir, validar **RN-006** consultando o produto correspondente ao `indexador`:

```php
// RN-006 — Service, não Form Request: exige lookup no banco.
if (! Produto::query()->where('nome', $dados['indexador'])->exists()) {
    throw new ErroValidacao('Indexador não corresponde a um produto cadastrado.');
}
```

### 4.1 Movimentações de FUTURO — integridade transacional (D-501/D-504)

`movimentarFuturo` valida a RN-022 **antes** do `INSERT` (sob lock), insere, **recarrega** `movimentacoes` para o `replay()` enxergar a nova linha (correção do estado estagnado), consolida `quantidade`/`status` e **retorna o estado recalculado** (`EstadoMovimentacao`, §5.2.3) — não a `Movimentacao` crua.

```php
namespace App\Services;

use App\Exceptions\{ErroConflito, ErroValidacao};
use App\Models\{Posicao, Futuro};
use App\Services\Dados\EstadoMovimentacao;
use Illuminate\Support\Facades\{Auth, DB};
use Illuminate\Database\QueryException;

class ServicoMovimentacoes
{
    private const EPSILON = 1e-4; // casa com NUMERIC(18,4)

    public function movimentarFuturo(int $posicaoId, array $dados): EstadoMovimentacao
    {
        return DB::transaction(function () use ($posicaoId, $dados) {
            /** @var Futuro $posicao */
            $posicao = Posicao::query()->lockForUpdate()->findOrFail($posicaoId);

            // 409: só FUTURO ABERTA aceita movimentação (§5.2.3).
            if ($posicao->instrumento !== 'FUTURO' || $posicao->status !== 'ABERTA') {
                throw new ErroConflito('Apenas posições FUTURO abertas podem ser movimentadas.');
            }

            // RN-025: data_movimentacao >= data_entrada (422).
            if ($dados['data_movimentacao'] < $posicao->data_entrada->format('Y-m-d')) {
                throw new ErroValidacao('Data da movimentação anterior à data de entrada.');
            }

            // Estado atual sob lock (fonte única: posicao.quantidade consolidada, RN-024).
            $posicao->load('movimentacoes', 'futuro');
            $saldo = round($posicao->quantidadeAtual(), 4);

            // RN-022: redução > saldo é rejeitada ANTES do INSERT (422) — sem inversão de lado.
            if ($dados['tipo'] === 'REDUCAO' && round($dados['quantidade'], 4) - $saldo > self::EPSILON) {
                throw new ErroValidacao('Redução superior à quantidade atual.');
            }

            $posicao->movimentacoes()->create($dados + ['criado_por' => $this->criadoPor()]);

            // A-3: recarregar para o replay incluir a movimentação recém-criada.
            $posicao->load('movimentacoes');

            $qtd = round($posicao->quantidadeAtual(), 4);
            // RN-022: redução total encerra a posição (epsilon evita "fechamento zumbi").
            $status = $qtd <= self::EPSILON ? 'ENCERRADA' : 'ABERTA';

            // D-504/RN-024: consolida APENAS quantidade/status (colunas reais).
            // preco_medio NÃO é persistido — é derivado por replay() (RN-021).
            $posicao->update(['quantidade' => $qtd, 'status' => $status]);

            return new EstadoMovimentacao(
                posicaoId:       $posicao->id,
                quantidadeAtual: $qtd,
                precoMedio:      $posicao->precoMedio(),   // float vindo do Model (D-506)
                plRealizado:     $posicao->plRealizado(),
                status:          $status,
            );
        });
    }

    public function criarAbertura(Posicao $posicao, array $dadosAbertura): void
    {
        try {
            $posicao->movimentacoes()->create($dadosAbertura + ['tipo' => 'ABERTURA']);
        } catch (QueryException $e) {
            // D-503: uq_mov_abertura garante UMA abertura por posição; 23505 -> 409.
            if ($e->getCode() === '23505') {
                throw new ErroConflito('Movimentação de ABERTURA já existente para esta posição.');
            }
            throw $e;
        }
    }

    private function criadoPor(): string
    {
        return Auth::user()?->email ?? 'sistema';
    }
}
```

> **Notas de correção:** (1) o PM **nunca** é gravado (B-1: `posicao_futuro` não tem `preco_medio`); (2) a RN-022 é checada **antes** do `INSERT`, sob lock, e o `replay()` roda sobre a relação **recarregada**; (3) comparações de quantidade usam `round(...,4)` + `EPSILON` (`1e-4`), tendo `posicao.quantidade` consolidada como fonte única; (4) o `Service` obtém `float` pelos **métodos do Model** — não chama o trait `ConverteDecimais` (D-506).

### 4.2 Deleção vs Encerramento (D-502/D-507)

`DELETE` só opera se a posição estiver "virgem" (sem MtM). Pós-MtM, o caminho é `encerrar`.

```php
namespace App\Services;

use App\Models\Posicao;
use App\Exceptions\ErroConflito;
use Illuminate\Support\Facades\DB;

class ServicoPosicoes
{
    public function remover(int $id): void
    {
        $posicao = Posicao::query()->findOrFail($id);

        if ($posicao->mtmDiarios()->exists()) {            // D-502
            throw new ErroConflito('Posição já possui registro de MtM. Utilize o encerramento em vez de deletar.');
        }

        $posicao->delete(); // cascata apaga pernas/movimentações/filhas
    }

    /**
     * Encerramento manual (D-507) — idempotente. Para NDF/OPCAO/OTC (sem movimentações)
     * e para FUTURO sem redução total. Distinto do encerramento automático por RN-022 e
     * da VENCIDA (RN-014, Fase 6). MtM existente NÃO bloqueia (ao contrário do DELETE).
     */
    public function encerrar(int $id): Posicao
    {
        return DB::transaction(function () use ($id) {
            $posicao = Posicao::query()->lockForUpdate()->findOrFail($id);

            if ($posicao->status === 'ENCERRADA') {
                return $posicao;                            // idempotente
            }
            if ($posicao->status !== 'ABERTA') {            // ex.: VENCIDA
                throw new ErroConflito('Apenas posições ABERTAS podem ser encerradas.');
            }

            $posicao->update(['status' => 'ENCERRADA']);

            return $posicao;
        });
    }
}
```

### 4.3 Endpoints (§5.2.3)

O `PosicaoController` expõe as rotas abaixo sob `auth:sanctum` (sem AuthZ por perfil nesta fase). `GET /posicoes` nasce **paginado** (50/página, §9.1) para não quebrar o contrato depois.

```
GET    /api/v1/posicoes?status=&produto_id=   Lista (paginada)
GET    /api/v1/posicoes/{id}                  Detalhe (com dados do tipo)
POST   /api/v1/posicoes/futuro                Cria FUTURO (+ ABERTURA, RN-020)
POST   /api/v1/posicoes/ndf                   Cria NDF
POST   /api/v1/posicoes/opcao                 Cria OPCAO (com pernas[])
POST   /api/v1/posicoes/otc                   Cria OTC (RN-006 no Service)
POST   /api/v1/posicoes/{id}/encerrar         Encerra (ação, D-507)
DELETE /api/v1/posicoes/{id}                  Remove (somente sem MtM, D-502)
GET    /api/v1/posicoes/{id}/movimentacoes    Lista movimentações (FUTURO)
POST   /api/v1/posicoes/{id}/movimentacoes    AUMENTO/REDUCAO -> EstadoMovimentacao
```

## 5. Estrutura esperada após a Parte 5

```
app/
├── Http/
│   ├── Controllers/Api/V1/PosicaoController.php    (novo)
│   ├── Requests/
│   │   ├── SalvarFuturoRequest.php                 (novo)
│   │   ├── SalvarNdfRequest.php                    (novo)
│   │   ├── SalvarOpcaoRequest.php                  (novo)
│   │   ├── SalvarOtcRequest.php                    (novo)
│   │   └── MovimentarFuturoRequest.php             (novo)
│   └── Resources/PosicaoResource.php               (novo)
├── Livewire/
│   ├── Posicoes/ListaPosicoes.php                  (novo)
│   ├── Posicoes/DetalhePosicao.php                 (novo)
│   └── Posicoes/FormNovaPosicao.php                (novo)
├── Services/
│   ├── ServicoPosicoes.php                         (novo)
│   ├── ServicoMovimentacoes.php                    (novo)
│   └── Dados/
│       ├── PosicaoResumo.php                       (novo)
│       ├── PosicaoDetalhe.php                      (novo)
│       └── EstadoMovimentacao.php                  (novo)
```

> Se a validação condicional por instrumento (D-508) for resolvida em uma única classe `SalvarPosicaoRequest`, ela substitui os quatro `Salvar*Request.php` — a escolha (split vs. condicional) fica a critério do implementador, recomendando-se a condicional para reduzir superfície.

## 6. Arquivos a entregar (checklist)

- [ ] `ServicoPosicoes.php` com `criarFuturo` (transação mãe+filha+`ABERTURA`, RN-020), `criarNdf`/`criarOpcao`/`criarOtc` (RN-006 no Service), `listar` paginado, `remover` (D-502) e `encerrar` (D-507) — **todo `create()` injeta `criado_por`** (D-507).
- [ ] `ServicoMovimentacoes.php`: `DB::transaction` com `lockForUpdate`, RN-022 validada antes do `INSERT`, recarga de `movimentacoes` antes do `replay()`, consolidação de `quantidade`/`status` (sem `preco_medio`), catch de `23505` na abertura, retorno de `EstadoMovimentacao`.
- [ ] DTOs `PosicaoResumo`, `PosicaoDetalhe` e `EstadoMovimentacao`.
- [ ] `PosicaoResource.php` e Form Requests por instrumento (validação condicional, D-508; sem `criado_por`).
- [ ] Rotas `/api/v1/posicoes` em `routes/api.php` — os **10 endpoints** da §4.3 (lista, detalhe, 4 `POST` de criação, `encerrar`, `DELETE`, `GET`/`POST` de movimentações).
- [ ] Telas Livewire de cadastro de posições e grid com detalhe de movimentação.
- [ ] Feature tests cobrindo RN-001..006 e RN-020..025.
- [ ] **Teste que falharia hoje**: cadastro/movimentação preenchem `criado_por` (`NOT NULL`); ausência → falha controlada, não `500`.
- [ ] Feature test de "duas movimentações na mesma sequência" (valida a recarga pós-`INSERT`, A-3).
- [ ] Feature test de "Redução concorrente" validando o `lockForUpdate` na tabela `posicao`.
- [ ] `phpstan` nível 8 rodando liso e `pint --test` verificado.

## 7. Definition of Done (critérios de aceite)

1. Os 10 endpoints da §4.3 respondem (listagem paginada, detalhe, 4 cadastros, `encerrar`, `DELETE` com `409` quando devido, `GET`/`POST` de movimentações), com `Resources` formatados.
2. Fluxo de `FUTURO`: múltiplas movimentações refletem corretamente na `quantidade` consolidada (sem desativar a imutabilidade das movimentações) e em sequência na **mesma** transação (A-3).
3. `POST /movimentacoes` devolve o **estado recalculado** (`EstadoMovimentacao`: `quantidade_atual`, `preco_medio`, `pl_realizado`, `status`), conforme §5.2.3.
4. Redução total altera o `status` para `ENCERRADA` automaticamente (RN-022); `encerrar` faz a transição manual idempotente `ABERTA → ENCERRADA` (D-507).
5. RNs críticas (abertura dupla, redução excedente, data retroativa) barram e devolvem `ErroConflito`/`ErroValidacao` **sem** expor `QueryException`/`500`.
6. Nenhum `create()` de `posicao`/`posicao_movimentacao` falha por `criado_por` ausente (D-507).
7. Tela de Posições Livewire funcionando com formulários adequados por instrumento.

## 8. Riscos e pontos a verificar

| Risco | Mitigação / ação |
|---|---|
| **`criado_por` (`NOT NULL`) não preenchido** | Service injeta `criado_por` em todo `create()` (D-507), com fallback `Auth::user()?->email ?? 'sistema'` até a Fase 10. Form Requests não aceitam o campo. Teste que falha hoje trava a regressão. |
| **Armadilha do Float / "fechamento zumbi"** | Comparações de quantidade usam `round(...,4)` + `EPSILON = 1e-4` (casando com `NUMERIC(18,4)`), tendo `posicao.quantidade` consolidada (RN-024) como fonte única na decisão de encerrar/rejeitar (RN-022). |
| **Estado consolidado incorreto (replay estagnado)** | Após o `INSERT`, recarregar `movimentacoes` antes do `replay()`; validar RN-022 **antes** do `create`, sob lock (A-3). |
| **Dupla fonte de verdade no PM** | O `preco_medio` **não** é persistido (D-504): permanece derivado por `replay()`. Denormalização de performance só na Fase 6/12 com migration e teste `cache⇄replay` (`pontos_de_atencao.md`). |
| **UX de Estorno / Deleção Imutável** | RN-025 trava edições. Antes do MtM, corrige-se apagando e recriando (D-502 permite `DELETE` sem `mtmDiarios`). Pós-MtM, usa-se `encerrar`; correção de dado exige suporte até "Movimentação de Estorno" (fora de escopo, Parte 5). |

## 9. Referências

- `specs/requisitos.md` (§3.2.3–3.2.8, §4.2–4.3, §5.2.3, §7.1, §7.1a, §9.1/§9.2).
- `specs/passos_dev.md` (Fase 5 — Módulo Posições).
- `specs/spec_parte_5_critica.md` e `specs/spec_parte_5_contraponto.md` (revisão incorporada).
- `specs/future/pontos_de_atencao.md` (parecer de arquitetura; hipótese de denormalização do motor).
- `specs/spec_parte_4.md` (moldes e estruturação de Services HTTP).

---
**Fim do documento.** Próxima etapa: **Fase 6 — Motor MtM** (`passos_dev.md`), onde o cálculo polimórfico entrará em ação iterando sobre estas posições criadas (incluindo o cálculo de `VENCIDA`, RN-014).
