<div>
    <div class="mb-6 flex flex-wrap items-end justify-between gap-4">
        <div>
            <p class="text-sm font-medium uppercase tracking-wide text-emerald-700">Relatórios · Posição aberta</p>
            <h1 class="text-2xl font-semibold">Posição aberta consolidada</h1>
            <p class="mt-1 text-sm text-zinc-500">Snapshot em {{ \Carbon\Carbon::parse($relatorio->data)->format('d/m/Y') }} · {{ count($relatorio->linhas) }} posições · RN-016</p>
        </div>
        <div class="flex items-end gap-3">
            <div>
                <label class="block text-xs font-medium text-zinc-600">Data</label>
                <input type="date" wire:model.live="data" class="mt-1 rounded-md border-zinc-300 text-sm shadow-sm">
            </div>
            <div class="flex rounded-md border border-zinc-300 text-sm">
                <button wire:click="$set('agrupar', 'produto')" class="px-3 py-2 {{ $agrupar === 'produto' ? 'bg-emerald-600 text-white' : 'text-zinc-600' }}">Por produto</button>
                <button wire:click="$set('agrupar', 'tipo')" class="px-3 py-2 {{ $agrupar === 'tipo' ? 'bg-emerald-600 text-white' : 'text-zinc-600' }}">Por tipo</button>
            </div>
        </div>
    </div>

    <section class="mb-6 grid gap-4 md:grid-cols-3">
        <div class="rounded-md border border-zinc-200 bg-white p-5">
            <p class="text-sm text-zinc-500">Posições abertas</p>
            <p class="mt-1 text-xl font-semibold">{{ count($relatorio->linhas) }}</p>
        </div>
        <div class="rounded-md border border-zinc-200 bg-white p-5">
            <p class="text-sm text-zinc-500">MtM consolidado (BRL)</p>
            <p class="mt-1 text-xl font-semibold {{ $relatorio->totalMtm() >= 0 ? 'text-emerald-600' : 'text-red-600' }}">{{ number_format($relatorio->totalMtm(), 2, ',', '.') }}</p>
        </div>
        <div class="rounded-md border border-zinc-200 bg-white p-5">
            <p class="text-sm text-zinc-500">Variação do dia (BRL)</p>
            <p class="mt-1 text-xl font-semibold {{ $relatorio->totalVariacao() >= 0 ? 'text-emerald-600' : 'text-red-600' }}">{{ number_format($relatorio->totalVariacao(), 2, ',', '.') }}</p>
        </div>
    </section>

    @forelse ($grupos as $nome => $linhas)
        <div class="mb-4 rounded-md border border-zinc-200 bg-white">
            <div class="flex items-center justify-between border-b border-zinc-100 px-4 py-3">
                <h3 class="font-medium">{{ $nome }} <span class="ml-2 text-xs text-zinc-400">{{ count($linhas) }} posições</span></h3>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-zinc-200 text-sm">
                    <thead class="bg-zinc-50 text-left text-xs uppercase text-zinc-500">
                        <tr>
                            <th class="px-4 py-2">#</th>
                            <th class="px-4 py-2">{{ $agrupar === 'produto' ? 'Tipo' : 'Produto' }}</th>
                            <th class="px-4 py-2">Lado</th>
                            <th class="px-4 py-2 text-right">Quantidade</th>
                            <th class="px-4 py-2 text-right">Preço médio</th>
                            <th class="px-4 py-2 text-right">Preço mercado</th>
                            <th class="px-4 py-2">Vencimento</th>
                            <th class="px-4 py-2 text-right">MtM (BRL)</th>
                            <th class="px-4 py-2 text-right">Δ D−1</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-100">
                        @foreach ($linhas as $l)
                            <tr>
                                <td class="px-4 py-2 text-zinc-400">{{ $l->posicaoId }}</td>
                                <td class="px-4 py-2">{{ $agrupar === 'produto' ? $l->instrumento : $l->produtoNome }}</td>
                                <td class="px-4 py-2">{{ $l->lado }}</td>
                                <td class="px-4 py-2 text-right">{{ number_format($l->quantidade, 2, ',', '.') }}</td>
                                <td class="px-4 py-2 text-right">{{ $l->precoMedio !== null ? number_format($l->precoMedio, 4, ',', '.') : '—' }}</td>
                                <td class="px-4 py-2 text-right">{{ $l->precoMercado !== null ? number_format($l->precoMercado, 4, ',', '.') : '—' }}</td>
                                <td class="px-4 py-2">{{ \Carbon\Carbon::parse($l->dataVencimento)->format('d/m/Y') }}</td>
                                <td class="px-4 py-2 text-right {{ $l->mtm >= 0 ? 'text-emerald-600' : 'text-red-600' }}">
                                    {{ $l->temMtm ? number_format($l->mtm, 2, ',', '.') : 'sem MtM' }}
                                </td>
                                <td class="px-4 py-2 text-right {{ $l->variacaoDia >= 0 ? 'text-emerald-600' : 'text-red-600' }}">{{ number_format($l->variacaoDia, 2, ',', '.') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @empty
        <p class="rounded-md border border-zinc-200 bg-white p-6 text-center text-sm text-zinc-500">Nenhuma posição aberta.</p>
    @endforelse
</div>
