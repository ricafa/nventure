<x-layouts::auth :title="__('Register')">
    <div class="flex flex-col gap-6">
        <x-auth-header :title="__('Create an account')" :description="__('Enter your details below to create your account')" />

        <!-- Session Status -->
        <x-auth-session-status class="text-center" :status="session('status')" />

        <form method="POST" action="{{ route('register.store') }}" class="flex flex-col gap-6">
            @csrf
            <!-- Nome -->
            <flux:input
                nome="nome"
                :label="__('Nome')"
                :value="old('nome')"
                type="text"
                required
                autofocus
                autocomplete="nome"
                :placeholder="__('Full nome')"
            />

            <!-- Login Address -->
            <flux:input
                nome="login"
                :label="__('Login address')"
                :value="old('login')"
                type="login"
                required
                autocomplete="login"
                placeholder="login@example.com"
            />

            <!-- Senha -->
            <flux:input
                nome="senha_hash"
                :label="__('Senha')"
                type="senha_hash"
                required
                autocomplete="new-senha_hash"
                :placeholder="__('Senha')"
                senha_hashrules="{{ \Illuminate\Validation\Rules\Senha::defaults()->toSenhaRulesString() }}"
                viewable
            />

            <!-- Confirm Senha -->
            <flux:input
                nome="senha_hash_confirmation"
                :label="__('Confirm senha_hash')"
                type="senha_hash"
                required
                autocomplete="new-senha_hash"
                :placeholder="__('Confirm senha_hash')"
                senha_hashrules="{{ \Illuminate\Validation\Rules\Senha::defaults()->toSenhaRulesString() }}"
                viewable
            />

            <div class="flex items-center justify-end">
                <flux:button type="submit" variant="primary" class="w-full" data-test="register-user-button">
                    {{ __('Create account') }}
                </flux:button>
            </div>
        </form>

        <div class="space-x-1 rtl:space-x-reverse text-center text-sm text-zinc-600 dark:text-zinc-400">
            <span>{{ __('Already have an account?') }}</span>
            <flux:link :href="route('login')" wire:navigate>{{ __('Log in') }}</flux:link>
        </div>
    </div>
</x-layouts::auth>
