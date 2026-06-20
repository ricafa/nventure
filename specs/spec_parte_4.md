# Spec — Parte 4: Módulo Produtos & Preços (Service + API + Livewire + CSV)

> **Equivale à Fase 4 do `passos_dev.md`.** Estreia a **camada de aplicação** sobre o *fat
> model* já pronto: serviços (`ServicoProdutos`, `ServicoPrecos`), a **primeira fatia da API
> REST** (`/api/v1/produtos`, `/api/v1/precos`), as telas Livewire de cadastro de produto e
> lançamento/upload de preço, e o importador de CSV através da interface **`FontePrecos`** —
> único ponto de extensão de ingestão que sobrevive como interface (CLAUDE.md / requisitos §4).
>
> **Fonte da verdade:** `specs/requisitos.md` (v1.6) — §5.2.1/§5.2.2 (contratos de API),
> §3.2.1/§3.2.2 (`produto`/`preco_referencia`), §5.1 (envelope de erro), §7.2 (RN-007..010a),
> §6.1 (telas 3 e 4), §9.2 (perfis). Roteiro: `specs/passos_dev.md` (Fase 4). Em divergência,
> `requisitos.md` prevalece.
>
> **Natureza:** especificação executável — descreve **o que entregar** (serviços, endpoints,
> telas, importador, DTO, Form Requests/Resources), as **decisões fixadas** e os critérios de
> aceite (DoD). **Não** altera regras de negócio, modelo de dados nem os contratos de API
> (definidos em `requisitos.md`); **não** altera os Models/traits (Fases 2–3). A **autorização
> por perfil** (Policies/Gates) é da **Fase 10** — aqui as rotas exigem apenas autenticação.

---

## 0. Decisões desta parte (fixadas)

| # | Tema | Decisão |
|---|---|---|
| **D-401** | Fundação da API REST | A Parte 4 é a **primeira** a expor `/api/v1`. Registrar o grupo `api` em `bootstrap/app.php` (`api: __DIR__.'/../routes/api.php'`, `apiPrefix: 'api/v1'`) e criar `routes/api.php`. Controllers REST em `app/Http/Controllers/Api/V1/`. No MVP só existe a versão `v1`. |
| **D-402** | Autenticação ≠ autorização | Endpoints sob `auth:sanctum` (**autenticação**). A **autorização por perfil** (OPERADOR/GESTOR/ADMIN, §9.2) é da **Fase 10** — a Parte 4 **não** restringe por perfil. Feature tests autenticam com `Sanctum::actingAs(Usuario::factory()->create())`. Telas Livewire sob middleware `auth` (sessão web). |
| **D-403** | RNs no Service (D-607) | Os **Form Requests** fazem só validação **estrutural** (presença, tipo, formato ISO, enum). As **RNs** (RN-007 unicidade, RN-006/FK existência, RN-010a bloqueio de exclusão) vivem no **Service** e lançam `ErroAplicacao`. Positividade (RN-008/009) é autoritativa no Service e **espelhada** no Form Request (UX) — defesa em profundidade; o UNIQUE/CHECK do banco (§3) é o backstop. |
| **D-404** | Dois formatos de 422 | Validação **estrutural** (Form Request) → 422 **nativo** do Laravel (`{message, errors}`, field-level). Regra de **negócio** (Service) → **envelope §5.1** (`{erro, mensagem}`) via `ErroValidacao`. Conflitos (RN-007, RN-010a) → **409** (`ErroConflito`); inexistente → **404** (`ErroNaoEncontrado`). Unificar os dois 422 fica como candidato da Fase 11. |
| **D-405** | Soft delete por `ativo` | `DELETE /produtos/{id}` faz `ativo = false` (**não** Eloquent `SoftDeletes` — não há `deleted_at` no §3.2.1). Produto inativo some das opções de novas posições mas permanece para histórico/relatórios. Operação **idempotente** (inativar já inativo → 200/204). |
| **D-406** | `FontePrecos` = único ponto de extensão | Interface `app/Support/Csv/FontePrecos.php`; `ImportadorPrecosCsv` a implementa. `ServicoPrecos::importar(FontePrecos $fonte)` depende da **interface** — trocar a fonte (XLSX, feed) é uma nova implementação sem tocar o Service. A **fonte** faz parsing + segurança + tipagem; o **Service** aplica RNs + persiste + monta o relatório. |
| **D-407** | Segurança do CSV (CWE-1236 + limites) | O importador: (a) **rejeita** células iniciadas por `=` `+` `-` `@` `TAB` `CR` (anti-formula-injection), com a checagem aplicada **após `trim`** para não deixar passar `" =SOMA()"` (A-4); para as 4 colunas tipadas a defesa primária é a própria **tipagem** — a regra de prefixo é defesa em profundidade redundante, e o controle **autoritativo** de CWE-1236 é na **geração** de CSV (Fase 7); (b) exige **cabeçalho exato** `produto_id,data_preco,preco_fechamento,cambio_brl`, **removendo BOM** UTF-8 antes de comparar (A-3); (c) **tipa estritamente** (int / date ISO `YYYY-MM-DD`); o **decimal é arredondado à escala da coluna (6 casas)** — coerção explícita, **não** rejeição (M-1); (d) **limita** tamanho (≤ 2 MB) e linhas (≤ 5.000); (e) faz **streaming** (`SplFileObject`), sem carregar tudo em memória. Linha inválida vira **rejeição** no relatório (RN-010), nunca exceção que aborta o lote. |
| **D-408** | Duplicata no CSV é rejeição, não upsert | Linha cujo (`produto_id`,`data_preco`) já existe é **rejeitada** com motivo (RN-007), preservando a auditoria do preço já lançado. Diferente da idempotência do **motor** (Fase 6, upsert por design): preço é dado de entrada do operador, não recálculo. Para corrigir, remove-se (se não referenciado — RN-010a) e relança. |
| **D-409** | DTO `ResultadoImportacao` | Em `app/Services/Dados/`. Conta **aceitas** e lista **rejeitadas** (`{linha, motivo}`). Serializado na resposta de `POST /precos/upload` e exibido na tela de preços. |
| **D-410** | Singletons + Facades | Registrar `ServicoProdutos`/`ServicoPrecos` como `singleton` no `AppServiceProvider` (região reservada da Fase 1). Facades `Produtos`/`Precos` em `app/Facades/` são **opcionais** — controllers preferem **injeção por construtor** (CLAUDE.md). |
| **D-411** | Locale do CSV (Excel pt-BR) | Mantém-se o formato **canônico** de `requisitos.md` §5.2.2 (delimitador `,`, decimal `.`), mas o importador **também aceita** CSV exportado do Excel pt-BR: detecta o **delimitador** (`,` ou `;`) pela 1ª linha e, quando `;`, normaliza o **decimal** `,`→`.` nas colunas numéricas; remove **BOM** UTF-8 do cabeçalho (A-3). É extensão **tolerante** (superset) — **não** altera o contrato §5.2.2. A tela/template oferece o CSV-modelo no formato canônico. |
| **D-412** | UNIQUE é o caminho primário do 409 | `criar`/`atualizar` de produto (nome único) e o lançamento de preço (RN-007) envolvem o `INSERT` em `try/catch QueryException`; no SQLSTATE `23505` (unique_violation do Postgres) traduzem para `ErroConflito` (409). O pré-`SELECT` permanece como **atalho de UX**, mas é o `catch` que fecha a *race condition* SELECT-então-INSERT que, sem ele, vazaria como **500** (A-1). |

