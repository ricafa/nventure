<div class="space-y-6">
    <div class="flex items-center justify-between">
        <h1 class="text-2xl font-semibold">Produtos</h1>
        <flux:button wire:click="$dispatch('novo-produto')" variant="primary">Novo produto</flux:button>
    </div>

    <livewire:produtos.form-produto />

    <div class="overflow-hidden rounded-md border border-zinc-200 bg-white">
        <table class="w-full text-left text-sm">
            <thead class="border-b border-zinc-200 bg-zinc-50 text-zinc-500">
                <tr>
                    <th class="px-4 py-3 font-medium">Nome</th>
                    <th class="px-4 py-3 font-medium">Unidade</th>
                    <th class="px-4 py-3 font-medium">Bolsa</th>
                    <th class="px-4 py-3 font-medium">Moeda</th>
                    <th class="px-4 py-3 font-medium">Ativo</th>
                    <th class="px-4 py-3 font-medium text-right">Ações</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-100">
                @forelse ($produtos as $produto)
                    <tr wire:key="produto-{{ $produto->id }}" @class(['text-zinc-400' => ! $produto->ativo])>
                        <td class="px-4 py-3 font-medium">{{ $produto->nome }}</td>
                        <td class="px-4 py-3">{{ $produto->unidade }}</td>
                        <td class="px-4 py-3">{{ $produto->bolsa_ref }}</td>
                        <td class="px-4 py-3">{{ $produto->moeda_cotacao }}</td>
                        <td class="px-4 py-3">
                            @if ($produto->ativo)
                                <span class="rounded-full bg-emerald-50 px-2 py-0.5 text-xs text-emerald-700">Ativo</span>
                            @else
                                <span class="rounded-full bg-zinc-100 px-2 py-0.5 text-xs text-zinc-500">Inativo</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-right">
                            <flux:button size="sm" variant="ghost"
                                wire:click="$dispatch('editar-produto', { id: {{ $produto->id }} })">Editar</flux:button>
                            @if ($produto->ativo)
                                <flux:button size="sm" variant="ghost"
                                    wire:click="inativar({{ $produto->id }})"
                                    wire:confirm="Inativar este produto?">Inativar</flux:button>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-4 py-6 text-center text-zinc-400">Nenhum produto cadastrado.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
