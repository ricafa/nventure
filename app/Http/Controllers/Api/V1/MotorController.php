<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ProcessarMotorRequest;
use App\Http\Resources\ExecucaoMotorResource;
use App\Models\Usuario;
use App\Services\ServicoMotor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Auth;

class MotorController extends Controller
{
    public function __construct(private readonly ServicoMotor $motor) {}

    public function processar(ProcessarMotorRequest $request): JsonResponse
    {
        $usuario = Auth::user();

        $resumo = $this->motor->processar(
            new \DateTimeImmutable($request->validated('data_calculo')),
            $usuario instanceof Usuario ? $usuario->login : 'sistema'
        );

        return response()->json($resumo->paraArray()); // 200 (D-611), flat
    }

    public function index(): AnonymousResourceCollection
    {
        return ExecucaoMotorResource::collection($this->motor->listar());
    }

    public function show(int $id): ExecucaoMotorResource
    {
        return new ExecucaoMotorResource($this->motor->detalhar($id));
    }
}
