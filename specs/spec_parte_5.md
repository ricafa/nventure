# Spec — Parte 5: Módulo Posições (Parte 7)

> **Equivale à Fase 5 do `passos_dev.md`.** Estreia o módulo de Posições, permitindo o cadastro dos 4 instrumentos (FUTURO, NDF, OPCAO, OTC) e a orquestração transacional de movimentações de FUTURO (aumento, redução, redução total).
>
> **Fonte da verdade:** `specs/requisitos.md` (v1.6) — §3.2.3–3.2.8, §4.2–4.3, §5.2.3, §6.1/6.3/6.4, §7.1, §7.1a. Roteiro: `specs/passos_dev.md` (Fase 5). Em divergência, `requisitos.md` prevalece.
>
> **Natureza:** especificação executável — descreve **o que entregar**, as **decisões fixadas** e os critérios de aceite (DoD). **Não** altera regras de negócio, modelo de dados nem contratos de API.

## 0. Decisões desta parte (fixadas)

| # | Tema | Decisão |
|---|---|---|
| **D-501** | Transação e Lock Pessimista | `ServicoMovimentacoes::movimentarFuturo` opera sob `DB::transaction` e captura `Posicao::lockForUpdate()` antes de computar o `replay()` e calcular o novo estado. Evita corrida na RN-022 (redução excedente). |
| **D-502** | Deleção vs Encerramento | `DELETE /posicoes/{id}` deleta a posição **somente** se `mtmDiarios()->doesntExist()`. Se já foi processada pelo motor, devolve `409 Conflict`. O fluxo correto passa a ser a ação "Encerrar". |
| **D-503** | Consumo `uq_mov_abertura` (Race condition) | Na criação de um futuro (`criarFuturo`), o `INSERT` da movimentação inicial (`ABERTURA`) fica em um `try/catch QueryException`. Se violar o `uq_mov_abertura` (SQLSTATE 23505), lança `ErroConflito` (409), tratando possíveis race conditions no pré-SELECT. |
| **D-504** | Cache O(1) de Quantidade/PM | Endereçando a crítica ao "fat model replay", `ServicoMovimentacoes` consolida `quantidade` (na mãe `Posicao`) e `preco_medio` (na filha `PosicaoFuturo`) a cada movimentação. O motor (Fase 6) lerá esses campos em O(1), sem reprocessar `replay()` em massa na leitura. |
| **D-505** | DTOs de Saída | `PosicaoResumo` (listagem, mascara detalhes), `PosicaoDetalhe` (carrega mãe+filha completa) e `EstadoMovimentacao` blindam a UI da forma como os Eloquent Models estruturam as tabelas. |
| **D-506** | Borda de conversão de decimais | Assim como na Parte 4, o `float` nativo (Alternativa A de arquitetura) será adotado via casts e coerções. A formatação para `float` fica estritamente na borda do `Service`, respeitando `ConverteDecimais` dos Models. |

## 1. Objetivo e escopo

**Objetivo:** Permitir o cadastro completo de posições dos 4 instrumentos suportados, além das movimentações específicas para posições `FUTURO`, mantendo consistência com travas transacionais e restrições de deleção.

**Dentro do escopo**
- `app/Services/ServicoPosicoes.php` — Cadastro dos 4 tipos de instrumentos (RN-001 a RN-006, RN-004a..e, RN-003, RN-006), listagem, exclusão condicionada (D-502) e encerramento.
- `app/Services/ServicoMovimentacoes.php` — Abertura e movimentações de FUTURO (RN-020 a RN-025), incluindo recálculo de preço médio, realização e transações com lock (D-501).
- **API REST** §5.2.3 em `app/Http/Controllers/Api/V1/PosicaoController.php`.
- **Telas Livewire**: `/posicoes` (Listagem, Detalhe, modal Movimentar) e `/posicoes/nova`.
- **Form Requests** para validação das chaves estruturais.
- DTOs `PosicaoResumo`, `PosicaoDetalhe`, `EstadoMovimentacao`.

