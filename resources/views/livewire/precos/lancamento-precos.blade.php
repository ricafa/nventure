<div class="space-y-8">
    <h1 class="text-2xl font-semibold">Preços de referência</h1>

    <div class="grid gap-6 lg:grid-cols-2">
        {{-- (a) Lançamento manual --}}
        <form wire:submit="lancar" class="space-y-4 rounded-md border border-zinc-200 bg-white p-5">
            <h2 class="text-lg font-semibold">Lançar preço</h2>

            <flux:select wire:model="produto_id" label="Produto">
                <option value="">Selecione…</option>
                @foreach ($produtos as $produto)
                    <option value="{{ $produto->id }}">{{ $produto->nome }}</option>
                @endforeach
            </flux:select>

            <flux:input type="date" wire:model="data_preco" label="Data do preço" />
            <flux:input wire:model="preco_fechamento" label="Preço de fechamento" />
            <flux:input wire:model="cambio_brl" label="Câmbio (BRL)" />

            @error('produto_id') <p class="text-sm text-red-600">{{ $message }}</p> @enderror
            @error('data_preco') <p class="text-sm text-red-600">{{ $message }}</p> @enderror
            @error('preco_fechamento') <p class="text-sm text-red-600">{{ $message }}</p> @enderror
            @error('cambio_brl') <p class="text-sm text-red-600">{{ $message }}</p> @enderror

            <flux:button type="submit" variant="primary">Lançar</flux:button>
        </form>

        {{-- (b) Upload CSV --}}
        <form wire:submit="importar" class="space-y-4 rounded-md border border-zinc-200 bg-white p-5">
            <h2 class="text-lg font-semibold">Importar CSV</h2>
            <p class="text-sm text-zinc-500">
                Cabeçalho esperado: <code>produto_id,data_preco,preco_fechamento,cambio_brl</code>.
                Aceita também CSV do Excel pt-BR (delimitador <code>;</code>, decimal <code>,</code>).
            </p>

            <input type="file" wire:model="arquivo" accept=".csv,.txt"
                class="block w-full text-sm text-zinc-700 file:mr-3 file:rounded-md file:border-0 file:bg-emerald-600 file:px-4 file:py-2 file:text-white">

            @error('arquivo') <p class="text-sm text-red-600">{{ $message }}</p> @enderror

            <flux:button type="submit" variant="primary">Importar</flux:button>

            @if ($resultadoImportacao !== null)
                <div class="space-y-2 rounded-md border border-zinc-200 bg-zinc-50 p-4">
                    <p class="text-sm">
                        <span class="font-medium text-emerald-700">{{ $resultadoImportacao['aceitas'] }} aceitas</span>
                        /
                        <span class="font-medium text-red-600">{{ count($resultadoImportacao['rejeitadas']) }} rejeitadas</span>
                        (de {{ $resultadoImportacao['total'] }})
                    </p>
                    @if (! empty($resultadoImportacao['rejeitadas']))
                        <table class="w-full text-left text-xs">
                            <thead class="text-zinc-500">
                                <tr><th class="py-1 pr-4">Linha</th><th class="py-1">Motivo</th></tr>
                            </thead>
                            <tbody>
                                @foreach ($resultadoImportacao['rejeitadas'] as $rejeitada)
                                    <tr>
                                        <td class="py-1 pr-4">{{ $rejeitada['linha'] }}</td>
                                        <td class="py-1">{{ $rejeitada['motivo'] }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    @endif
                </div>
            @endif
        </form>
    </div>

    {{-- Listagem com filtros --}}
    <div class="space-y-4">
        <div class="flex flex-wrap items-end gap-4">
            <flux:select wire:model.live="filtroProduto" label="Filtrar por produto">
                <option value="">Todos</option>
                @foreach ($produtos as $produto)
                    <option value="{{ $produto->id }}">{{ $produto->nome }}</option>
                @endforeach
            </flux:select>
            <flux:input type="date" wire:model.live="filtroInicio" label="De" />
            <flux:input type="date" wire:model.live="filtroFim" label="Até" />
        </div>

        @error('remocao') <p class="text-sm text-red-600">{{ $message }}</p> @enderror

        <div class="overflow-hidden rounded-md border border-zinc-200 bg-white">
            <table class="w-full text-left text-sm">
                <thead class="border-b border-zinc-200 bg-zinc-50 text-zinc-500">
                    <tr>
                        <th class="px-4 py-3 font-medium">Produto</th>
                        <th class="px-4 py-3 font-medium">Data</th>
                        <th class="px-4 py-3 font-medium text-right">Fechamento</th>
                        <th class="px-4 py-3 font-medium text-right">Câmbio</th>
                        <th class="px-4 py-3 font-medium text-right">Ações</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100">
                    @forelse ($precos as $preco)
                        <tr wire:key="preco-{{ $preco->id }}">
                            <td class="px-4 py-3">{{ $preco->produto_id }}</td>
                            <td class="px-4 py-3">{{ $preco->data_preco->toDateString() }}</td>
                            <td class="px-4 py-3 text-right">{{ $preco->preco_fechamento }}</td>
                            <td class="px-4 py-3 text-right">{{ $preco->cambio_brl }}</td>
                            <td class="px-4 py-3 text-right">
                                <flux:button size="sm" variant="ghost"
                                    wire:click="remover({{ $preco->id }})"
                                    wire:confirm="Remover este preço?">Remover</flux:button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-4 py-6 text-center text-zinc-400">Nenhum preço lançado.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
