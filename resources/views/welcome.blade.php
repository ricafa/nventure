<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>{{ config('app.name') }}</title>
        @vite(['resources/css/app.css', 'resources/js/app.js'])
        @fluxAppearance
    </head>
    <body class="min-h-screen bg-zinc-50 text-zinc-900 antialiased">
        <main class="mx-auto flex min-h-screen max-w-4xl items-center justify-center px-6">
            <div class="space-y-5 text-center">
                <p class="text-sm font-medium uppercase tracking-wide text-emerald-700">NeverVenture</p>
                <h1 class="text-3xl font-semibold">Fundacao Laravel pronta</h1>
                <p class="text-zinc-600">Use a tela de login para acessar a base autenticada da aplicacao.</p>
                <flux:button :href="route('login')" variant="primary">Entrar</flux:button>
            </div>
        </main>
        @fluxScripts
    </body>
</html>
