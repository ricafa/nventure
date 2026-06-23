# Spec — Parte 6: Motor MtM (Parte 8)

> **Equivale à Fase 6 do `passos_dev.md`.** Estreia o **Motor de marcação a mercado**: o
> processamento diário que itera as posições `ABERTA`, calcula o MtM polimórfico (sem
> `if`/`switch` por tipo), persiste o histórico em `mtm_diario` de forma **idempotente** e
> registra cada execução em `motor_execucao` (auditoria por design).
>
> **Fonte da verdade:** `specs/requisitos.md` (v1.7) — §3.2.8/3.2.9 (tabelas), §4.4 (motor),
> §4.5 (`newFromBuilder`), §5.2.4 (API), §6.1/6.2 (telas/ciclo diário), §7.3 (RN-011..015),
> §9.1 (performance), §12.6 (operação do motor). Roteiro: `specs/passos_dev.md` (Fase 6).
> Em divergência, `requisitos.md` prevalece.
>
> **Natureza:** especificação executável — descreve **o que entregar**, as **decisões
> fixadas** e os critérios de aceite (DoD). **Não** altera regras de negócio, modelo de
> dados nem contratos de API (já definidos em `requisitos.md`).
>
> **Convenção de numeração:** as decisões desta parte são `D-6xx` (Fase 6), seguindo a
> convenção das specs anteriores (`spec_parte_5.md` = `D-5xx`). O `passos_dev.md` pré-rotulou
> as mesmas decisões pela numeração de **módulo do produto** (Parte 8 = `D-803/804/806/808`);
> esses rótulos são referenciados entre parênteses para rastreabilidade — não são uma segunda
> família de decisões.

## 0. Decisões desta parte (fixadas)

