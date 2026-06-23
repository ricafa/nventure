<div>
    <div class="mb-6">
        <p class="text-sm font-medium uppercase tracking-wide text-emerald-700">Mesa de risco</p>
        <h1 class="text-2xl font-semibold">Dashboard · {{ \Carbon\Carbon::parse($data)->format('d/m/Y') }}</h1>
        <p class="mt-1 text-sm text-zinc-500">
            Snapshot do dia
            @if ($ultimaExecucao)
                · última execução do motor em {{ $ultimaExecucao->iniciado_em->format('d/m/Y H:i') }}
            @else
                · motor ainda não executado
            @endif
        </p>
    </div>

    <section class="grid gap-4 md:grid-cols-4">
        <div class="rounded-md border border-zinc-200 bg-white p-5">
            <p class="text-sm text-zinc-500">P&amp;L do dia (BRL)</p>
            <p class="mt-1 text-xl font-semibold {{ $plDiario >= 0 ? 'text-emerald-600' : 'text-red-600' }}">
                {{ number_format($plDiario, 2, ',', '.') }}
            </p>
        </div>
        <div class="rounded-md border border-zinc-200 bg-white p-5">
            <p class="text-sm text-zinc-500">P&amp;L acumulado (BRL)</p>
            <p class="mt-1 text-xl font-semibold {{ $plAcumulado >= 0 ? 'text-emerald-600' : 'text-red-600' }}">
                {{ number_format($plAcumulado, 2, ',', '.') }}
            </p>
        </div>
        <div class="rounded-md border border-zinc-200 bg-white p-5">
            <p class="text-sm text-zinc-500">Posições abertas</p>
            <p class="mt-1 text-xl font-semibold">{{ $posicoesAbertas }}</p>
        </div>
        <div class="rounded-md border border-zinc-200 bg-white p-5">
            <p class="text-sm text-zinc-500">Última execução</p>
            <p class="mt-1 text-xl font-semibold">
                @if ($ultimaExecucao)
                    #{{ $ultimaExecucao->id }}
                    <span class="text-sm font-normal {{ count($ultimaExecucao->falhas ?? []) === 0 ? 'text-emerald-600' : 'text-red-600' }}">
                        {{ $ultimaExecucao->sucessos }}/{{ $ultimaExecucao->total_posicoes }}
                    </span>
                @else
                    —
                @endif
            </p>
        </div>
    </section>

    <section class="mt-8 flex flex-wrap gap-3">
        <a href="{{ route('relatorios.posicao-aberta') }}" class="rounded-md border border-zinc-200 bg-white px-4 py-2 text-sm font-medium text-emerald-700 hover:bg-zinc-50">Posição aberta</a>
        <a href="{{ route('relatorios.pl') }}" class="rounded-md border border-zinc-200 bg-white px-4 py-2 text-sm font-medium text-emerald-700 hover:bg-zinc-50">P&amp;L diário/acumulado</a>
        <a href="{{ route('relatorios.exposicao') }}" class="rounded-md border border-zinc-200 bg-white px-4 py-2 text-sm font-medium text-emerald-700 hover:bg-zinc-50">Exposição líquida</a>
    </section>
</div>