---

## 1. Objetivo e escopo

**Objetivo.** Ao final, um operador consegue **cadastrar/editar/inativar produtos** e
**lançar preços** (manual e por **upload CSV**) — pela **API REST** e pelas **telas Livewire** —
com as regras RN-007..010a aplicadas no Service, erros no envelope §5.1, e o CSV processando
linhas válidas e reportando as rejeitadas (RN-010) com endurecimento de segurança (D-407).

**Dentro do escopo (Parte 4)**
- `app/Services/ServicoProdutos.php` — CRUD + inativação (RN-005 não se aplica aqui; soft delete D-405).
- `app/Services/ServicoPrecos.php` — lançamento manual, listagem filtrada, remoção (RN-010a) e
  **importação** via `FontePrecos` (RN-007/008/009/010).
- `app/Support/Csv/FontePrecos.php` (interface) + `app/Support/Csv/ImportadorPrecosCsv.php`
  (implementação, segurança D-407).
- `app/Services/Dados/ResultadoImportacao.php` (DTO, D-409).
- **API REST** §5.2.1 (produtos) e §5.2.2 (preços) em `app/Http/Controllers/Api/V1/` +
  `routes/api.php` + roteamento `api:` em `bootstrap/app.php` (D-401).
- **Form Requests** (validação estrutural) + **Resources** (serialização §5.1).
- **Telas Livewire** (§6.1): cadastro de produtos (CRUD em tabela) e lançamento de preços
  (form manual + área de upload CSV com relatório aceitas/rejeitadas).
- **Feature tests** do módulo (API + Livewire + importador), autenticados via Sanctum (D-402).

**Fora do escopo (outras fases)**
- **Autorização por perfil** (Policies/Gates OPERADOR/GESTOR/ADMIN) e emissão de **tokens**
  Sanctum → **Fase 10**. Aqui só `auth:sanctum` (autenticação).
- **Posições** e movimentações → **Fase 5**; **motor** e idempotência de MtM → **Fase 6**;
  **relatórios** (incl. export CSV/PDF que reaproveita o endurecimento) → **Fase 7**.
- **Seeders de demonstração** → **Fase 8** (aqui só o necessário para os testes, via factories).
- Cálculo de MtM (já entregue nas Fases 2–3) — **inalterado**.

---

## 2. Mapa de arquivos × responsabilidade

| Arquivo | Camada | Responsabilidade |
|---|---|---|
| `bootstrap/app.php` | infra | **Editado**: registra `api:` + `apiPrefix: 'api/v1'` (D-401). Envelope §5.1 já existe (D-605). |
| `routes/api.php` | infra | **Novo**: rotas §5.2.1/§5.2.2 sob `auth:sanctum` (D-402). |
| `app/Providers/AppServiceProvider.php` | infra | **Editado**: `singleton` de `ServicoProdutos`/`ServicoPrecos` (D-410). |
| `app/Services/ServicoProdutos.php` | aplicação | CRUD de produto; inativação (D-405); RNs de produto. |
| `app/Services/ServicoPrecos.php` | aplicação | Lançamento/listagem/remoção de preço; `importar(FontePrecos)`; RN-007..010a. |
| `app/Services/Dados/ResultadoImportacao.php` | DTO | Relatório aceitas/rejeitadas (D-409). |
| `app/Support/Csv/FontePrecos.php` | contrato | Interface de ingestão (D-406). |
| `app/Support/Csv/ImportadorPrecosCsv.php` | suporte | Parsing + segurança CSV (D-407); implementa `FontePrecos`. |
| `app/Http/Controllers/Api/V1/ProdutoController.php` | HTTP | Endpoints §5.2.1; injeta `ServicoProdutos`. |
| `app/Http/Controllers/Api/V1/PrecoController.php` | HTTP | Endpoints §5.2.2 (incl. `upload`); injeta `ServicoPrecos`. |
| `app/Http/Requests/*` | HTTP | Validação estrutural (D-403): `SalvarProdutoRequest`, `SalvarPrecoRequest`, `UploadPrecosRequest`, filtros. |
| `app/Http/Resources/{Produto,PrecoReferencia}Resource.php` | HTTP | Serialização §5.1. |
| `app/Livewire/Produtos/*` | UI | Tela 3 (CRUD em tabela). |
| `app/Livewire/Precos/*` | UI | Tela 4 (form manual + upload CSV). |
| `app/Facades/{Produtos,Precos}.php` | conveniência | **Opcional** (D-410). |
| `tests/Feature/Produtos/*`, `tests/Feature/Precos/*` | teste | API + Livewire + importador. |
| `tests/Unit/Csv/ImportadorPrecosCsvTest.php` | teste | Segurança/parsing do CSV **sem banco** (D-407). |

