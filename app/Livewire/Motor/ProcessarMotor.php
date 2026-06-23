<?php

declare(strict_types=1);

namespace App\Livewire\Motor;

use App\Models\MotorExecucao;
use App\Models\Usuario;
use App\Services\ServicoMotor;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('components.layouts.app')]
class ProcessarMotor extends Component
{
    #[Url]
    public string $dataCalculo;

    /** @var array{execucao_id: int, data_calculo: string, posicoes_processadas: int, sucessos: int, falhas: list<array{posicao_id: int, motivo: string}>}|null */
    public ?array $resumo = null;

    public function mount(): void
    {
        $this->dataCalculo = now()->toDateString();
    }

    public function disparar(ServicoMotor $motor): void
    {
        $usuario = Auth::user();

        $resumo = $motor->processar(
            new \DateTimeImmutable($this->dataCalculo),
            $usuario instanceof Usuario ? $usuario->login : 'sistema'
        );

        $this->resumo = $resumo->paraArray();
    }

    public function render(): mixed
    {
        return view('livewire.motor.processar-motor', [
            'execucoes' => MotorExecucao::query()
                ->orderByDesc('id')
                ->limit(20)
                ->get(),
        ]);
    }
}
