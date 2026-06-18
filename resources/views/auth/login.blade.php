<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Entrar - {{ config('app.name') }}</title>
        @vite(['resources/css/app.css', 'resources/js/app.js'])
        @fluxAppearance
    </head>
    <body class="min-h-screen bg-zinc-50 text-zinc-900 antialiased">
        <main class="mx-auto flex min-h-screen w-full max-w-md items-center px-6">
            <section class="w-full space-y-8">
                <div class="space-y-2">
                    <p class="text-sm font-medium uppercase tracking-wide text-emerald-700">NeverVenture</p>
                    <h1 class="text-3xl font-semibold">Entrar</h1>
                </div>

                @if ($errors->any())
                    <div class="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
                        {{ $errors->first() }}
                    </div>
                @endif

                <form method="POST" action="{{ route('login') }}" class="space-y-5">
                    @csrf

                    <label class="block space-y-2">
                        <span class="text-sm font-medium text-zinc-700">Login</span>
                        <input
                            name="login"
                            type="text"
                            value="{{ old('login') }}"
                            required
                            autofocus
                            autocomplete="username"
                            class="block w-full rounded-md border border-zinc-300 bg-white px-3 py-2 text-zinc-900 shadow-sm outline-none transition focus:border-emerald-600 focus:ring-2 focus:ring-emerald-200"
                        >
                    </label>

                    <label class="block space-y-2">
                        <span class="text-sm font-medium text-zinc-700">Senha</span>
                        <input
                            name="password"
                            type="password"
                            required
                            autocomplete="current-password"
                            class="block w-full rounded-md border border-zinc-300 bg-white px-3 py-2 text-zinc-900 shadow-sm outline-none transition focus:border-emerald-600 focus:ring-2 focus:ring-emerald-200"
                        >
                    </label>

                    <label class="flex items-center gap-2 text-sm text-zinc-600">
                        <input name="remember" type="checkbox" class="rounded border-zinc-300 text-emerald-700">
                        Manter conectado
                    </label>

                    <flux:button type="submit" variant="primary" class="w-full">Entrar</flux:button>
                </form>
            </section>
        </main>
        @fluxScripts
    </body>
</html>
