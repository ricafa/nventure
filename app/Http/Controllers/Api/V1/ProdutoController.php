<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\SalvarProdutoRequest;
use App\Http\Resources\ProdutoResource;
use App\Services\ServicoProdutos;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * API de produtos (§5.2.1). Controller fino: injeta o serviço, valida via Form
 * Request, serializa via Resource. As RNs e a tradução de erro vivem no Service.
 */
class ProdutoController extends Controller
{
    public function __construct(private readonly ServicoProdutos $servico) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return ProdutoResource::collection(
            $this->servico->listar(apenasAtivos: $request->boolean('apenas_ativos')),
        );
    }

    public function show(int $id): ProdutoResource
    {
        return new ProdutoResource($this->servico->buscar($id));
    }

    public function store(SalvarProdutoRequest $request): JsonResponse
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
        $this->servico->inativar($id);   // soft delete (D-405)

        return response()->noContent();
    }
}