| # | Tema | Decisão |
|---|---|---|
| **D-601** | Separação cálculo × orquestração | Dois serviços, conforme §4.4: `app/Services/MotorMtm.php` é o **laço de cálculo** (itera `Posicao` ABERTA com eager loading, chama `calcularMtm()`, faz o UPSERT em `mtm_diario`, marca `VENCIDA` e devolve `ResultadoProcessamento`); `app/Services/ServicoMotor.php` é a **orquestração/auditoria** (abre/fecha `motor_execucao`, consolida totais/falhas, devolve `ResumoExecucao`). O motor **não** tem `if` por tipo de instrumento (Alternativa A — *fat model*). |
| **D-602** | Auditoria abre primeiro; `execucao_id` propaga | `ServicoMotor` cria a linha `motor_execucao` (`iniciado_em`, `disparado_por`, `data_calculo`) **antes** do laço e passa o `execucao_id` para `MotorMtm::processarDia($data, $execucaoId)`, que o grava em cada `mtm_diario`. Ao fim, atualiza `total_posicoes`/`sucessos`/`falhas`/`finalizado_em`. Toda execução fica registrada (§2.3, §3.2.9), inclusive as que terminam só com falhas. |
| **D-603** | Idempotência (RN-013) | Conceitualmente um UPSERT pela chave natural `(posicao_id, data_calculo)`, com a `UNIQUE` da tabela (§3.2.8) como backstop. Implementado na prática via `firstOrNew` + `save` para preservar a proveniência (ver D-604). Reprocessar a mesma data **atualiza**, nunca duplica. |
| **D-604** | Proveniência condicional (passos_dev **D-803**) | `execucao_id` e `processado_em` só são sobrescritos quando algum **valor financeiro muda**. Usa-se `firstOrNew` + `fill` dos atributos derivados e `isDirty(['preco_ref_id','preco_mercado','mtm_valor','variacao_dia','pl_acumulado'])`: se *novo* ou *dirty*, carimba `execucao_id` e `processado_em`; se idêntico ao já gravado, **não** toca a proveniência. **Nota:** "sucesso" contabilizado inclui reprocessamentos estéreis (avaliado com sucesso, mesmo sem rescrita). O `isDirty` depende estritamente do cast `decimal:` nos attributes do Eloquent para funcionar corretamente. |
| **D-605** | `VENCIDA` (RN-014, passos_dev **D-804**) | Após calcular o MtM do dia, marca-se `status = VENCIDA` **somente** as posições que (a) foram processadas **com sucesso** nesta execução **e** (b) têm `data_vencimento <= data_calculo`. Posição que falhou (ex.: sem preço) **permanece `ABERTA`** para reprocessamento. A posição que vence **no** dia ainda é processada (era `ABERTA` no início), recebe seu MtM final e só então transita para `VENCIDA` — o que garante que a execução seguinte (RN-011) a ignore. |
| **D-606** | Isolamento de falhas (RN-012) | O laço usa `try/catch` **por posição**; preço ausente para a data vira `{posicao_id, motivo}` em `falhas` e o processamento **continua**. **Não** há transação única do lote (uma falha tardia não pode desfazer sucessos anteriores). O UPSERT + a marcação `VENCIDA` de **uma** posição podem ser agrupados em `DB::transaction` por posição (consistência local), mas o lote não é atômico. |
| **D-607** | Conversão para BRL (RN-015) | `mtmBrl = calcularMtm((float)$preco->preco_fechamento) * (float)$preco->cambio_brl`, polimórfico e **sem caso especial**. Para NDF cambial, a fórmula já produz BRL; a convenção de cadastro (produto-câmbio com `cambio_brl = 1`, §1.4/§4.3.2) torna a multiplicação **neutra**. `pl_acumulado = mtmBrl + plRealizado() * cambio_brl` (RN-023), onde `plRealizado()` é polimórfico (base = 0; `Futuro` sobrescreve). `variacao_dia = mtmBrl − mtmOntem` (último `mtm_diario` anterior à data). |
| **D-608** | Desempenho/precisão (endereça crítica) | Evita N+1 crítico de relações via **carga por subclasse com eager loading concatenado** (evitando bug na base) e **um** `SELECT` para preços do dia num mapa `produto_id ⇒ PrecoReferencia`. O `mtmOntem` usa o índice `idx_mtm_posicao_data`, mas o N+1 associado a ele é **aceito no MVP** (a otimização via mapa `DISTINCT ON` fica para a Fase 12). O `float` nativo (D-MVC-2) é mantido — BCMath/Money e denormalização de replay permanecem na Fase 12. |
| **D-609** | Command + agendamento (passos_dev **D-806**) | `ProcessarMotorCommand` (`motor:processar {--data=}`): sem `--data` processa **hoje** (§12.6); com `--data=YYYY-MM-DD` processa a data informada; `disparado_por = 'agendador'`. Em `routes/console.php`: `Schedule::command('motor:processar')->weekdays()->at('19:00')`. Em produção o cron chama `php artisan schedule:run`. |
| **D-610** | Livewire injeta o Service (passos_dev **D-808**) | A tela `/motor` injeta `ServicoMotor` e chama o método PHP diretamente — **sem** auto-chamada HTTP ao próprio endpoint. UI e API compartilham a mesma orquestração. |
| **D-611** | Contrato HTTP de `POST /motor/processar` | Retorna **`200 OK`** com o `ResumoExecucao` flat da §5.2.4 (sem *wrapper* `data`). Embora cada disparo crie uma linha `motor_execucao`, ela é **log de auditoria**, não um recurso REST endereçável que o cliente tenha criado; o efeito material (`mtm_diario`) é idempotente (D-603). `data_calculo` é **obrigatório** no Form Request da API; o *default hoje* existe só no Command (D-609). |
| **D-612** | AuthZ por perfil deferida | Rotas sob `auth:sanctum` **sem** distinção de perfil nesta fase. A restrição "OPERADOR dispara o motor" (§9.2) entra na **Fase 10** (consistente com D-402). Registrar a ressalva. |
| **D-613** | Execuções concorrentes | A `UNIQUE(posicao_id, data_calculo)` (§3.2.8) é o backstop contra duplicidade se dois disparos da **mesma** data correrem juntos (`QueryException` 23505 isolada como falha da posição, D-606). Trava de execução por data (advisory lock / linha sentinela) é refinamento da Fase 12 — no MVP o agendador roda **uma vez** às 19:00 e o disparo manual é pontual. |

## 1. Objetivo e escopo

**Objetivo:** processar a marcação a mercado de um pregão de ponta a ponta — buscar as
posições abertas, calcular o MtM polimórfico de cada uma, gravar o histórico diário de
forma idempotente, marcar vencimentos e auditar a execução — exposto por API, Command
agendável e tela Livewire.

**Dentro do escopo**
- `app/Services/MotorMtm.php` — laço de cálculo polimórfico + UPSERT idempotente + `VENCIDA` (RN-011..015).
- `app/Services/ServicoMotor.php` — orquestração e auditoria em `motor_execucao`.
- DTOs em `app/Services/Dados/`: `ResultadoProcessamento`, `RegistroMtm`, `ResumoExecucao`.
- **API REST** §5.2.4 em `app/Http/Controllers/Api/V1/MotorController.php` (+ `ProcessarMotorRequest`, `ExecucaoMotorResource`).
- **Command** `ProcessarMotorCommand` (`motor:processar`) + **agendamento** em `routes/console.php` (D-609).
- **Tela Livewire** `/motor` (disparo + resumo + histórico de execuções), espelhando o mock (`mock_telas/screens3.jsx`).
- Feature tests dos fluxos RN-011..015 (incl. idempotência, `VENCIDA`, falha isolada).

