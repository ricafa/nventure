<div class="space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-semibold">Posições do portfólio</h1>
            <p class="text-sm text-zinc-500">{{ $posicoes->total() }} registro(s)</p>
        </div>
        <flux:button :href="route('posicoes.nova')" variant="primary" wire:navigate>Nova posição</flux:button>
    </div>

    @if (session('status'))
        <div class="rounded-md bg-emerald-50 px-4 py-3 text-sm text-emerald-700">{{ session('status') }}</div>
    @endif

    <div class="flex flex-wrap items-center gap-3">
        <select wire:model.live="status" class="rounded-md border-zinc-200 text-sm">
            <option value="ABERTA">Aberta</option>
            <option value="ENCERRADA">Encerrada</option>
            <option value="VENCIDA">Vencida</option>
            <option value="TODAS">Todas</option>
        </select>
        <select wire:model.live="tipo" class="rounded-md border-zinc-200 text-sm">
            <option value="TODOS">Todos tipos</option>
            <option value="FUTURO">FUTURO</option>
            <option value="NDF">NDF</option>
            <option value="OPCAO">OPCAO</option>
            <option value="OTC">OTC</option>
        </select>
        <select wire:model.live="produtoId" class="rounded-md border-zinc-200 text-sm">
            <option value="">Todos produtos</option>
            @foreach ($produtos as $id => $nome)
                <option value="{{ $id }}">{{ $nome }}</option>
            @endforeach
        </select>
    </div>

    <div class="overflow-hidden rounded-md border border-zinc-200 bg-white">
        <table class="w-full text-left text-sm">
            <thead class="border-b border-zinc-200 bg-zinc-50 text-zinc-500">
                <tr>
                    <th class="px-4 py-3 font-medium">#</th>
                    <th class="px-4 py-3 font-medium">Produto</th>
                    <th class="px-4 py-3 font-medium">Tipo</th>
                    <th class="px-4 py-3 font-medium">Lado</th>
                    <th class="px-4 py-3 font-medium text-right">Quantidade</th>
                    <th class="px-4 py-3 font-medium">Entrada → Venc.</th>
                    <th class="px-4 py-3 font-medium">Contraparte</th>
                    <th class="px-4 py-3 font-medium">Status</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-100">
                @forelse ($posicoes as $posicao)
                    <tr wire:key="posicao-{{ $posicao->id }}"
                        class="cursor-pointer hover:bg-zinc-50"
                        wire:click="$dispatch('ver-posicao', { id: {{ $posicao->id }} })">
                        <td class="px-4 py-3 font-mono text-zinc-400">{{ $posicao->id }}</td>
                        <td class="px-4 py-3">{{ $produtos[$posicao->produtoId] ?? $posicao->produtoId }}</td>
                        <td class="px-4 py-3">
                            <span class="rounded-full bg-amber-50 px-2 py-0.5 text-xs text-amber-700">{{ $posicao->instrumento }}</span>
                        </td>
                        <td class="px-4 py-3">{{ $posicao->lado }}</td>
                        <td class="px-4 py-3 text-right font-mono">{{ number_format($posicao->quantidade, 2, ',', '.') }}</td>
                        <td class="px-4 py-3 font-mono text-xs text-zinc-500">{{ $posicao->dataEntrada }} → {{ $posicao->dataVencimento }}</td>
                        <td class="px-4 py-3 text-zinc-500">{{ $posicao->contraparte ?? '—' }}</td>
                        <td class="px-4 py-3">
                            @if ($posicao->status === 'ABERTA')
                                <span class="rounded-full bg-emerald-50 px-2 py-0.5 text-xs text-emerald-700">ABERTA</span>
                            @elseif ($posicao->status === 'ENCERRADA')
                                <span class="rounded-full bg-zinc-100 px-2 py-0.5 text-xs text-zinc-500">ENCERRADA</span>
                            @else
                                <span class="rounded-full bg-amber-100 px-2 py-0.5 text-xs text-amber-700">VENCIDA</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" class="px-4 py-6 text-center text-zinc-400">Nenhuma posição encontrada.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div>{{ $posicoes->links() }}</div>

    <livewire:posicoes.detalhe-posicao />
</div>
