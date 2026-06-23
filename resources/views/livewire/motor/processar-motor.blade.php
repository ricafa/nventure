<div>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            Motor de Marcação a Mercado
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">

            <!-- Painel de Disparo e Resumo -->
            <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg">
                <div class="max-w-xl">
                    <h3 class="text-lg font-medium text-gray-900 mb-4">Processar Novo Dia</h3>

                    <div class="flex items-end gap-4 mb-6">
                        <div class="w-1/2">
                            <label for="dataCalculo" class="block text-sm font-medium text-gray-700">Data de Cálculo</label>
                            <input type="date" wire:model="dataCalculo" id="dataCalculo" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm">
                        </div>
                        
                        <button wire:click="disparar" wire:loading.attr="disabled" class="inline-flex items-center px-4 py-2 bg-indigo-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-indigo-700 focus:bg-indigo-700 active:bg-indigo-900 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 transition ease-in-out duration-150">
                            Disparar Motor
                        </button>
                    </div>

                    <div wire:loading wire:target="disparar" class="text-sm text-gray-500 mb-4">
                        Processando... aguarde.
                    </div>

                    @if ($resumo)
                        <div class="bg-gray-50 p-4 rounded-md border border-gray-200">
                            <h4 class="font-semibold text-gray-800 mb-2">Resultado da Execução #{{ $resumo['execucao_id'] }} ({{ \Carbon\Carbon::parse($resumo['data_calculo'])->format('d/m/Y') }})</h4>
                            <ul class="text-sm text-gray-600 space-y-1">
                                <li><strong>Posições Processadas:</strong> {{ $resumo['posicoes_processadas'] }}</li>
                                <li class="text-green-600"><strong>Sucessos:</strong> {{ $resumo['sucessos'] }}</li>
                                <li class="{{ count($resumo['falhas']) > 0 ? 'text-red-600' : 'text-gray-600' }}">
                                    <strong>Falhas:</strong> {{ count($resumo['falhas']) }}
                                </li>
                            </ul>

                            @if (count($resumo['falhas']) > 0)
                                <div class="mt-4">
                                    <h5 class="text-xs font-semibold text-red-600 uppercase tracking-wide">Detalhe das Falhas</h5>
                                    <ul class="mt-2 text-xs text-red-500 list-disc list-inside">
                                        @foreach ($resumo['falhas'] as $falha)
                                            <li>Posição #{{ $falha['posicao_id'] }}: {{ $falha['motivo'] }}</li>
                                        @endforeach
                                    </ul>
                                </div>
                            @endif
                        </div>
                    @endif
                </div>
            </div>

            <!-- Histórico de Execuções -->
            <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg">
                <h3 class="text-lg font-medium text-gray-900 mb-4">Histórico de Execuções</h3>
                
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">ID</th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Data Cálculo</th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Disparado Por</th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Duração</th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Sucessos / Total</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            @forelse ($execucoes as $exec)
                                <tr>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">#{{ $exec->id }}</td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">{{ $exec->data_calculo->format('d/m/Y') }}</td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">{{ $exec->disparado_por }}</td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                        @if ($exec->iniciado_em && $exec->finalizado_em)
                                            {{ $exec->finalizado_em->diffInSeconds($exec->iniciado_em) }}s
                                        @else
                                            -
                                        @endif
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                        <span class="text-green-600 font-semibold">{{ $exec->sucessos }}</span> / {{ $exec->total_posicoes }}
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 text-center">Nenhuma execução registrada.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

        </div>
    </div>
</div>
