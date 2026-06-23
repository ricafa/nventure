<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\HistoricoMtmRequest;
use App\Http\Requests\RelatorioRequest;
use App\Services\Dados\ExposicaoProduto;
use App\Services\Dados\PontoHistoricoMtm;
use App\Services\ServicoRelatorios;
use App\Support\Csv\ExportadorCsv;
use Symfony\Component\HttpFoundation\Response;   // supertipo de StreamedResponse (csv) E JsonResponse (A-1/D-707)

/**
 * Relatórios §5.2.5 (Fase 7). 4 endpoints de leitura agregada sobre `mtm_diario`; cada
 * um negocia `formato=json|csv|pdf`. AuthZ por perfil é Fase 10 (D-709). O retorno é
 * tipado `Symfony\...\Response` (supertipo comum de `StreamedResponse` e `JsonResponse`).
 */
class RelatorioController extends Controller
{
    public function __construct(private readonly ServicoRelatorios $rel) {}

    public function posicaoAberta(RelatorioRequest $request): Response
    {
        $dados = $this->rel->posicaoAberta($request->dataRef());

        return $this->responder($request->formato(), $dados->paraArray(), $dados->paraLinhasCsv(), 'posicao-aberta');
    }

    public function plDiario(RelatorioRequest $request): Response
    {
        $dados = $this->rel->plDiario($request->dataRef());

        return $this->responder($request->formato(), $dados->paraArray(), $dados->paraLinhasCsv(), 'pl-diario');
    }

    public function exposicaoLiquida(RelatorioRequest $request): Response
    {
        $dados = $this->rel->exposicaoLiquida($request->dataRef());

        $json = array_map(fn (ExposicaoProduto $e) => $e->paraArray(), $dados);
        /** @var list<array<string, scalar|null>> $csv */
        $csv = array_map(fn (ExposicaoProduto $e) => $this->csvExposicao($e), $dados);

        return $this->responder($request->formato(), ['data' => $request->dataRef(), 'produtos' => $json], $csv, 'exposicao-liquida');
    }

    public function historicoMtm(HistoricoMtmRequest $request): Response
    {
        $pontos = $this->rel->historicoMtm($request->integer('posicao_id'));

        $json = array_map(fn (PontoHistoricoMtm $p) => $p->paraArray(), $pontos);

        return $this->responder($request->formato(), ['posicao_id' => $request->integer('posicao_id'), 'pontos' => $json], $json, 'historico-mtm');
    }

    /**
     * Negocia o formato (D-707): json (flat §5.1), csv (stream endurecido), pdf → 501.
     *
     * @param  array<string, mixed>  $json
     * @param  list<array<string, scalar|null>>  $linhasCsv
     */
    private function responder(string $formato, array $json, array $linhasCsv, string $arquivo): Response
    {
        return match ($formato) {
            'csv' => app(ExportadorCsv::class)->resposta($linhasCsv, "{$arquivo}.csv"),
            // pdf está no contrato (§5.2.5) mas o SERVIDOR ainda não o implementa → 501, não 422.
            'pdf' => response()->json(
                ['erro' => 'FORMATO_INDISPONIVEL', 'mensagem' => 'Exportação em PDF ainda não suportada.'],
                Response::HTTP_NOT_IMPLEMENTED,   // 501
            ),
            default => response()->json($json),
        };
    }

    /**
     * Achata o mix (objeto aninhado) para o CSV (formato tabular plano).
     *
     * @return array<string, scalar|null>
     */
    private function csvExposicao(ExposicaoProduto $e): array
    {
        return [
            'produto_id' => $e->produtoId,
            'produto' => $e->produtoNome,
            'comprado' => round($e->comprado, 4),
            'vendido' => round($e->vendido, 4),
            'liquido' => $e->liquido(),
            'mtm' => round($e->mtm, 2),
            'posicoes' => $e->posicoes,
            'mix_futuro' => $e->mix['FUTURO'],
            'mix_ndf' => $e->mix['NDF'],
            'mix_opcao' => $e->mix['OPCAO'],
            'mix_otc' => $e->mix['OTC'],
            'unidade_mista' => $e->unidadeMista() ? '1' : '0',
        ];
    }
}