---

## 3. Pré-requisitos

- **Fases 1–3 verdes:** esquema migrado (`produto`/`preco_referencia` com UNIQUE
  `(produto_id, data_preco)` e FK), Models `Produto`/`PrecoReferencia`/`MtmDiario`
  implementados, exceções base + envelope §5.1 ligado em `bootstrap/app.php` (D-605),
  `preventLazyLoading` ativo em dev/teste (D-206).
- **Sanctum** instalado (Fase 0). Para autenticar feature tests: `use Laravel\Sanctum\Sanctum;`
  + `Sanctum::actingAs(Usuario::factory()->create())`. A `UsuarioFactory` já existe.
- **Livewire 4 + Flux UI** instalados (Fase 0); telas vivem sob o layout autenticado.
- **`MtmDiario`** já mapeia `preco_ref_id` — necessário para a verificação da RN-010a.

---

## 4. Passo a passo

> Comandos no container (`docker compose exec app …`). Estilo do código: PHP 8.3, `declare`
> implícito do projeto, `protected $guarded = []` nos Models, casts `decimal:`. Na **borda**
> de Services/Importador a conversão para aritmética é `(float)` **explícito** — o trait
> `ConverteDecimais` é dos **Models** (não é chamável de fora deles: chamada estática direta de
> método de trait é *deprecated* e o PHPStan nível 8 acusa). PHPStan nível 8.

### 4.0 Fundação da API REST (D-401)

`bootstrap/app.php` ainda **não** registra rotas de API. Adicionar o grupo e o prefixo,
preservando o `render` do envelope §5.1 já existente:

```php
return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',   // ← novo (D-401)
        apiPrefix: 'api/v1',                 // ← base path §5.1
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    // ->withMiddleware(...) e ->withExceptions(...) inalterados
```

`routes/api.php` (novo) — rotas §5.2.1/§5.2.2 sob autenticação (autorização por perfil é Fase 10):

```php
<?php

use App\Http\Controllers\Api\V1\{ProdutoController, PrecoController};
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function () {
    // §5.2.1 Produtos
    Route::apiResource('produtos', ProdutoController::class);          // index/show/store/update/destroy

    // §5.2.2 Preços
    Route::get('precos', [PrecoController::class, 'index']);
    Route::post('precos', [PrecoController::class, 'store']);
    Route::post('precos/upload', [PrecoController::class, 'upload']);
    Route::delete('precos/{preco}', [PrecoController::class, 'destroy']);
});
```

> `DELETE /produtos/{id}` é `destroy` mas **inativa** (D-405), não remove — o controller chama
> `ServicoProdutos::inativar()`.

### 4.1 `ServicoProdutos` (§5.2.1, D-405/D-410)

CRUD direto em Eloquent (sem repositório — *fat model*). RNs de produto: nome único
(o UNIQUE do banco é o backstop; o serviço traduz a violação para o envelope).

```php
namespace App\Services;

use App\Exceptions\{ErroConflito, ErroNaoEncontrado};
use App\Models\Produto;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;

class ServicoProdutos
{
    /** @return Collection<int, Produto> */
    public function listar(bool $apenasAtivos = false): Collection
    {
        return Produto::query()
            ->when($apenasAtivos, fn ($q) => $q->where('ativo', true))
            ->orderBy('nome')
            ->get();
    }

    public function buscar(int $id): Produto
    {
        return Produto::query()->find($id)
            ?? throw new ErroNaoEncontrado('Produto não encontrado.');
    }

    /** @param array<string, mixed> $dados */
    public function criar(array $dados): Produto
    {
        // Atalho de UX (mensagem amigável); o UNIQUE do banco é o caminho primário (D-412).
        if (Produto::query()->where('nome', $dados['nome'])->exists()) {
            throw new ErroConflito('Já existe um produto com esse nome.');
        }

        try {
            return Produto::query()->create($dados + ['ativo' => true]);
        } catch (QueryException $e) {                       // fecha a race SELECT→INSERT (A-1/D-412)
            $this->traduzConflitoNome($e);
        }
    }

    /** @param array<string, mixed> $dados */
    public function atualizar(int $id, array $dados): Produto
    {
        $produto = $this->buscar($id);

        if (isset($dados['nome'])
            && Produto::query()->where('nome', $dados['nome'])->whereKeyNot($id)->exists()) {
            throw new ErroConflito('Já existe um produto com esse nome.');
        }

        try {
            $produto->update($dados);
        } catch (QueryException $e) {
            $this->traduzConflitoNome($e);
        }

        return $produto;
    }

    /** unique_violation (Postgres SQLSTATE 23505) → 409; demais erros re-lançados. */
    private function traduzConflitoNome(QueryException $e): never
    {
        if ($e->getCode() === '23505') {
            throw new ErroConflito('Já existe um produto com esse nome.');
        }
        throw $e;
    }

    /** Soft delete por `ativo` (D-405) — idempotente. */
    public function inativar(int $id): Produto
    {
        $produto = $this->buscar($id);
        $produto->update(['ativo' => false]);

        return $produto;
    }
}
```

### 4.2 API de produtos (§5.2.1)

Controller fino: injeta o serviço, valida via Form Request, serializa via Resource.