**Fora do escopo (outras fases)**
- **Relatórios** (as 4 visões consolidadas) — Fase 7 (§5.2.5).
- **Seed/dataset de demonstração** e geração de histórico de MtM — Fase 8.
- **AuthZ por perfil** (OPERADOR/GESTOR/ADMIN, §9.2) — Fase 10 (D-612/D-402).
- **`/health` e `/metrics`**, teste de carga (1.000 posições < 30 s, §9.1) — Fase 12.
- **Carry-over / forward-fill de preço** (preço ausente assume D-1): permanece **fora do MVP** (RN-012 = falha + continua); registrado em `pontos_de_atencao.md`.
- **BCMath/Money, `ESTORNO`, decomposição preço × câmbio da `variacao_dia`**: fora do MVP (críticas reafirmadas, §8).

## 2. Mapa de arquivos × responsabilidade

| Arquivo | Camada | Responsabilidade |
|---|---|---|
| `app/Services/MotorMtm.php` | aplicação | Laço de cálculo: busca posições `ABERTA` (eager loading), `calcularMtm()` polimórfico, conversão BRL (RN-015), UPSERT idempotente (RN-013, D-603/604), marcação `VENCIDA` (RN-014, D-605). Devolve `ResultadoProcessamento`. **(novo)** |
| `app/Services/ServicoMotor.php` | aplicação | Orquestração/auditoria: abre `motor_execucao`, chama `MotorMtm`, consolida totais/falhas, fecha a execução; devolve `ResumoExecucao`. Lista/detalha execuções. **(novo)** |
| `app/Services/Dados/ResultadoProcessamento.php` | DTO | Saída transitória do laço: `sucessos: int[]`, `falhas: list<{posicao_id,motivo}>`. **(novo)** |
| `app/Services/Dados/RegistroMtm.php` | DTO | Valores financeiros calculados de **uma** posição (pré-persistência), mantendo a aritmética isolada/testável. **(novo)** |
| `app/Services/Dados/ResumoExecucao.php` | DTO | Read model da execução para a API/UI (§5.2.4): `execucao_id`, `data_calculo`, `posicoes_processadas`, `sucessos`, `falhas[]`. **(novo)** |
| `app/Http/Controllers/Api/V1/MotorController.php` | HTTP | Endpoints §5.2.4 (`processar`, `index`, `show`). **(novo)** |
| `app/Http/Requests/ProcessarMotorRequest.php` | HTTP | Valida `data_calculo` (obrigatório, `date`). **(novo)** |
| `app/Http/Resources/ExecucaoMotorResource.php` | HTTP | Serialização de `motor_execucao` (lista/detalhe). **(novo)** |
| `app/Console/Commands/ProcessarMotorCommand.php` | console | `motor:processar {--data=}` (D-609). **(novo)** |
| `routes/console.php` | console | Agendamento `weekdays()->at('19:00')` (D-609). **(editado)** |
| `routes/api.php` | HTTP | 3 rotas `/motor/*` sob `auth:sanctum`. **(editado)** |
| `routes/web.php` | web | Rota `/motor` (Livewire) sob `auth`. **(editado)** |
| `app/Livewire/Motor/ProcessarMotor.php` (+ view) | UI | Tela de disparo, resumo e histórico (D-610). **(novo)** |
| `app/Models/MtmDiario.php`, `app/Models/MotorExecucao.php` | Model | **Já existem** (Fase 2). Reusados como estão (casts/relações). **(reuso)** |

## 3. Pré-requisitos

- Fases 1–5 verdes.
- Models de cálculo prontos e testados: `Posicao::newFromBuilder` (§4.5), `Futuro/Ndf/Opcao/Otc::calcularMtm`, `Futuro::precoMedio/quantidadeAtual/plRealizado` (replay puro), traits `ConverteDecimais`/`ReproduzMovimentacoes`.
- Tabelas `mtm_diario` (UNIQUE `posicao_id,data_calculo`; índices `idx_mtm_posicao_data`/`idx_mtm_data`) e `motor_execucao` (JSONB `falhas`) migradas; índice parcial `idx_posicao_status WHERE status='ABERTA'`.
- Models `MtmDiario`/`MotorExecucao` (Fase 2) com casts e relações (`mtmDiarios`, `execucao`).
- Exceções/`bootstrap/app.php` mapeando o envelope §5.1 (D-605 do `passos_dev`).
- Produto-câmbio (Dólar USD/BRL, `cambio_brl=1`) disponível para NDF cambial (premissa §1.4; cadastrado na Fase 4 / semeado na Fase 8).

