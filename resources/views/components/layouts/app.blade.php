<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>{{ $title ?? 'NeverVenture' }} - {{ config('app.name') }}</title>
        @vite(['resources/css/app.css', 'resources/js/app.js'])
        @fluxAppearance
    </head>
    <body class="min-h-screen bg-zinc-50 text-zinc-900 antialiased">
        <header class="border-b border-zinc-200 bg-white">
            <div class="mx-auto flex max-w-6xl items-center justify-between px-6 py-4">
                <div class="flex items-center gap-8">
                    <a href="{{ route('dashboard') }}" class="block">
                        <p class="text-sm font-medium uppercase tracking-wide text-emerald-700">NeverVenture</p>
                    </a>
                    <nav class="flex items-center gap-4 text-sm font-medium text-zinc-600">
                        <a href="{{ route('produtos.index') }}" class="hover:text-emerald-700">Produtos</a>
                        <a href="{{ route('precos.index') }}" class="hover:text-emerald-700">Preços</a>
                    </nav>
                </div>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <flux:button type="submit" variant="ghost">Sair</flux:button>
                </form>
            </div>
        </header>

        <main class="mx-auto max-w-6xl px-6 py-8">
            {{ $slot }}
        </main>
        @fluxScripts
    </body>
</html>