```php
namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\SalvarProdutoRequest;
use App\Http\Resources\ProdutoResource;
use App\Services\ServicoProdutos;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class ProdutoController extends Controller
{
    public function __construct(private readonly ServicoProdutos $servico) {}

    public function index(Request $request): mixed
    {
        return ProdutoResource::collection(
            $this->servico->listar(apenasAtivos: $request->boolean('apenas_ativos')),
        );
    }

    public function show(int $id): ProdutoResource
    {
        return new ProdutoResource($this->servico->buscar($id));
    }

    public function store(SalvarProdutoRequest $request): mixed
    {
        $produto = $this->servico->criar($request->validated());

        return (new ProdutoResource($produto))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    public function update(SalvarProdutoRequest $request, int $id): ProdutoResource
    {
        return new ProdutoResource($this->servico->atualizar($id, $request->validated()));
    }

    public function destroy(int $id): Response
    {
        $this->servico->inativar($id);   // D-405

        return response()->noContent();
    }
}
```

> `SalvarProdutoRequest` valida estrutura: `nome` (string, ≤60), `unidade` (string ≤20),
> `bolsa_ref` (≤20), `moeda_cotacao` (3 letras), `ativo` (boolean, opcional). Como
> `apiResource` mapeia **PUT e PATCH** para `update`, a mesma classe **ramifica as regras por
> método** (`$this->isMethod('POST')` → `required`; caso contrário → `sometimes`) — uma classe
> não alterna sozinha (M-5). Contrato de update é **PATCH-merge** (campos ausentes preservados),
> não PUT-replace. A **unicidade** de nome fica no Service (D-403) para sair no envelope §5.1.

### 4.3 `ServicoPrecos` — lançamento, listagem, remoção (RN-007/008/009/010a)

```php
namespace App\Services;

use App\Exceptions\{ErroConflito, ErroNaoEncontrado, ErroValidacao};
use App\Models\{PrecoReferencia, Produto};
use App\Support\Csv\FontePrecos;
use App\Services\Dados\ResultadoImportacao;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;

class ServicoPrecos
{
    /** @return Collection<int, PrecoReferencia> */
    public function listar(?int $produtoId, ?string $dataInicio, ?string $dataFim): Collection
    {
        return PrecoReferencia::query()
            ->when($produtoId, fn ($q) => $q->where('produto_id', $produtoId))
            ->when($dataInicio, fn ($q) => $q->where('data_preco', '>=', $dataInicio))
            ->when($dataFim, fn ($q) => $q->where('data_preco', '<=', $dataFim))
            ->orderByDesc('data_preco')
            ->get();
    }

    /** @param array<string, mixed> $dados */
    public function lancar(array $dados): PrecoReferencia
    {
        $this->validarRegras($dados);   // RN-007/008/009 (atalho de UX + FK/positividade)

        try {
            return PrecoReferencia::query()->create($dados);
        } catch (QueryException $e) {           // RN-007 sob concorrência (A-1/D-412)
            $this->traduzConflitoData($e);
        }
    }

    /** RN-010a: bloquear remoção de preço já referenciado por mtm_diario → 409. */
    public function remover(int $id): void
    {
        $preco = PrecoReferencia::query()->find($id)
            ?? throw new ErroNaoEncontrado('Preço não encontrado.');

        if ($preco->mtms()->exists()) {
            throw new ErroConflito('Preço já utilizado em cálculo de MtM; não pode ser removido.');
        }

        $preco->delete();
    }

    /** Importa de uma fonte arbitrária (D-406). Linhas inválidas → rejeitadas (RN-010). */
    public function importar(FontePrecos $fonte): ResultadoImportacao
    {
        $resultado = new ResultadoImportacao;

        foreach ($fonte->ler() as $linha) {
            // A fonte já tipou/sanitizou; aqui só as RNs de negócio.
            if ($linha['_erro'] ?? null) {                 // erro de parsing/segurança (D-407)
                $resultado->rejeitar((int) $linha['_linha'], (string) $linha['_erro']);
                continue;
            }
            try {
                $this->validarRegras($linha);
                try {
                    PrecoReferencia::query()->create([
                        'produto_id' => $linha['produto_id'],
                        'data_preco' => $linha['data_preco'],
                        'preco_fechamento' => $linha['preco_fechamento'],
                        'cambio_brl' => $linha['cambio_brl'],
                    ]);
                } catch (QueryException $e) {       // duplicata sob concorrência → ErroConflito
                    $this->traduzConflitoData($e);
                }
                $resultado->aceitar();
            } catch (ErroValidacao|ErroConflito $e) {
                $resultado->rejeitar((int) $linha['_linha'], $e->getMessage());
            }
        }

        return $resultado;
    }

    /** unique_violation (produto_id, data_preco) → 409; demais erros re-lançados. */
    private function traduzConflitoData(QueryException $e): never
    {
        if ($e->getCode() === '23505') {
            throw new ErroConflito('Já existe preço para esse produto nessa data.');
        }
        throw $e;
    }

    /** @param array<string, mixed> $dados */
    private function validarRegras(array $dados): void
    {
        if (! Produto::query()->whereKey($dados['produto_id'])->exists()) {
            throw new ErroValidacao('Produto inexistente.');                    // FK / RN-006-like
        }
        if ((float) $dados['preco_fechamento'] <= 0) {
            throw new ErroValidacao('Preço deve ser maior que zero.');          // RN-008
        }
        if ((float) $dados['cambio_brl'] <= 0) {
            throw new ErroValidacao('Câmbio deve ser maior que zero.');         // RN-009
        }
        $existe = PrecoReferencia::query()
            ->where('produto_id', $dados['produto_id'])
            ->where('data_preco', $dados['data_preco'])
            ->exists();
        if ($existe) {
            throw new ErroConflito('Já existe preço para esse produto nessa data.'); // RN-007 → 409
        }
    }
}
```

