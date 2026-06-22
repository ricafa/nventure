<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\MovimentarFuturoRequest;
use App\Http\Requests\SalvarFuturoRequest;
use App\Http\Requests\SalvarNdfRequest;
use App\Http\Requests\SalvarOpcaoRequest;
use App\Http\Requests\SalvarOtcRequest;
use App\Http\Resources\PosicaoResource;
use App\Services\ServicoMovimentacoes;
use App\Services\ServicoPosicoes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * API de posições (§5.2.3). Controller fino: valida via Form Request, delega ao
 * Service e serializa via Resource/DTO. As RNs e a tradução de erro vivem nos
 * Services. Tudo sob `auth:sanctum`; a AuthZ por perfil (§9.2) é da Fase 10 (D-402).
 */
class PosicaoController extends Controller
{
    public function __construct(
        private readonly ServicoPosicoes $posicoes,
        private readonly ServicoMovimentacoes $movimentacoes,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return PosicaoResource::collection(
            $this->posicoes->listar(
                status: $request->query('status') ? (string) $request->query('status') : null,
                produtoId: $request->integer('produto_id') ?: null,
            ),
        );
    }

    public function show(int $id): PosicaoResource
    {
        return new PosicaoResource($this->posicoes->detalhar($id));
    }

    public function storeFuturo(SalvarFuturoRequest $request): JsonResponse
    {
        $posicao = $this->posicoes->criarFuturo($request->validated());

        return $this->detalheCriado($posicao->id);
    }

    public function storeNdf(SalvarNdfRequest $request): JsonResponse
    {
        $posicao = $this->posicoes->criarNdf($request->validated());

        return $this->detalheCriado($posicao->id);
    }

    public function storeOpcao(SalvarOpcaoRequest $request): JsonResponse
    {
        $posicao = $this->posicoes->criarOpcao($request->validated());

        return $this->detalheCriado($posicao->id);
    }

    public function storeOtc(SalvarOtcRequest $request): JsonResponse
    {
        $posicao = $this->posicoes->criarOtc($request->validated());

        return $this->detalheCriado($posicao->id);
    }

    public function encerrar(int $id): PosicaoResource
    {
        $this->posicoes->encerrar($id);

        return new PosicaoResource($this->posicoes->detalhar($id));
    }

    public function destroy(int $id): Response
    {
        $this->posicoes->remover($id); // 409 se já tem MtM (D-502)

        return response()->noContent();
    }

    public function movimentacoes(int $id): JsonResponse
    {
        // Reaproveita o detalhe (carrega FUTURO + movimentações) e expõe só o histórico.
        $detalhe = $this->posicoes->detalhar($id);

        return response()->json(['data' => $detalhe->movimentacoes]);
    }

    public function movimentar(MovimentarFuturoRequest $request, int $id): JsonResponse
    {
        // §5.2.3: estado recalculado, flat (sem wrap `data`).
        $estado = $this->movimentacoes->movimentarFuturo($id, $request->validated());

        return response()->json($estado->paraArray());
    }

    private function detalheCriado(int $id): JsonResponse
    {
        return (new PosicaoResource($this->posicoes->detalhar($id)))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }
}
