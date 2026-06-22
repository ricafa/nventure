<div class="mx-auto max-w-3xl space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-semibold">Nova posição</h1>
            <p class="text-sm text-zinc-500">Formulário dinâmico · POST /posicoes/{{ strtolower($tipo) }}</p>
        </div>
        <flux:button :href="route('posicoes.index')" variant="ghost" wire:navigate>Voltar</flux:button>
    </div>

    @error('tipo')<div class="rounded-md bg-red-50 px-4 py-3 text-sm text-red-700">{{ $message }}</div>@enderror

    <form wire:submit="salvar" class="space-y-6">
        <div class="space-y-3 rounded-md border border-zinc-200 p-4">
            <div class="text-xs font-medium text-zinc-500">Tipo de instrumento</div>
            <div class="flex flex-wrap gap-2">
                @foreach (['FUTURO', 'NDF', 'OPCAO', 'OTC'] as $t)
                    <button type="button" wire:click="$set('tipo', '{{ $t }}')"
                        @class([
                            'rounded-md border px-4 py-2 text-sm',
                            'border-amber-500 bg-amber-50 text-amber-700' => $tipo === $t,
                            'border-zinc-200' => $tipo !== $t,
                        ])>{{ $t }}</button>
                @endforeach
            </div>
        </div>

        <div class="grid grid-cols-2 gap-4 rounded-md border border-zinc-200 p-4">
            <div class="col-span-2 text-xs font-medium text-zinc-500">Atributos comuns (tabela posicao)</div>

            <label class="text-sm">Produto
                <select wire:model="produtoId" class="mt-1 w-full rounded-md border-zinc-200 text-sm">
                    <option value="">Selecione…</option>
                    @foreach ($produtos as $produto)
                        <option value="{{ $produto->id }}">{{ $produto->nome }}</option>
                    @endforeach
                </select>
                @error('produtoId')<span class="text-xs text-red-600">{{ $message }}</span>@enderror
            </label>

            <label class="text-sm">Mercado
                <select wire:model.live="mercado" class="mt-1 w-full rounded-md border-zinc-200 text-sm">
                    <option value="BOLSA">BOLSA</option>
                    <option value="BALCAO">BALCAO</option>
                </select>
            </label>

            <label class="text-sm">Lado
                <select wire:model="lado" class="mt-1 w-full rounded-md border-zinc-200 text-sm">
                    <option value="COMPRADO">COMPRADO</option>
                    <option value="VENDIDO">VENDIDO</option>
                </select>
            </label>

            @if ($tipo !== 'OPCAO')
                <label class="text-sm">Quantidade
                    <input type="number" step="any" wire:model="quantidade" class="mt-1 w-full rounded-md border-zinc-200 text-sm">
                    @error('quantidade')<span class="text-xs text-red-600">{{ $message }}</span>@enderror
                </label>
            @endif

            <label class="text-sm">Data de entrada
                <input type="date" wire:model="dataEntrada" class="mt-1 w-full rounded-md border-zinc-200 text-sm">
                @error('dataEntrada')<span class="text-xs text-red-600">{{ $message }}</span>@enderror
            </label>

            <label class="text-sm">Data de vencimento
                <input type="date" wire:model="dataVencimento" class="mt-1 w-full rounded-md border-zinc-200 text-sm">
                @error('dataVencimento')<span class="text-xs text-red-600">{{ $message }}</span>@enderror
            </label>

            @if ($mercado === 'BALCAO')
                <label class="text-sm col-span-2">Contraparte
                    <input type="text" wire:model="contraparte" class="mt-1 w-full rounded-md border-zinc-200 text-sm">
                    @error('contraparte')<span class="text-xs text-red-600">{{ $message }}</span>@enderror
                </label>
            @endif

            <label class="text-sm col-span-2">Observações
                <textarea wire:model="observacoes" class="mt-1 w-full rounded-md border-zinc-200 text-sm"></textarea>
            </label>
        </div>

        <div class="grid grid-cols-2 gap-4 rounded-md border border-zinc-200 p-4">
            <div class="col-span-2 text-xs font-medium text-zinc-500">Campos específicos · posicao_{{ strtolower($tipo) }}</div>

            @if ($tipo === 'FUTURO')
                <label class="text-sm">Preço de entrada
                    <input type="number" step="any" wire:model="precoEntrada" class="mt-1 w-full rounded-md border-zinc-200 text-sm">
                    @error('precoEntrada')<span class="text-xs text-red-600">{{ $message }}</span>@enderror
                </label>
                <label class="text-sm">Código do contrato
                    <input type="text" wire:model="codigoContrato" class="mt-1 w-full rounded-md border-zinc-200 text-sm">
                    @error('codigoContrato')<span class="text-xs text-red-600">{{ $message }}</span>@enderror
                </label>
            @elseif ($tipo === 'NDF')
                <label class="text-sm">Taxa contratada
                    <input type="number" step="any" wire:model="taxaContratada" class="mt-1 w-full rounded-md border-zinc-200 text-sm">
                    @error('taxaContratada')<span class="text-xs text-red-600">{{ $message }}</span>@enderror
                </label>
                <label class="text-sm">Valor nocional
                    <input type="number" step="any" wire:model="valorNocional" class="mt-1 w-full rounded-md border-zinc-200 text-sm">
                    @error('valorNocional')<span class="text-xs text-red-600">{{ $message }}</span>@enderror
                </label>
                <label class="text-sm">Moeda do nocional
                    <input type="text" wire:model="moedaNocional" maxlength="3" class="mt-1 w-full rounded-md border-zinc-200 text-sm">
                    @error('moedaNocional')<span class="text-xs text-red-600">{{ $message }}</span>@enderror
                </label>
            @elseif ($tipo === 'OTC')
                <label class="text-sm">Preço de entrada
                    <input type="number" step="any" wire:model="precoEntrada" class="mt-1 w-full rounded-md border-zinc-200 text-sm">
                    @error('precoEntrada')<span class="text-xs text-red-600">{{ $message }}</span>@enderror
                </label>
                <label class="text-sm">Indexador
                    <input type="text" wire:model="indexador" class="mt-1 w-full rounded-md border-zinc-200 text-sm">
                    @error('indexador')<span class="text-xs text-red-600">{{ $message }}</span>@enderror
                </label>
                <label class="text-sm">Prêmio OTC
                    <input type="number" step="any" wire:model="premioOtc" class="mt-1 w-full rounded-md border-zinc-200 text-sm">
                </label>
            @elseif ($tipo === 'OPCAO')
                <label class="text-sm col-span-2">Nome da estrutura
                    <input type="text" wire:model="nomeEstrutura" class="mt-1 w-full rounded-md border-zinc-200 text-sm">
                </label>
                <div class="col-span-2 space-y-3">
                    @error('pernas')<p class="text-xs text-red-600">{{ $message }}</p>@enderror
                    @foreach ($pernas as $i => $perna)
                        <div wire:key="perna-{{ $i }}" class="grid grid-cols-6 items-end gap-2 rounded-md border border-zinc-100 p-3">
                            <label class="text-xs">Tipo
                                <select wire:model="pernas.{{ $i }}.tipo_opcao" class="mt-1 w-full rounded-md border-zinc-200 text-sm">
                                    <option value="CALL">CALL</option><option value="PUT">PUT</option>
                                </select>
                            </label>
                            <label class="text-xs">Estilo
                                <select wire:model="pernas.{{ $i }}.estilo" class="mt-1 w-full rounded-md border-zinc-200 text-sm">
                                    <option value="EUROPEIA">EUROPEIA</option><option value="AMERICANA">AMERICANA</option>
                                </select>
                            </label>
                            <label class="text-xs">Strike
                                <input type="number" step="any" wire:model="pernas.{{ $i }}.strike" class="mt-1 w-full rounded-md border-zinc-200 text-sm">
                            </label>
                            <label class="text-xs">Prêmio
                                <input type="number" step="any" wire:model="pernas.{{ $i }}.premio_pago" class="mt-1 w-full rounded-md border-zinc-200 text-sm">
                            </label>
                            <label class="text-xs">Qtde
                                <input type="number" step="any" wire:model="pernas.{{ $i }}.quantidade" class="mt-1 w-full rounded-md border-zinc-200 text-sm">
                            </label>
                            <label class="text-xs">Lado
                                <select wire:model="pernas.{{ $i }}.lado" class="mt-1 w-full rounded-md border-zinc-200 text-sm">
                                    <option value="COMPRADO">COMPRADO</option><option value="VENDIDO">VENDIDO</option>
                                </select>
                            </label>
                            <div class="col-span-6 text-right">
                                <button type="button" class="text-xs text-red-600" wire:click="removerPerna({{ $i }})">remover perna</button>
                            </div>
                        </div>
                    @endforeach
                    <flux:button type="button" size="sm" variant="ghost" wire:click="adicionarPerna">+ Adicionar perna</flux:button>
                </div>
            @endif
        </div>

        <div class="flex justify-end">
            <flux:button type="submit" variant="primary">Cadastrar posição</flux:button>
        </div>
    </form>
</div>
