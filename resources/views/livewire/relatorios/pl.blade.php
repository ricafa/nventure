<div>
    <div class="mb-6 flex flex-wrap items-end justify-between gap-4">
        <div>
            <p class="text-sm font-medium uppercase tracking-wide text-emerald-700">Relatórios · P&amp;L</p>
            <h1 class="text-2xl font-semibold">P&amp;L diário e acumulado</h1>
            <p class="mt-1 text-sm text-zinc-500">RN-017 soma variacao_dia · RN-018 soma pl_acumulado das abertas</p>
        </div>
        <div>
            <label class="block text-xs font-medium text-zinc-600">Data</label>
            <input type="date" wire:model.live="data" class="mt-1 rounded-md border-zinc-300 text-sm shadow-sm">
        </div>
    </div>

    <section class="mb-6 grid gap-4 md:grid-cols-4">
        <div class="rounded-md border border-zinc-200 bg-white p-5">
            <p class="text-sm text-zinc-500">P&amp;L acumulado</p>
            <p class="mt-1 text-xl font-semibold {{ $resumo->plAcumulado >= 0 ? 'text-emerald-600' : 'text-red-600' }}">{{ number_format($resumo->plAcumulado, 2, ',', '.') }}</p>
        </div>
        <div class="rounded-md border border-zinc-200 bg-white p-5">
            <p class="text-sm text-zinc-500">P&amp;L do dia</p>
            <p class="mt-1 text-xl font-semibold {{ $resumo->plDiario >= 0 ? 'text-emerald-600' : 'text-red-600' }}">{{ number_format($resumo->plDiario, 2, ',', '.') }}</p>
        </div>
        <div class="rounded-md border border-zinc-200 bg-white p-5">
            <p class="text-sm text-zinc-500">Melhor pregão</p>
            <p class="mt-1 text-xl font-semibold text-emerald-600">{{ number_format($melhorPregao, 2, ',', '.') }}</p>
        </div>
        <div class="rounded-md border border-zinc-200 bg-white p-5">
            <p class="text-sm text-zinc-500">Pior pregão</p>
            <p class="mt-1 text-xl font-semibold text-red-600">{{ number_format($piorPregao, 2, ',', '.') }}</p>
        </div>
    </section>

    <div class="mb-6 rounded-md border border-zinc-200 bg-white">
        <div class="border-b border-zinc-100 px-4 py-3">
            <h3 class="font-medium">Série temporal</h3>
            <p class="text-xs text-zinc-400">P&amp;L diário (variacao_dia) e acumulado (pl_acumulado) por pregão</p>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-zinc-200 text-sm">
                <thead class="bg-zinc-50 text-left text-xs uppercase text-zinc-500">
                    <tr>
                        <th class="px-4 py-2">Data</th>
                        <th class="px-4 py-2 text-right">P&amp;L do dia</th>
                        <th class="px-4 py-2 text-right">P&amp;L acumulado</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100">
                    @forelse ($resumo->serie as $ponto)
                        <tr>
                            <td class="px-4 py-2">{{ \Carbon\Carbon::parse($ponto['data'])->format('d/m/Y') }}</td>
                            <td class="px-4 py-2 text-right {{ $ponto['pl_dia'] >= 0 ? 'text-emerald-600' : 'text-red-600' }}">{{ number_format($ponto['pl_dia'], 2, ',', '.') }}</td>
                            <td class="px-4 py-2 text-right {{ $ponto['pl_acumulado'] >= 0 ? 'text-emerald-600' : 'text-red-600' }}">{{ number_format($ponto['pl_acumulado'], 2, ',', '.') }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="3" class="px-4 py-6 text-center text-zinc-500">Sem histórico de MtM até a data.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="rounded-md border border-zinc-200 bg-white">
        <div class="border-b border-zinc-100 px-4 py-3">
            <h3 class="font-medium">Detalhe por posição</h3>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-zinc-200 text-sm">
                <thead class="bg-zinc-50 text-left text-xs uppercase text-zinc-500">
                    <tr>
                        <th class="px-4 py-2">#</th>
                        <th class="px-4 py-2">Produto</th>
                        <th class="px-4 py-2">Tipo</th>
                        <th class="px-4 py-2">Lado</th>
                        <th class="px-4 py-2 text-right">MtM</th>
                        <th class="px-4 py-2 text-right">Δ D−1</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100">
                    @foreach ($posicoes as $p)
                        <tr>
                            <td class="px-4 py-2 text-zinc-400">{{ $p->posicaoId }}</td>
                            <td class="px-4 py-2">{{ $p->produtoNome }}</td>
                            <td class="px-4 py-2">{{ $p->instrumento }}</td>
                            <td class="px-4 py-2">{{ $p->lado }}</td>
                            <td class="px-4 py-2 text-right {{ $p->mtm >= 0 ? 'text-emerald-600' : 'text-red-600' }}">{{ number_format($p->mtm, 2, ',', '.') }}</td>
                            <td class="px-4 py-2 text-right {{ $p->variacaoDia >= 0 ? 'text-emerald-600' : 'text-red-600' }}">{{ number_format($p->variacaoDia, 2, ',', '.') }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</div>
