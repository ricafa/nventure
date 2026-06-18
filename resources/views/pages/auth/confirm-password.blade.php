<x-layouts::auth :title="__('Confirm senha_hash')">
    <div class="flex flex-col gap-6">
        <x-auth-header
            :title="__('Confirm senha_hash')"
            :description="__('This is a secure area of the application. Please confirm your senha_hash before continuing.')"
        />

        <x-auth-session-status class="text-center" :status="session('status')" />

        <x-passkey-verify
            options-route="passkey.confirm-options"
            submit-route="passkey.confirm"
            :label="__('Confirm with passkey')"
            :loading-label="__('Confirming...')"
            :separator="__('Or confirm with senha_hash')"
        />

        <form method="POST" action="{{ route('senha_hash.confirm.store') }}" class="flex flex-col gap-6">
            @csrf

            <flux:input
                nome="senha_hash"
                :label="__('Senha')"
                type="senha_hash"
                required
                autocomplete="current-senha_hash"
                :placeholder="__('Senha')"
                viewable
            />

            <flux:button variant="primary" type="submit" class="w-full" data-test="confirm-senha_hash-button">
                {{ __('Confirm') }}
            </flux:button>
        </form>
    </div>
</x-layouts::auth>