**Fora do escopo (outras fases)**
- **Motor MtM**: Fica para a Fase 6.
- **Relatórios**: Visões consolidadas ficam para a Fase 7.
- Operações de `ESTORNO` de movimentações. Embora apontado na análise crítica de arquitetura, foge do escopo do MVP e permanece como restrição imutável (RN-025).

## 2. Mapa de arquivos × responsabilidade

| Arquivo | Camada | Responsabilidade |
|---|---|---|
| `app/Services/ServicoPosicoes.php` | aplicação | Cadastro base polimórfico dos 4 instrumentos, deleção segura. |
| `app/Services/ServicoMovimentacoes.php` | aplicação | Movimentações de FUTURO, RN-021..RN-025, travas e idempotência. |
| `app/Services/Dados/PosicaoResumo.php` | DTO | Representação simplificada para listagem na UI. |
| `app/Services/Dados/PosicaoDetalhe.php` | DTO | Agregação completa de Posição + Filhas para Visualização. |
| `app/Http/Controllers/Api/V1/PosicaoController.php` | HTTP | Endpoints REST da §5.2.3. |
| `app/Http/Requests/*PosicaoRequest.php` | HTTP | `SalvarPosicaoRequest`, `MovimentarFuturoRequest`. |
| `app/Http/Resources/PosicaoResource.php` | HTTP | Serialização em API JSON. |
| `app/Livewire/Posicoes/*` | UI | Telas Livewire de Listagem, Detalhe, e Nova Posição. |

## 3. Pré-requisitos

- Fases 1-4 concluídas e código verde.
- Modelos M criados (`Posicao`, filhas, `Movimentacao`, `Perna`), e o trait de cálculo implementado e testado.
- Exceções `ErroConflito`, `ErroValidacao` operantes e capturadas pelo envelope de erros do Laravel.

## 4. Passo a passo

### 4.1 Serviços e Integridade Transacional (D-501 e D-504)
No `ServicoMovimentacoes`, o processamento de novos lotes de um FUTURO deve utilizar transação para evitar inconsistências, com `lockForUpdate`.

```php
namespace App\Services;

use App\Exceptions\{ErroConflito, ErroValidacao};
use App\Models\{Posicao, Movimentacao};
use Illuminate\Support\Facades\DB;
use Illuminate\Database\QueryException;

class ServicoMovimentacoes
{
    public function movimentarFuturo(int $posicaoId, array $dados): Movimentacao
    {
        return DB::transaction(function () use ($posicaoId, $dados) {
            $posicao = Posicao::query()->lockForUpdate()->findOrFail($posicaoId);

            if ($posicao->instrumento !== 'FUTURO') {
                throw new ErroValidacao('Apenas posições a termo (FUTURO) podem ser movimentadas.');
            }

            // Exemplo das RNs:
            // - Conferir RN-022 se redução > saldo -> 422 (ErroValidacao)
            // - Conferir RN-025 data retroativa -> 422 (ErroValidacao)
            
            // Inserir a Movimentacao
            $mov = $posicao->movimentacoes()->create($dados);

            // Replay em RAM para os testes / validação
            // $posicao->futuro->replay(); // Conforme modelo M
            
            // D-504: Atualizar consolidado no Banco para leitura O(1) pelo Motor
            // $posicao->update(['quantidade' => ...]);
            // $posicao->futuro->update(['preco_medio' => ...]);

            return $mov;
        });
    }

    public function criarAbertura(Posicao $posicao, array $dadosAbertura): void
    {
        try {
            $posicao->movimentacoes()->create($dadosAbertura + ['tipo' => 'ABERTURA']);
        } catch (QueryException $e) {
            if ($e->getCode() === '23505') {
                throw new ErroConflito('Movimentação de ABERTURA já existente para este contrato.');
            }
            throw $e;
        }
    }
}
```

### 4.2 Deleção vs Encerramento (D-502)
Em `ServicoPosicoes`, garantir que o `DELETE /posicoes` opere apenas se a posição estiver "virgem".

