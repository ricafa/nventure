<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ListarPrecosRequest;
use App\Http\Requests\SalvarPrecoRequest;
use App\Http\Requests\UploadPrecosRequest;
use App\Http\Resources\PrecoReferenciaResource;
use App\Services\ServicoPrecos;
use App\Support\Csv\ImportadorPrecosCsv;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * API de preços de referência (§5.2.2), incluindo upload em lote.
 */
class PrecoController extends Controller
{
    public function __construct(private readonly ServicoPrecos $servico) {}

    public function index(ListarPrecosRequest $request): AnonymousResourceCollection
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