## 4. Passo a passo

### 4.0 Visão geral do fluxo

```
ServicoMotor::processar(data, disparadoPor)
  → cria motor_execucao (iniciado_em, disparado_por, data_calculo)
  → MotorMtm::processarDia(data, execucao_id)
        para cada Posicao ABERTA (por subclasse com eager loading):
          preco = mapaPrecos[produto_id]  (1 SELECT do dia, D-608)
          se sem preco → falhas[] += {id, "Preço não cadastrado para a data"}; continua (RN-012)
          registro = calcularRegistro(posicao, preco, mtmOntem)   ← aritmética pura
          upsert idempotente em mtm_diario (D-603/604)
          se data_vencimento <= data → marcar VENCIDA (D-605)
          sucessos[] += id
  → consolida total_posicoes/sucessos/falhas, finalizado_em
  → devolve ResumoExecucao
```

### 4.1 `MotorMtm` — laço de cálculo (RN-011..015)

```php
namespace App\Services;

use App\Models\{MtmDiario, Posicao, Futuro, Ndf, Opcao, Otc, PrecoReferencia};
use App\Services\Dados\{RegistroMtm, ResultadoProcessamento};
use Illuminate\Support\Facades\DB;

class MotorMtm
{
    /**
     * Processa todas as posições ABERTA para a data. Idempotente (RN-013);
     * falhas isoladas não interrompem o lote (RN-012); sem `if` por tipo.
     */
    public function processarDia(\DateTimeImmutable $data, int $execucaoId): ResultadoProcessamento
    {
        $dataStr = $data->format('Y-m-d');
        $resultado = new ResultadoProcessamento($data);

        // RN-011 + eager loading (D-608): carrega por subclasse para não estourar método na base.
        $posicoes = collect()
            ->merge(Futuro::query()->with(['futuro', 'movimentacoes'])->where('status', 'ABERTA')->get())
            ->merge(Ndf::query()->with('ndf')->where('status', 'ABERTA')->get())
            ->merge(Opcao::query()->with(['opcao', 'pernas'])->where('status', 'ABERTA')->get())
            ->merge(Otc::query()->with('otc')->where('status', 'ABERTA')->get());

        // D-608: preços do dia em UM SELECT, indexados por produto_id (evita N+1).
        $precos = PrecoReferencia::query()
            ->where('data_preco', $dataStr)
            ->get()
            ->keyBy('produto_id');

        foreach ($posicoes as $posicao) {
            try {
                $preco = $precos->get($posicao->produto_id);
                if ($preco === null) {
                    $resultado->registrarFalha($posicao->id, 'Preço não cadastrado para a data');
                    continue; // RN-012
                }

                DB::transaction(function () use ($posicao, $preco, $data, $dataStr, $execucaoId) {
                    $registro = $this->calcularRegistro($posicao, $preco, $dataStr);
                    $this->persistir($registro, $dataStr, $execucaoId);

                    // RN-014 (D-605/M-3): vencida só quem teve sucesso ∩ venceu, sob lock (D-501).
                    if ($posicao->data_vencimento->format('Y-m-d') <= $dataStr) {
                        $posLock = Posicao::lockForUpdate()->find($posicao->id);
                        if ($posLock && $posLock->status === 'ABERTA') {
                            $posLock->update(['status' => 'VENCIDA']);
                        }
                    }
                });

                $resultado->registrarSucesso($posicao->id);
            } catch (\Illuminate\Database\QueryException $e) {
                $resultado->registrarFalha($posicao->id, 'Conflito ao gravar MtM / reprocessamento concorrente');
            } catch (\Throwable $e) {
                $resultado->registrarFalha($posicao->id, 'Erro inesperado ao processar a posição');
            }
        }

        return $resultado;
    }

    /** Aritmética pura (RN-015/RN-023): nenhuma escrita, só leitura de relações já carregadas. */
    private function calcularRegistro(Posicao $posicao, PrecoReferencia $preco, string $dataStr): RegistroMtm
    {
        $cambio = (float) $preco->cambio_brl;

        $mtmBrl = $posicao->calcularMtm((float) $preco->preco_fechamento) * $cambio; // RN-015
        $plAcumulado = $mtmBrl + $posicao->plRealizado() * $cambio;                  // RN-023

        $mtmOntem = MtmDiario::query()
            ->where('posicao_id', $posicao->id)
            ->where('data_calculo', '<', $dataStr)
            ->orderByDesc('data_calculo')               // idx_mtm_posicao_data (D-608)
            ->value('mtm_valor');
        $variacao = $mtmBrl - (float) ($mtmOntem ?? 0.0);

        return new RegistroMtm(
            posicaoId:   $posicao->id,
            precoRefId:  $preco->id,
            precoMercado: (float) $preco->preco_fechamento,
            mtmValor:    round($mtmBrl, 2),
            variacaoDia: round($variacao, 2),
            plAcumulado: round($plAcumulado, 2),
        );
    }

    /** UPSERT idempotente (RN-013, D-603) com proveniência condicional (D-604). */
    private function persistir(RegistroMtm $r, string $dataStr, int $execucaoId): void
    {
        $mtm = MtmDiario::query()->firstOrNew([
            'posicao_id'   => $r->posicaoId,
            'data_calculo' => $dataStr,
        ]);

        $mtm->fill([
            'preco_ref_id'  => $r->precoRefId,
            'preco_mercado' => $r->precoMercado,
            'mtm_valor'     => $r->mtmValor,
            'variacao_dia'  => $r->variacaoDia,
            'pl_acumulado'  => $r->plAcumulado,
        ]);

        // D-604: só carimba autoria/timestamp quando algo financeiro mudou.
        if (! $mtm->exists || $mtm->isDirty(['preco_ref_id', 'preco_mercado', 'mtm_valor', 'variacao_dia', 'pl_acumulado'])) {
            $mtm->execucao_id = $execucaoId;
            $mtm->processado_em = now();
        }

        $mtm->save();
    }
}
```

