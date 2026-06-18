<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Dashboard - {{ config('app.name') }}</title>
        @vite(['resources/css/app.css', 'resources/js/app.js'])
        @fluxAppearance
    </head>
    <body class="min-h-screen bg-zinc-50 text-zinc-900 antialiased">
        <header class="border-b border-zinc-200 bg-white">
            <div class="mx-auto flex max-w-6xl items-center justify-between px-6 py-4">
                <div>
                    <p class="text-sm font-medium uppercase tracking-wide text-emerald-700">NeverVenture</p>
                    <h1 class="text-xl font-semibold">Fundacao do projeto</h1>
                </div>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <flux:button type="submit" variant="ghost">Sair</flux:button>
                </form>
            </div>
        </header>

        <main class="mx-auto max-w-6xl px-6 py-8">
            <section class="grid gap-4 md:grid-cols-3">
                <div class="rounded-md border border-zinc-200 bg-white p-5">
                    <p class="text-sm text-zinc-500">Usuario</p>
                    <p class="mt-1 font-medium">{{ auth()->user()->nome }}</p>
                </div>
                <div class="rounded-md border border-zinc-200 bg-white p-5">
                    <p class="text-sm text-zinc-500">Perfil</p>
                    <p class="mt-1 font-medium">{{ auth()->user()->perfil }}</p>
                </div>
                <div class="rounded-md border border-zinc-200 bg-white p-5">
                    <p class="text-sm text-zinc-500">Stack</p>
                    <p class="mt-1 font-medium">Laravel 13 + Livewire 4</p>
                </div>
            </section>
        </main>
        @fluxScripts
    </body>
</html>