> **Relação nova exigida:** `PrecoReferencia::mtms(): HasMany` (FK `preco_ref_id`) para a
> verificação da RN-010a. Adicionar ao Model `PrecoReferencia` (mudança mínima, sem alterar
> cálculo). O cast `decimal:6` devolve string; as comparações `> 0` fazem `(float)` explícito
> (o trait `ConverteDecimais` é dos Models — não é chamável a partir do Service).

### 4.4 `FontePrecos` + `ImportadorPrecosCsv` (D-406)

Interface — o **único** contrato de ingestão que sobrevive (CLAUDE.md):

```php
namespace App\Support\Csv;

interface FontePrecos
{
    /**
     * Lê a fonte e devolve linhas já tipadas/sanitizadas, sem persistir.
     * Cada item: array com chaves de negócio
     *   ['produto_id'=>int, 'data_preco'=>string('Y-m-d'),
     *    'preco_fechamento'=>float, 'cambio_brl'=>float]
     * mais metadados:
     *   ['_linha'=>int, '_erro'=>?string]   // _erro != null ⇒ linha inválida (parsing/segurança)
     *
     * @return iterable<int, array<string, mixed>>
     */
    public function ler(): iterable;
}
```

Implementação CSV com segurança (D-407) e streaming:

```php
namespace App\Support\Csv;

class ImportadorPrecosCsv implements FontePrecos
{
    private const CABECALHO = ['produto_id', 'data_preco', 'preco_fechamento', 'cambio_brl'];
    private const MAX_LINHAS = 5000;
    private const MAX_BYTES = 2_097_152;   // 2 MB
    private const PERIGOSOS = ['=', '+', '-', '@', "\t", "\r"]; // CWE-1236

    public function __construct(private readonly string $caminho) {}

    public function ler(): iterable
    {
        if (filesize($this->caminho) > self::MAX_BYTES) {
            yield ['_linha' => 0, '_erro' => 'Arquivo excede o tamanho máximo (2 MB).'];
            return;
        }

        $arquivo = new \SplFileObject($this->caminho, 'r');

        // Detecta o delimitador pela 1ª linha: aceita ',' (RFC 4180 / §5.2.2 canônico) e
        // ';' (Excel pt-BR). Com ';', o separador decimal vira ',' (normalizado adiante). (D-411)
        $delimitador = $this->detectarDelimitador($arquivo);

        $arquivo->setFlags(\SplFileObject::READ_CSV | \SplFileObject::SKIP_EMPTY | \SplFileObject::DROP_NEW_LINE);
        $arquivo->setCsvControl($delimitador);

        $numero = 0;
        foreach ($arquivo as $colunas) {
            if ($colunas === [null] || $colunas === false) {
                continue;
            }
            $numero++;

            if ($numero === 1) {                                  // cabeçalho exato
                $colunas[0] = $this->removerBom((string) ($colunas[0] ?? ''));   // Excel grava BOM (A-3)
                if (array_map('trim', $colunas) !== self::CABECALHO) {
                    yield ['_linha' => 1, '_erro' => 'Cabeçalho inválido. Esperado: '.implode(',', self::CABECALHO)];
                    return;
                }
                continue;
            }
            if ($numero - 1 > self::MAX_LINHAS) {
                yield ['_linha' => $numero, '_erro' => 'Limite de '.self::MAX_LINHAS.' linhas excedido.'];
                return;
            }

            yield $this->parseLinha($numero, $colunas, $delimitador);
        }
    }

    /** Sniff do delimitador (`,`/`;`) na 1ª linha, sem consumir o ponteiro de leitura. */
    private function detectarDelimitador(\SplFileObject $arquivo): string
    {
        $primeira = (string) $arquivo->fgets();
        $arquivo->rewind();

        return substr_count($primeira, ';') > substr_count($primeira, ',') ? ';' : ',';
    }

    /** Remove o BOM UTF-8 (EF BB BF) que o Excel grava na 1ª célula. */
    private function removerBom(string $valor): string
    {
        return str_starts_with($valor, "\xEF\xBB\xBF") ? substr($valor, 3) : $valor;
    }

    /**
     * @param list<string|null> $c
     * @return array<string, mixed>
     */
    private function parseLinha(int $numero, array $c, string $delimitador): array
    {
        $base = ['_linha' => $numero, '_erro' => null];

        if (count($c) !== 4) {
            return $base + ['_erro' => 'Número de colunas diferente de 4.'];
        }

        [$produtoId, $data, $preco, $cambio] = array_map(fn ($v) => trim((string) $v), $c);

        foreach ([$produtoId, $data, $preco, $cambio] as $celula) {   // anti-formula-injection após trim (D-407/A-4)
            if ($celula !== '' && in_array($celula[0], self::PERIGOSOS, true)) {
                return $base + ['_erro' => 'Célula com prefixo potencialmente perigoso (CWE-1236).'];
            }
        }

        // Excel pt-BR (delimitador ';') usa vírgula decimal: normaliza 1450,50 → 1450.50 (D-411)
        if ($delimitador === ';') {
            $preco = str_replace(',', '.', $preco);
            $cambio = str_replace(',', '.', $cambio);
        }

        if (! ctype_digit($produtoId)) {
            return $base + ['_erro' => 'produto_id inválido.'];
        }
        if (\DateTimeImmutable::createFromFormat('!Y-m-d', $data) === false) {
            return $base + ['_erro' => 'data_preco fora do formato YYYY-MM-DD.'];
        }
        if (! is_numeric($preco) || ! is_numeric($cambio)) {
            return $base + ['_erro' => 'preco_fechamento/cambio_brl não numéricos.'];
        }

        return [
            '_linha' => $numero, '_erro' => null,
            'produto_id' => (int) $produtoId,
            'data_preco' => $data,
            'preco_fechamento' => round((float) $preco, 6),   // escala 6 (M-1): coage à escala da coluna
            'cambio_brl' => round((float) $cambio, 6),
        ];
    }
}
```