> **Por que `firstOrNew`/`isDirty` e não `updateOrCreate` direto:** `updateOrCreate`
> sempre reescreveria `execucao_id`/`processado_em`, perdendo a proveniência do último
> cálculo **material**. D-604 preserva quem/quando produziu o número vigente quando o
> reprocessamento é estéril. A unicidade (RN-013) continua garantida por `firstOrNew` +
> `UNIQUE(posicao_id,data_calculo)`.

> **Polimorfismo intacto:** `calcularMtm()` e `plRealizado()` são chamados sem nenhum
> `if`/`switch` por instrumento; o único `match` por tipo vive em `Posicao::newFromBuilder`
> (§4.5). Novo instrumento = novo Model + um `case` no `match`; o motor não muda.

### 4.2 `ServicoMotor` — orquestração e auditoria (D-601/D-602)

```php
namespace App\Services;

use App\Models\MotorExecucao;
use App\Services\Dados\ResumoExecucao;

class ServicoMotor
{
    public function __construct(private readonly MotorMtm $motor) {}

    public function processar(\DateTimeImmutable $data, string $disparadoPor): ResumoExecucao
    {
        // D-602: abre a auditoria ANTES do laço; execucao_id propaga ao mtm_diario.
        $execucao = MotorExecucao::query()->create([
            'data_calculo' => $data->format('Y-m-d'),
            'disparado_por' => $disparadoPor,
            'iniciado_em' => now(),
        ]);

        $resultado = $this->motor->processarDia($data, $execucao->id);

        $execucao->update([
            'finalizado_em' => now(),
            'total_posicoes' => $resultado->totalPosicoes(),
            'sucessos' => count($resultado->sucessos),
            'falhas' => $resultado->falhas, // JSONB [{posicao_id, motivo}]
        ]);

        return ResumoExecucao::deExecucao($execucao->refresh());
    }
}
```

- `disparado_por`: na API, usa-se guard `instanceof Usuario` (D-507); no Command agendado/manual, `'agendador'` (D-609).
- Listagem/detalhe (`GET /motor/execucoes[/{id}]`) consultam `MotorExecucao` diretamente (paginado na lista) — método `listar()`/`detalhar(int $id)` no `ServicoMotor`, devolvendo `MotorExecucao` para o `ExecucaoMotorResource`. `detalhar` usa `find(...) ?? throw new ErroNaoEncontrado(...)`.

### 4.3 DTOs (`app/Services/Dados/`)

```php
final class ResultadoProcessamento
{
    /** @var list<int> */ public array $sucessos = [];
    /** @var list<array{posicao_id:int,motivo:string}> */ public array $falhas = [];

    public function __construct(public readonly \DateTimeImmutable $data) {}

    public function registrarSucesso(int $posicaoId): void { $this->sucessos[] = $posicaoId; }
    public function registrarFalha(int $posicaoId, string $motivo): void
    {
        $this->falhas[] = ['posicao_id' => $posicaoId, 'motivo' => $motivo];
    }
    public function totalPosicoes(): int { return count($this->sucessos) + count($this->falhas); }
}
```

