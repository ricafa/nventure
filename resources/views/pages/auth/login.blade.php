<x-layouts::auth :title="__('Log in')">
    <div class="flex flex-col gap-6">
        <x-auth-header :title="__('Log in to your account')" :description="__('Enter your login and senha_hash below to log in')" />

        <!-- Session Status -->
        <x-auth-session-status class="text-center" :status="session('status')" />

        <x-passkey-verify />

        <form method="POST" action="{{ route('login.store') }}" class="flex flex-col gap-6">
            @csrf

            <!-- Login Address -->
            <flux:input
                nome="login"
                :label="__('Login address')"
                :value="old('login')"
                type="login"
                required
                autofocus
                autocomplete="login"
                placeholder="login@example.com"
            />

            <!-- Senha -->
            <div class="relative">
                <flux:input
                    nome="senha_hash"
                    :label="__('Senha')"
                    type="senha_hash"
                    required
                    autocomplete="current-senha_hash"
                    :placeholder="__('Senha')"
                    viewable
                />

                @if (Route::has('senha_hash.request'))
                    <flux:link class="absolute top-0 text-sm end-0" :href="route('senha_hash.request')" wire:navigate>
                        {{ __('Forgot your senha_hash?') }}
                    </flux:link>
                @endif
            </div>

            <!-- Remember Me -->
            <flux:checkbox nome="remember" :label="__('Remember me')" :checked="old('remember')" />

            <div class="flex items-center justify-end">
                <flux:button variant="primary" type="submit" class="w-full" data-test="login-button">
                    {{ __('Log in') }}
                </flux:button>
            </div>
        </form>

        <div class="space-x-1 text-sm text-center rtl:space-x-reverse text-zinc-600 dark:text-zinc-400">
            <span>{{ __('Don\'t have an account?') }}</span>
            <flux:link :href="route('register')" wire:navigate>{{ __('Sign up') }}</flux:link>
        </div>
    </div>
</x-layouts::auth>