> O importador **não** aplica RN-007/008/009 (isso é do Service — D-403): ele só garante
> **forma e segurança**. A positividade e a unicidade chegam no `ServicoPrecos::validarRegras`.
>
> **Locale (D-411):** o formato **canônico** continua sendo o de `requisitos.md` §5.2.2
> (delimitador `,`, decimal `.`) — é o que o **CSV-modelo** da tela oferece. Mas o importador
> aceita também o CSV do **Excel pt-BR**: `detectarDelimitador` escolhe `,`/`;` pela 1ª linha,
> `removerBom` tira o BOM do cabeçalho e, sob `;`, o decimal `,` é normalizado para `.`. Isso é
> um **superset tolerante**, não altera o contrato §5.2.2.
>
> **Escala (M-1):** o decimal é **arredondado** à escala da coluna (6 casas) — coerção, **não**
> rejeição. Um `1450.1234567` entra como `1450.123457`; a DoD-3 reflete esse comportamento.
> O `-` é tratado como prefixo perigoso; preços/câmbios são sempre positivos no MVP (RN-008/009),
> então valores negativos legítimos não existem — números negativos seriam barrados na RN de
> qualquer forma. (Se uma iteração futura admitir negativos, trocar a heurística por "alfabético
> seguido de fórmula" conforme OWASP.)

### 4.5 DTO `ResultadoImportacao` (D-409)

```php
namespace App\Services\Dados;

final class ResultadoImportacao
{
    /** @param list<array{linha:int, motivo:string}> $rejeitadas */
    public function __construct(
        public int $aceitas = 0,
        public array $rejeitadas = [],
    ) {}

    public function aceitar(): void
    {
        $this->aceitas++;
    }

    public function rejeitar(int $linha, string $motivo): void
    {
        $this->rejeitadas[] = ['linha' => $linha, 'motivo' => $motivo];
    }

    public function total(): int
    {
        return $this->aceitas + count($this->rejeitadas);
    }

    /** @return array<string, mixed> — corpo da resposta de POST /precos/upload */
    public function paraArray(): array
    {
        return [
            'total' => $this->total(),
            'aceitas' => $this->aceitas,
            'rejeitadas' => $this->rejeitadas,
        ];
    }
}
```

### 4.6 API de preços + upload (§5.2.2)

```php
namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\{SalvarPrecoRequest, UploadPrecosRequest, ListarPrecosRequest};
use App\Http\Resources\PrecoReferenciaResource;
use App\Services\ServicoPrecos;
use App\Support\Csv\ImportadorPrecosCsv;
use Illuminate\Http\{JsonResponse, Response};

class PrecoController extends Controller
{
    public function __construct(private readonly ServicoPrecos $servico) {}

    public function index(ListarPrecosRequest $request): mixed
    {
        return PrecoReferenciaResource::collection($this->servico->listar(
            $request->integer('produto_id') ?: null,
            $request->date('data_inicio')?->toDateString(),
            $request->date('data_fim')?->toDateString(),
        ));
    }

    public function store(SalvarPrecoRequest $request): JsonResponse
    {
        $preco = $this->servico->lancar($request->validated());

        return (new PrecoReferenciaResource($preco))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    public function upload(UploadPrecosRequest $request): JsonResponse
    {
        $caminho = $request->file('arquivo')->getRealPath();
        $resultado = $this->servico->importar(new ImportadorPrecosCsv($caminho));

        // 200: o lote pode ter linhas rejeitadas (RN-010); o relatório diz o que entrou.
        return response()->json($resultado->paraArray());
    }

    public function destroy(int $preco): Response
    {
        $this->servico->remover($preco);   // RN-010a → 409 se referenciado

        return response()->noContent();
    }
}
```

> `UploadPrecosRequest`: `arquivo` → `required|file|mimes:csv,txt|max:2048` (KB). O limite de
> **linhas** e a sanitização de **fórmula** ficam no importador (D-407) — `mimes`/`max` são a
> primeira barreira (defesa em profundidade).

### 4.7 Form Requests e Resources (D-403/D-404)

- `SalvarProdutoRequest` — `nome` `required|string|max:60`; `unidade` `required|string|max:20`;
  `bolsa_ref` `required|string|max:20`; `moeda_cotacao` `required|string|size:3`;
  `ativo` `sometimes|boolean`. No `update` (PUT/PATCH), o método **ramifica** as regras
  (`isMethod('POST')` → `required`; senão troca `required`→`sometimes`) — PATCH-merge (M-5).
  (Unicidade do nome **não** aqui — D-403.)
- `SalvarPrecoRequest` — `produto_id` `required|integer`; `data_preco` `required|date_format:Y-m-d`;
  `preco_fechamento` `required|numeric|gt:0`; `cambio_brl` `required|numeric|gt:0`.
  (Existência do produto, unicidade RN-007 e positividade autoritativa → Service, D-403.)
- `ListarPrecosRequest` — `produto_id` `sometimes|integer`; `data_inicio`/`data_fim`
  `sometimes|date_format:Y-m-d`.
- `UploadPrecosRequest` — `arquivo` `required|file|mimes:csv,txt|max:2048`.
- `ProdutoResource` / `PrecoReferenciaResource` — expõem os campos do §3.2.1/§3.2.2 com
  decimais como número (não string) e datas ISO; envelope de **erro** já vem do §5.1 (D-605).

### 4.8 Telas Livewire — Produtos (tela 3, §6.1)

`app/Livewire/Produtos/` sob middleware `auth` (sessão), registradas em `routes/web.php`:

- `ListaProdutos` — tabela com `nome`, `unidade`, `bolsa_ref`, `moeda_cotacao`, `ativo`; ações
  **Editar** e **Inativar** (confirmação). Usa `ServicoProdutos` injetado (`boot`/`mount`).
- `FormProduto` — criar/editar; em erro de negócio captura `ErroAplicacao` e exibe a mensagem
  no campo/topo (não vaza stack). Reaproveita a **mesma** `ServicoProdutos` da API.

```php
// rota web (exemplo)
Route::middleware('auth')->group(function () {
    Route::get('/produtos', \App\Livewire\Produtos\ListaProdutos::class)->name('produtos.index');
});
```

### 4.9 Telas Livewire — Preços (tela 4, §6.1)

`app/Livewire/Precos/`:

- `LancamentoPrecos` — duas áreas: **(a)** form manual (`produto_id`, `data_preco`,
  `preco_fechamento`, `cambio_brl`) chamando `ServicoPrecos::lancar`; **(b)** **upload CSV**
  (`wire:model` em `arquivo`, `WithFileUploads`) chamando `ServicoPrecos::importar(new ImportadorPrecosCsv(...))`
  e renderizando o `ResultadoImportacao` (badge "N aceitas / M rejeitadas" + tabela de
  rejeitadas com `linha`/`motivo`).
- Listagem filtrável por produto e período (reusa `ServicoPrecos::listar`), com ação
  **Remover** que trata o 409 da RN-010a (mensagem: "preço já usado em MtM").

### 4.10 Registrar singletons (D-410)

No `AppServiceProvider::register()` (região reservada da Fase 1):

```php
$this->app->singleton(\App\Services\ServicoProdutos::class);
$this->app->singleton(\App\Services\ServicoPrecos::class);
```

Facades `app/Facades/Produtos.php` e `Precos.php` (opcionais) apontam para esses singletons;
controllers continuam preferindo injeção por construtor.

---

## 5. Estrutura esperada após a Parte 4

```
app/
├── Facades/
│   ├── Produtos.php                         (opcional, D-410)
│   └── Precos.php                           (opcional, D-410)
├── Http/
│   ├── Controllers/Api/V1/
│   │   ├── ProdutoController.php            (novo)
│   │   └── PrecoController.php              (novo)
│   ├── Requests/
│   │   ├── SalvarProdutoRequest.php         (novo)
│   │   ├── SalvarPrecoRequest.php           (novo)
│   │   ├── ListarPrecosRequest.php          (novo)
│   │   └── UploadPrecosRequest.php          (novo)
│   └── Resources/
│       ├── ProdutoResource.php              (novo)
│       └── PrecoReferenciaResource.php      (novo)
├── Livewire/
│   ├── Produtos/{ListaProdutos,FormProduto}.php   (novo)
│   └── Precos/LancamentoPrecos.php          (novo)
├── Services/
│   ├── ServicoProdutos.php                  (novo)
│   ├── ServicoPrecos.php                    (novo)
│   └── Dados/ResultadoImportacao.php        (novo, DTO)
├── Support/Csv/
│   ├── FontePrecos.php                      (novo, interface)
│   └── ImportadorPrecosCsv.php              (novo)
├── Models/PrecoReferencia.php               (editado: relação mtms() p/ RN-010a)
└── Providers/AppServiceProvider.php         (editado: singletons D-410)

bootstrap/app.php                            (editado: api: + apiPrefix D-401)
routes/api.php                               (novo, D-401)
routes/web.php                               (editado: rotas Livewire)

tests/
├── Feature/Produtos/ProdutoApiTest.php      (novo)
├── Feature/Produtos/ListaProdutosTest.php   (novo, Livewire)
├── Feature/Precos/PrecoApiTest.php          (novo)
├── Feature/Precos/UploadCsvTest.php         (novo)
└── Unit/Csv/ImportadorPrecosCsvTest.php     (novo, sem banco)
```

---

## 6. Arquivos a entregar (checklist)

- [ ] `bootstrap/app.php` com `api:`/`apiPrefix: 'api/v1'` (D-401); `routes/api.php` sob `auth:sanctum`.
- [ ] `ServicoProdutos` — listar/buscar/criar/atualizar/**inativar** (D-405); nome único → 409.
- [ ] `ServicoPrecos` — listar (filtros) / lançar (RN-007/008/009) / **remover** (RN-010a → 409) /
      **importar** (RN-010).
- [ ] `FontePrecos` (interface) + `ImportadorPrecosCsv` (cabeçalho exato + **BOM** removido,
      streaming, anti-formula-injection CWE-1236 **após `trim`**, limites 2 MB / 5.000 linhas —
      D-407; delimitador `,`/`;` + decimal pt-BR — D-411).
- [ ] `ResultadoImportacao` (DTO) com `paraArray()` (D-409).
- [ ] Controllers `ProdutoController`/`PrecoController` (incl. `upload`); Form Requests; Resources.
- [ ] Telas Livewire: `ListaProdutos`/`FormProduto` e `LancamentoPrecos` (manual + CSV + relatório).
- [ ] `PrecoReferencia::mtms()` (relação p/ RN-010a); singletons no `AppServiceProvider` (D-410).
- [ ] Feature tests: produtos (CRUD + inativar + 404/409), preços (lançar + 409 RN-007 + remover
      409 RN-010a), upload CSV (linhas mistas → relatório correto), Livewire das duas telas.
- [ ] Unit test do importador (sem banco): cabeçalho inválido, **cabeçalho com BOM**, fórmula
      (inclusive **`" =SOMA()"` com espaço à esquerda**), tipos, limites, **delimitador `;` +
      decimal `,`**, **escala decimal arredondada a 6 casas**.
- [ ] `pint --test` e `phpstan` (nível 8) verdes; `composer test` global verde.

---

## 7. Definition of Done (critérios de aceite)

1. **Endpoints operando.** `GET/POST/PUT/DELETE /api/v1/produtos` e
   `GET/POST/DELETE /api/v1/precos` + `POST /api/v1/precos/upload` respondem autenticados
   (Sanctum), com Resources no formato §5.1 e erros no envelope §5.1.
2. **RNs aplicadas no Service:** RN-007 (duplicata produto+data → **409**), RN-008/009
   (preço/câmbio > 0 → 422), RN-010a (remover preço referenciado por `mtm_diario` → **409**),
   RN-010 (CSV com linhas mistas → relatório `{aceitas, rejeitadas[]}` correto, sem abortar o lote).
3. **CSV endurecido (D-407):** cabeçalho inválido (inclusive com **BOM**), célula com prefixo de
   fórmula (checagem **após `trim`** — `" =..."` também barrado), tipo inválido, arquivo/linhas
   acima do limite — todos viram **rejeição** (ou erro de cabeçalho), nunca exceção não tratada.
   **Escala decimal excedente é arredondada à coluna (6 casas)**, não rejeitada (M-1). CSV do
   **Excel pt-BR** (delimitador `;`, decimal `,`) é aceito (D-411). Teste unitário do importador
   cobre cada caso **sem banco**.
4. **Telas funcionando:** cadastro de produto (CRUD + inativar) e lançamento de preço (manual +
   upload com relatório) operam pela mesma `Servico*` da API; erros de negócio aparecem como
   mensagem amigável (sem stack — envelope/captura de `ErroAplicacao`).
5. **Soft delete coerente (D-405):** produto inativado some das opções de nova posição mas
   permanece em listagens históricas; reinativar é idempotente.
6. **Sem regressão:** `vendor/bin/pint --test` e `vendor/bin/phpstan analyse` (nível 8) sem
   erros; `composer test` (Fases 1–4) verde; cobertura de cálculo ≥ 90 % preservada (Fase 3).

---

## 8. Riscos e pontos a verificar

| Risco | Mitigação / ação |
|---|---|
| `routes/api.php` ainda não existe → `apiResource` 404 silencioso | Confirmar `api:` em `bootstrap/app.php` **antes** dos controllers; teste de fumaça `GET /api/v1/produtos` autenticado retorna 200. |
| Cast `decimal:6` devolve **string** → comparação `> 0` e Resource com aspas | Converter com `(float)` **explícito** nas RNs (o trait `ConverteDecimais` é dos Models); no Resource, *cast* explícito para número. |
| Nome único / RN-007 sob **concorrência** → 500 em vez de 409 | `try/catch QueryException` SQLSTATE `23505` → `ErroConflito` no `INSERT` (D-412); pré-`SELECT` é só atalho de UX. |
| CSV do **Excel pt-BR** (`;` / decimal `,` / BOM) rejeitado por inteiro | Detectar delimitador `,`/`;` + normalizar decimal; remover BOM do cabeçalho (D-411); template no formato canônico §5.2.2; testes dedicados. |
| Dois formatos de 422 confundem o cliente (D-404) | Documentar no README da API; estrutural = `{message, errors}`, negócio = `{erro, mensagem}`. Unificação opcional na Fase 11. |
| **Formula injection** no CSV (CWE-1236) | Rejeitar células com prefixo `= + - @ TAB CR` no importador **após `trim`** (D-407/A-4) + `mimes`/`max` no Form Request; controle **autoritativo** é na **geração** de CSV (Fase 7); teste unitário dedicado. |
| CSV grande estoura memória | `SplFileObject` em streaming + limites de bytes/linhas (D-407); **não** usar `file()`/`str_getcsv` em string inteira. |
| Duplicata no CSV: upsert vs. rejeição | **Rejeição** (D-408) — preserva auditoria; documentado no relatório. Não confundir com idempotência do motor (Fase 6). |
| RN-010a sem a relação `mtms()` | Adicionar `HasMany` por `preco_ref_id` em `PrecoReferencia`; teste cobre remoção bloqueada (409) e permitida (sem MtM). |
| `auth:sanctum` sem token (Fase 10 ainda não emitiu) | Em teste, `Sanctum::actingAs(Usuario::factory()->create())`; rotas web Livewire usam sessão (`auth`). Autorização por perfil é Fase 10 (não testar deny por perfil aqui). |
| `preventLazyLoading` (D-206) estoura em listagem | Eager load explícito quando o Resource tocar relações (`Produto::with('precos')` só se necessário); listagens simples não acessam relação. |
| Inativação confundida com DELETE físico | `destroy` chama `inativar` (D-405); teste confirma `ativo=false` e que o registro **permanece**. |

---

## 9. Referências

- `specs/requisitos.md` — §5.2.1/§5.2.2 (contratos), §3.2.1/§3.2.2 (`produto`/`preco_referencia`),
  §5.1 (envelope de erro), §7.2 (RN-007..010a), §6.1 (telas 3 e 4), §9.2 (perfis — Fase 10).
- `specs/passos_dev.md` — Fase 4 (objetivo, tarefas, DoD) e Apêndice (RN × Fase).
- `specs/spec_parte_1.md` — exceções base + envelope §5.1 (D-605).
- `specs/spec_parte_2.md` / `spec_parte_3.md` — Models/traits e cálculo (D-206 `preventLazyLoading`).
- `CLAUDE.md` — arquitetura *fat model*, `FontePrecos` como único ponto de extensão de ingestão,
  comandos Docker/teste.
- OWASP — CSV/Formula Injection (CWE-1236): https://owasp.org/www-community/attacks/CSV_Injection.

---

**Fim do documento.** Próxima etapa: **Fase 5 — Módulo Posições** (`passos_dev.md`), que usa os
produtos/preços daqui para cadastrar os 4 instrumentos e as movimentações de FUTURO com
transação + lock pessimista.