```php
final class RegistroMtm
{
    public function __construct(
        public int $posicaoId,
        public int $precoRefId,
        public float $precoMercado,
        public float $mtmValor,
        public float $variacaoDia,
        public float $plAcumulado,
    ) {}
}
```

```php
final class ResumoExecucao  // §5.2.4 (resposta flat)
{
    /** @param list<array{posicao_id:int,motivo:string}> $falhas */
    public function __construct(
        public int $execucaoId,
        public string $dataCalculo,
        public int $posicoesProcessadas,
        public int $sucessos,
        public array $falhas,
    ) {}

    public static function deExecucao(MotorExecucao $e): self { /* mapeia colunas → DTO */ }

    /** @return array{execucao_id:int,data_calculo:string,posicoes_processadas:int,sucessos:int,falhas:list<array{posicao_id:int,motivo:string}>} */
    public function paraArray(): array
    {
        return [
            'execucao_id' => $this->execucaoId,
            'data_calculo' => $this->dataCalculo,
            'posicoes_processadas' => $this->posicoesProcessadas,
            'sucessos' => $this->sucessos,
            'falhas' => $this->falhas,
        ];
    }
}
```

### 4.4 API REST (§5.2.4) — `MotorController` + rotas

```php
class MotorController extends Controller
{
    public function __construct(private readonly ServicoMotor $motor) {}

    public function processar(ProcessarMotorRequest $request): JsonResponse
    {
        $usuario = Auth::user();
        $resumo = $this->motor->processar(
            new \DateTimeImmutable($request->validated('data_calculo')),
            $usuario instanceof \App\Models\Usuario ? $usuario->login : 'sistema',
        );

        return response()->json($resumo->paraArray()); // 200 (D-611), flat
    }

    public function index(): AnonymousResourceCollection
    {
        return ExecucaoMotorResource::collection($this->motor->listar());
    }

    public function show(int $id): ExecucaoMotorResource
    {
        return new ExecucaoMotorResource($this->motor->detalhar($id)); // 404 via ErroNaoEncontrado
    }
}
```

Rotas em `routes/api.php` (dentro do grupo `auth:sanctum` existente):

```php
// §5.2.4 Motor MtM (AuthZ por perfil é Fase 10, D-612)
Route::post('motor/processar', [MotorController::class, 'processar']);
Route::get('motor/execucoes', [MotorController::class, 'index']);
Route::get('motor/execucoes/{id}', [MotorController::class, 'show'])->whereNumber('id');
```

`ProcessarMotorRequest`: `['data_calculo' => ['required', 'date']]`. `ExecucaoMotorResource`
serializa `id` (como `execucao_id`), `data_calculo`, `disparado_por`, `iniciado_em`,
`finalizado_em`, `total_posicoes`, `sucessos`, `falhas`.

### 4.5 Command + agendamento (D-609)

```php
// app/Console/Commands/ProcessarMotorCommand.php
class ProcessarMotorCommand extends Command
{
    protected $signature = 'motor:processar {--data= : Data YYYY-MM-DD (default: hoje)}';
    protected $description = 'Processa a marcação a mercado (MtM) das posições abertas para a data.';

    public function handle(ServicoMotor $motor): int
    {
        try {
            $data = new \DateTimeImmutable($this->option('data') ?: 'today');
        } catch (\Exception $e) {
            $this->error('Data malformada. Use o formato YYYY-MM-DD.');
            return self::FAILURE;
        }
        
        $resumo = $motor->processar($data, 'agendador');

        $this->info(sprintf(
            'Motor #%d · %s · %d/%d sucessos · %d falhas',
            $resumo->execucaoId, $resumo->dataCalculo,
            $resumo->sucessos, $resumo->posicoesProcessadas, count($resumo->falhas),
        ));

        return self::SUCCESS;
    }
}
```

```php
// routes/console.php
use Illuminate\Support\Facades\Schedule;

Schedule::command('motor:processar')->weekdays()->at('19:00'); // D-609
```

### 4.6 Tela Livewire `/motor` (D-610)

Espelha `mock_telas/screens3.jsx` (`MotorScreen`): seletor de **data do cálculo**, painel de
disparo (`POST /motor/processar`), bloco de **resumo** (sucessos/falhas/`execucao_id`) e
**histórico de execuções** (`GET /motor/execucoes`) com detalhe. O componente injeta
`ServicoMotor` e chama `processar(...)` diretamente — **sem** auto-chamada HTTP (D-610).

