<div>
    <div class="mb-6 flex flex-wrap items-end justify-between gap-4">
        <div>
            <p class="text-sm font-medium uppercase tracking-wide text-emerald-700">Relatórios · Exposição líquida</p>
            <h1 class="text-2xl font-semibold">Exposição líquida por produto</h1>
            <p class="mt-1 text-sm text-zinc-500">Em {{ \Carbon\Carbon::parse($data)->format('d/m/Y') }} · RN-019 soma de (quantidade × sinal)</p>
        </div>
        <div>
            <label class="block text-xs font-medium text-zinc-600">Data</label>
            <input type="date" wire:model.live="data" class="mt-1 rounded-md border-zinc-300 text-sm shadow-sm">
        </div>
    </div>

    <div class="rounded-md border border-zinc-200 bg-white">
        <div class="border-b border-zinc-100 px-4 py-3">
            <h3 class="font-medium">Visão consolidada</h3>
            <p class="text-xs text-zinc-400">Comprado vs. vendido · agrupado por produto</p>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-zinc-200 text-sm">
                <thead class="bg-zinc-50 text-left text-xs uppercase text-zinc-500">
                    <tr>
                        <th class="px-4 py-2">Produto</th>
                        <th class="px-4 py-2">Mix de instrumentos</th>
                        <th class="px-4 py-2 text-right">Comprado</th>
                        <th class="px-4 py-2 text-right">Vendido</th>
                        <th class="px-4 py-2 text-right">Líquido</th>
                        <th class="px-4 py-2 text-right">MtM (BRL)</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100">
                    @forelse ($produtos as $e)
                        <tr>
                            <td class="px-4 py-2">
                                <div class="font-medium">{{ $e->produtoNome }}</div>
                                <div class="text-xs text-zinc-400">{{ $e->posicoes }} posições</div>
                                @if ($e->unidadeMista())
                                    <div class="mt-1 inline-block rounded bg-amber-100 px-2 py-0.5 text-xs text-amber-700" title="O líquido soma contratos (FUTURO/OTC) com nocional em moeda (NDF) — unidades diferentes (D-705a)">⚠ unidade mista</div>
                                @endif
                            </td>
                            <td class="px-4 py-2">
                                <div class="flex flex-wrap gap-1">
                                    @foreach ($e->mix as $tipo => $n)
                                        @if ($n > 0)
                                            <span class="rounded bg-zinc-100 px-2 py-0.5 text-xs text-zinc-600">{{ $tipo }} ×{{ $n }}</span>
                                        @endif
                                    @endforeach
                                </div>
                            </td>
                            <td class="px-4 py-2 text-right text-emerald-600">{{ number_format($e->comprado, 2, ',', '.') }}</td>
                            <td class="px-4 py-2 text-right text-red-600">{{ number_format($e->vendido, 2, ',', '.') }}</td>
                            <td class="px-4 py-2 text-right font-semibold {{ $e->liquido() >= 0 ? 'text-emerald-600' : 'text-red-600' }}">{{ number_format($e->liquido(), 2, ',', '.') }}</td>
                            <td class="px-4 py-2 text-right {{ $e->mtm >= 0 ? 'text-emerald-600' : 'text-red-600' }}">{{ number_format($e->mtm, 2, ',', '.') }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-4 py-6 text-center text-zinc-500">Nenhuma posição aberta.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    @if (collect($produtos)->contains(fn ($e) => $e->unidadeMista()))
        <p class="mt-4 rounded-md border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800">
            Alguns produtos somam grandezas de unidades diferentes no líquido (contratos do FUTURO/OTC + nocional em moeda do NDF). Veja o mix de instrumentos por produto (D-705a).
        </p>
    @endif
</div>
