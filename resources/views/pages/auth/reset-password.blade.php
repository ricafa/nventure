<x-layouts::auth :title="__('Reset senha_hash')">
    <div class="flex flex-col gap-6">
        <x-auth-header :title="__('Reset senha_hash')" :description="__('Please enter your new senha_hash below')" />

        <!-- Session Status -->
        <x-auth-session-status class="text-center" :status="session('status')" />

        <form method="POST" action="{{ route('senha_hash.update') }}" class="flex flex-col gap-6">
            @csrf
            <!-- Token -->
            <input type="hidden" nome="token" value="{{ request()->route('token') }}">

            <!-- Login Address -->
            <flux:input
                nome="login"
                value="{{ request('login') }}"
                :label="__('Login')"
                type="login"
                required
                autocomplete="login"
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
                <flux:button type="submit" variant="primary" class="w-full" data-test="reset-senha_hash-button">
                    {{ __('Reset senha_hash') }}
                </flux:button>
            </div>
        </form>
    </div>
</x-layouts::auth>