```php
namespace App\Livewire\Motor;

#[Layout('components.layouts.app')]
class ProcessarMotor extends Component
{
    #[Url] public string $dataCalculo;
    public ?array $resumo = null;

    public function mount(): void { $this->dataCalculo = now()->toDateString(); }

    public function disparar(ServicoMotor $motor): void
    {
        $usuario = Auth::user();
        $resumo = $motor->processar(
            new \DateTimeImmutable($this->dataCalculo),
            $usuario instanceof \App\Models\Usuario ? $usuario->login : 'sistema',
        );
        $this->resumo = $resumo->paraArray();
    }

    public function render(): mixed
    {
        return view('livewire.motor.processar-motor', [
            'execucoes' => MotorExecucao::query()->orderByDesc('id')->limit(20)->get(),
        ]);
    }
}
```

Rota em `routes/web.php` (grupo `auth`): `Route::get('/motor', ProcessarMotor::class)->name('motor.index');`.

## 5. Estrutura esperada após a Parte 6

```
app/
├── Console/Commands/ProcessarMotorCommand.php          (novo)
├── Http/
│   ├── Controllers/Api/V1/MotorController.php           (novo)
│   ├── Requests/ProcessarMotorRequest.php               (novo)
│   └── Resources/ExecucaoMotorResource.php              (novo)
├── Livewire/Motor/ProcessarMotor.php                    (novo)
├── Services/
│   ├── MotorMtm.php                                     (novo)
│   ├── ServicoMotor.php                                 (novo)
│   └── Dados/
│       ├── ResultadoProcessamento.php                   (novo)
│       ├── RegistroMtm.php                              (novo)
│       └── ResumoExecucao.php                           (novo)
resources/views/livewire/motor/processar-motor.blade.php (novo)
routes/api.php       (editado — 3 rotas /motor/*)
routes/web.php       (editado — rota /motor)
routes/console.php   (editado — Schedule weekdays 19:00)
```

## 6. Arquivos a entregar (checklist)

- [ ] `MotorMtm.php`: laço sobre `Posicao` ABERTA (eager loading por subclasse), `calcularMtm()` polimórfico **sem `if` por tipo**, mapa de preços do dia (1 SELECT, D-608), UPSERT idempotente com proveniência condicional (D-603/604), marcação `VENCIDA` com lock (D-605/M-3), `try/catch` por posição (D-606).
- [ ] `ServicoMotor.php`: abre/fecha `motor_execucao`, propaga `execucao_id`, consolida totais/falhas (JSONB); `listar()`/`detalhar()`.
- [ ] DTOs `ResultadoProcessamento`, `RegistroMtm`, `ResumoExecucao`.
- [ ] `MotorController` + `ProcessarMotorRequest` + `ExecucaoMotorResource`; 3 rotas `/motor/*` sob `auth:sanctum`.
- [ ] `ProcessarMotorCommand` (`motor:processar {--data=}`) + `Schedule::command(...)->weekdays()->at('19:00')` em `routes/console.php`.
- [ ] Tela Livewire `/motor` (injeta `ServicoMotor`, D-610) + rota web.
- [ ] **Feature tests:** processa só ABERTA (RN-011); preço ausente → falha isolada e lote continua (RN-012); **reprocessar a mesma data atualiza e não duplica** (RN-013); posição que vence muda para `VENCIDA` e some da execução seguinte (RN-014); conversão BRL e NDF cambial neutra (RN-015); `pl_acumulado = mtm + realizado` para FUTURO com redução (RN-023); resposta §5.2.4 flat; `GET /execucoes/{id}` 404 inexistente.
- [ ] **Teste de proveniência (D-604):** reprocessar sem mudança de valor **não** altera `execucao_id`/`processado_em`; reprocessar com preço diferente **altera**.
- [ ] **Teste de auditoria:** toda execução cria `motor_execucao` com `iniciado_em`/`finalizado_em` e contadores corretos, inclusive execução só com falhas.
- [ ] `phpstan` nível 8 liso e `pint --test` verde.

## 7. Definition of Done (critérios de aceite)

