<div>
    @if ($aberto)
        <form wire:submit="salvar" class="space-y-4 rounded-md border border-zinc-200 bg-white p-5">
            <h2 class="text-lg font-semibold">
                {{ $produtoId ? 'Editar produto' : 'Novo produto' }}
            </h2>

            <div class="grid gap-4 md:grid-cols-2">
                <flux:input wire:model="nome" label="Nome" />
                <flux:input wire:model="unidade" label="Unidade" />
                <flux:input wire:model="bolsa_ref" label="Bolsa de referência" />
                <flux:input wire:model="moeda_cotacao" label="Moeda (ISO, 3 letras)" maxlength="3" />
            </div>

            <flux:checkbox wire:model="ativo" label="Ativo" />

            @error('nome')
                <p class="text-sm text-red-600">{{ $message }}</p>
            @enderror

            <div class="flex gap-2">
                <flux:button type="submit" variant="primary">Salvar</flux:button>
                <flux:button type="button" variant="ghost" wire:click="cancelar">Cancelar</flux:button>
            </div>
        </form>
    @endif
</div>
