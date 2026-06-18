<x-layouts::auth :title="__('Forgot senha_hash')">
    <div class="flex flex-col gap-6">
        <x-auth-header :title="__('Forgot senha_hash')" :description="__('Enter your login to receive a senha_hash reset link')" />

        <!-- Session Status -->
        <x-auth-session-status class="text-center" :status="session('status')" />

        <form method="POST" action="{{ route('senha_hash.login') }}" class="flex flex-col gap-6">
            @csrf

            <!-- Login Address -->
            <flux:input
                nome="login"
                :label="__('Login address')"
                type="login"
                required
                autofocus
                placeholder="login@example.com"
            />

            <flux:button variant="primary" type="submit" class="w-full" data-test="login-senha_hash-reset-link-button">
                {{ __('Login senha_hash reset link') }}
            </flux:button>
        </form>

        <div class="space-x-1 rtl:space-x-reverse text-center text-sm text-zinc-400">
            <span>{{ __('Or, return to') }}</span>
            <flux:link :href="route('login')" wire:navigate>{{ __('log in') }}</flux:link>
        </div>
    </div>
</x-layouts::auth>