1. `POST /motor/processar { "data_calculo": "YYYY-MM-DD" }` responde **200** com `{execucao_id, data_calculo, posicoes_processadas, sucessos, falhas[]}` (§5.2.4) e grava as linhas `mtm_diario` + a linha `motor_execucao`.
2. **Reprocessar a mesma data atualiza** os registros (UPSERT por `posicao_id+data_calculo`) — nunca duplica (RN-013); a `variacao_dia` recalculada usa o último MtM anterior à data.
3. Posição com **vencimento ≤ data** processada com sucesso transita para `VENCIDA` (RN-014) e é **ignorada** na execução seguinte (RN-011).
4. **Preço ausente** marca a posição como falha (`{posicao_id, motivo}`) e o lote **continua** processando as demais (RN-012); a posição permanece `ABERTA`.
5. O motor **não** contém `if`/`switch` por tipo de instrumento (revisão de código); o resultado de cada instrumento confere com os cenários unitários da Fase 3 multiplicados pelo câmbio (RN-015), com NDF cambial neutra.
6. `motor:processar` roda no container (com e sem `--data`) e o agendamento `weekdays 19:00` aparece em `php artisan schedule:list`.
7. A tela `/motor` dispara via `ServicoMotor` (sem self-call HTTP, D-610), mostra resumo e histórico.
8. `phpstan` nível 8 e `pint --test` verdes; feature tests do módulo verdes.

## 8. Riscos e pontos a verificar

| Risco | Mitigação / ação |
|---|---|
| **Reprocessamento duplica MtM** | UPSERT por chave natural (D-603) + `UNIQUE(posicao_id,data_calculo)` como backstop; teste de idempotência fixo. |
| **Reprocessamento estéril reescreve autoria** | D-604: `firstOrNew` + `isDirty` carimba `execucao_id`/`processado_em` só quando o valor financeiro muda; teste dedicado. |
| **`VENCIDA` marcada em posição que falhou** | D-605: só a interseção sucesso ∩ vencida transiciona; falha mantém `ABERTA` para retry; teste com preço ausente em posição vencida. |
| **Falha de uma posição derruba o lote** | D-606: `try/catch` por posição, sem transação única do lote; preço ausente vira falha registrada (RN-012). |
| **Dupla conversão cambial (NDF)** | D-607/RN-015: multiplicação única por `cambio_brl`; NDF cambial neutra pela convenção `cambio_brl=1` (§4.3.2). Teste de NDF cambial confirmando resultado já em BRL. |
| **N+1 no mtmOntem / replay pesado (crítica §1, §9.1)** | D-608: eager loading por subclasse + mapa de preços (1 SELECT) mitigam grande parte. N+1 do `mtmOntem` é aceito no MVP; mapa `DISTINCT ON`, meta de 30 s e denormalização de replay → **Fase 12**. |
| **Defasagem variacao_dia em reprocesso fora de ordem** | Documentado como limitação do MVP. Recomenda-se que o backfill da Fase 8 processe as datas em **ordem crescente**. |
| **Precisão `float` (crítica §1)** | D-MVC-2 mantido (float + arredondamento à escala na borda); BCMath/Money fora do MVP — risco aceito e registrado. |
| **Preço ausente em feriado vira "buraco" no MtM (crítica §2)** | Carry-over/forward-fill fora do MVP (RN-012 = falha+continua); registrado em `pontos_de_atencao.md` para a Fase 2 do roadmap. |
| **Execuções concorrentes na mesma data** | D-613: `UNIQUE` como backstop (23505 isolado como falha da posição); trava por data adiada à Fase 12. |
| **Qualquer um dispara o motor** | D-612: nesta fase só `auth:sanctum`; restrição a OPERADOR (§9.2) na Fase 10 — ressalva registrada. |

## 9. Referências

- `specs/requisitos.md` — §3.2.8/3.2.9, §4.4/§4.5, §5.2.4, §6.1/6.2, §7.3 (RN-011..015), §9.1, §12.6.
- `specs/passos_dev.md` — Fase 6 (rótulos D-803/804/806/808) e Apêndice de rastreabilidade (RN-011..015 → Fase 6).
- `specs/spec_parte_5.md` — molde de Service/DTO/HTTP e decisões herdadas (D-402, D-507, D-MVC-1..3).
- `specs/future/pontos_de_atencao.md` — críticas de arquitetura (replay/performance, float, carry-over) reafirmadas como fora do MVP / Fase 12.
- `mock_telas/screens3.jsx` (`MotorScreen`) — UI de referência da tela `/motor`.
- `CLAUDE.md` — arquitetura *fat model*, comandos Docker/teste, "nunca commite como Claude".

---
**Fim do documento.** Próxima etapa: **Fase 7 — Relatórios (Parte 9)** (`passos_dev.md`), que consome o histórico `mtm_diario` produzido por este motor para montar as 4 visões consolidadas da mesa de risco (§5.2.5, RN-016..019).
</content>
</invoke>
