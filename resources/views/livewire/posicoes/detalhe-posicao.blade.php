<div>
    @if ($aberto && $detalhe)
        @php($r = $detalhe->resumo)
        <div class="fixed inset-0 z-40 flex items-start justify-center overflow-y-auto bg-black/30 p-6">
            <div class="w-full max-w-2xl space-y-5 rounded-lg bg-white p-6 shadow-xl">
                <div class="flex items-start justify-between">
                    <div>
                        <h2 class="text-xl font-semibold">Posição #{{ $r->id }}</h2>
                        <p class="text-sm text-zinc-500">{{ $r->instrumento }} · {{ $r->lado }} · {{ $r->mercado }}</p>
                    </div>
                    <flux:button size="sm" variant="ghost" wire:click="fechar">Fechar</flux:button>
                </div>

                <dl class="grid grid-cols-2 gap-3 text-sm">
                    <div><dt class="text-zinc-400">Quantidade</dt><dd>{{ number_format($r->quantidade, 4, ',', '.') }}</dd></div>
                    <div><dt class="text-zinc-400">Status</dt><dd>{{ $r->status }}</dd></div>
                    <div><dt class="text-zinc-400">Entrada</dt><dd>{{ $r->dataEntrada }}</dd></div>
                    <div><dt class="text-zinc-400">Vencimento</dt><dd>{{ $r->dataVencimento }}</dd></div>
                    @foreach ($detalhe->detalhe as $chave => $valor)
                        @if (! is_array($valor))
                            <div><dt class="text-zinc-400">{{ str_replace('_', ' ', $chave) }}</dt><dd class="font-mono">{{ $valor }}</dd></div>
                        @endif
                    @endforeach
                </dl>

                @if ($r->instrumento === 'FUTURO')
                    <div class="rounded-md border border-zinc-200">
                        <div class="border-b border-zinc-200 bg-zinc-50 px-4 py-2 text-xs font-medium text-zinc-500">Movimentações</div>
                        <table class="w-full text-left text-sm">
                            <tbody class="divide-y divide-zinc-100">
                                @foreach ($detalhe->movimentacoes as $mov)
                                    <tr wire:key="mov-{{ $mov['id'] }}">
                                        <td class="px-4 py-2">{{ $mov['tipo'] }}</td>
                                        <td class="px-4 py-2 font-mono text-xs">{{ $mov['data_movimentacao'] }}</td>
                                        <td class="px-4 py-2 text-right font-mono">{{ number_format($mov['quantidade'], 4, ',', '.') }}</td>
                                        <td class="px-4 py-2 text-right font-mono">{{ number_format($mov['preco'], 6, ',', '.') }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    @if ($r->status === 'ABERTA')
                        <div class="space-y-3 rounded-md border border-zinc-200 p-4">
                            <div class="text-xs font-medium text-zinc-500">Movimentar (AUMENTO / REDUCAO)</div>
                            <div class="grid grid-cols-2 gap-3">
                                <select wire:model="tipo" class="rounded-md border-zinc-200 text-sm">
                                    <option value="AUMENTO">AUMENTO</option>
                                    <option value="REDUCAO">REDUCAO</option>
                                </select>
                                <input type="date" wire:model="dataMovimentacao" class="rounded-md border-zinc-200 text-sm">
                                <input type="number" step="any" placeholder="Quantidade" wire:model="quantidade" class="rounded-md border-zinc-200 text-sm">
                                <input type="number" step="any" placeholder="Preço" wire:model="preco" class="rounded-md border-zinc-200 text-sm">
                            </div>
                            @error('quantidade')<p class="text-sm text-red-600">{{ $message }}</p>@enderror
                            <flux:button size="sm" variant="primary" wire:click="movimentar">Registrar movimentação</flux:button>
                        </div>
                    @endif
                @endif

                <div class="flex justify-end gap-2 border-t border-zinc-100 pt-4">
                    @if ($r->status === 'ABERTA')
                        <flux:button size="sm" variant="ghost" wire:click="encerrar"
                            wire:confirm="Encerrar esta posição?">Encerrar</flux:button>
                    @endif
                    <flux:button size="sm" variant="danger" wire:click="excluir"
                        wire:confirm="Excluir esta posição? (somente se ainda sem MtM)">Excluir</flux:button>
                </div>
            </div>
        </div>
    @endif
</div>