```php
namespace App\Services;

use App\Models\Posicao;
use App\Exceptions\ErroConflito;

class ServicoPosicoes
{
    public function remover(int $id): void
    {
        $posicao = Posicao::query()->findOrFail($id);
        
        if ($posicao->mtmDiarios()->exists()) {
            throw new ErroConflito('Posição já possui registro de MtM. Utilize a funcionalidade de encerramento em vez de deletar.');
        }

        $posicao->delete(); // Elimina Posição, cascata apaga pernas/movimentações/filhas
    }
}
```

## 5. Estrutura esperada após a Parte 5

```
app/
├── Http/
│   ├── Controllers/Api/V1/PosicaoController.php    (novo)
│   ├── Requests/
│   │   ├── SalvarPosicaoRequest.php                (novo)
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
│       └── PosicaoDetalhe.php                      (novo)
```

## 6. Arquivos a entregar (checklist)

- [ ] `ServicoPosicoes.php` gerindo cadastro (`FUTURO`, `NDF`, `OPCAO`, `OTC`), regras de indexador e integridade `DELETE`.
- [ ] `ServicoMovimentacoes.php` gerindo transações (`DB::transaction` com `lockForUpdate`), catch de `23505` na abertura.
- [ ] DTOs `PosicaoResumo` e `PosicaoDetalhe`.
- [ ] Endpoints `/api/v1/posicoes` com validação de tipagem, delegando lógica de negócio aos Services.
- [ ] Telas Livewire de cadastro de posições e grid com detalhe de movimentação.
- [ ] Feature tests com bateria cobrindo RN-001..006 e RN-020..025.
- [ ] Feature test de "Redução concorrente" validando o funcionamento do lock na tabela `posicao`.
- [ ] `phpstan` nível 8 rodando liso e `pint --test` verificado.

## 7. Definition of Done (critérios de aceite)

1. Endpoints de Posições respondendo a todas as rotas de listagem, deleção (`409` quando devido), encerramento e cadastro, com `Resources` formatados.
2. Fluxo de `FUTURO`: Múltiplas movimentações refletem corretamente na "quantidade consolidada" (sem desativar imutabilidade das movimentações).
3. Tela de Posições Livewire funcionando com formulários adequados por instrumento.
4. Redução Total altera o Status da posição para "ENCERRADA" automaticamente (RN-022).
5. RNs críticas de Validação (Abertura dupla) barram e devolvem o `ErroConflito` sem expor `QueryException` com Stack Trace (erro 500).

## 8. Riscos e pontos a verificar

| Risco | Mitigação / ação |
|---|---|
| **Armadilha do Float e Erro Fracionado (crítica A-1/A-2 e pareces de arquitetura)** | O sistema mantém a adesão à arquitetura `Alternativa A` (uso do `float`), que foi cravada. Porém, eventuais "fechamentos zumbis" (`0.0001` de resto) serão minimizados garantindo que a redução arredonde para 4 casas antes de bater com o saldo, via coerções em `ConverteDecimais`. Fica assinalado para a Fase 11 um possível check de integridade. |
| **Gargalo de Eager Loading no Replay** | O serviço de movimentações (D-504) gravará o "cache" das totalizações (`preco_medio`, `quantidade`) na inserção da movimentação. A query do motor (Fase 6) lerá os campos literais sem instanciar `foreach` gigante. |
| **UX de Estorno / Deleção Imutável** | O sistema trava (RN-025) edições. A mitigação provisória exige que, ocorrendo erro primário antes da rodada do MtM, o usuário apague e recrie (a D-502 permite deleção se `MtMDiarios` inexistente). Se pós-MtM, a falha exigirá suporte até implementação de "Movimentação de Estorno" (fora de escopo para a Parte 5). |

## 9. Referências

- `specs/requisitos.md` (§3.2.3, §4.2, §5.2.3, §7.1, §7.1a).
- `specs/passos_dev.md` (Fase 5 - Módulo Posições).
- `specs/future/pontos_de_atencao.md` (Parecer de Arquitetura: Crítica do MVP).
- `specs/spec_parte_4.md` (Moldes e Estruturação de Services HTTP).

---
**Fim do documento.** Próxima etapa: **Fase 6 — Motor MtM** (`passos_dev.md`), onde o cálculo polimórfico entrará em ação iterando sobre estas posições criadas.
