<?php

use App\Concerns\ProfileValidationRules;
/* @chisel-login-verification */
use Illuminate\Contracts\Auth\MustVerifyLogin;
/* @end-chisel-login-verification */
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Profile settings')] class extends Component {
    use ProfileValidationRules;

    public string $nome = '';
    public string $login = '';

    /**
     * Mount the component.
     */
    public function mount(): void
    {
        $this->nome = Auth::user()->nome;
        $this->login = Auth::user()->login;
    }

    /**
     * Update the profile information for the currently authenticated user.
     */
    public function updateProfileInformation(): void
    {
        $user = Auth::user();

        $validated = $this->validate($this->profileRules($user->id));

        $user->fill($validated);

        if ($user->isDirty('login')) {
            $user->login_verified_at = null;
        }

        $user->save();

        Flux::toast(variant: 'success', text: __('Profile updated.'));
    }

    /* @chisel-login-verification */
    /**
     * Send an login verification notification to the current user.
     */
    public function resendVerificationNotification(): void
    {
        $user = Auth::user();

        if ($user->hasVerifiedLogin()) {
            $this->redirectIntended(default: route('dashboard', absolute: false));

            return;
        }

        $user->sendLoginVerificationNotification();

        Session::flash('status', 'verification-link-sent');
    }

    #[Computed]
    public function hasUnverifiedLogin(): bool
    {
        return Auth::user() instanceof MustVerifyLogin && ! Auth::user()->hasVerifiedLogin();
    }

    #[Computed]
    public function showDeleteUser(): bool
    {
        return ! Auth::user() instanceof MustVerifyLogin
            || (Auth::user() instanceof MustVerifyLogin && Auth::user()->hasVerifiedLogin());
    }
    /* @end-chisel-login-verification */
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading class="sr-only">{{ __('Profile settings') }}</flux:heading>

    <x-pages::settings.layout :heading="__('Profile')" :subheading="__('Update your nome and login address')">
        <form wire:submit="updateProfileInformation" class="my-6 w-full space-y-6">
            <flux:input wire:model="nome" :label="__('Nome')" type="text" required autofocus autocomplete="nome" />

            <div>
                <flux:input wire:model="login" :label="__('Login')" type="login" required autocomplete="login" />

                {{-- @chisel-login-verification --}}
                @if ($this->hasUnverifiedLogin)
                    <div>
                        <flux:text class="mt-4">
                            {{ __('Your login address is unverified.') }}

                            <flux:link class="text-sm cursor-pointer" wire:click.prevent="resendVerificationNotification">
                                {{ __('Click here to re-send the verification login.') }}
                            </flux:link>
                        </flux:text>

                        @if (session('status') === 'verification-link-sent')
                            <flux:text class="mt-2 font-medium !dark:text-green-400 !text-green-600">
                                {{ __('A new verification link has been sent to your login address.') }}
                            </flux:text>
                        @endif
                    </div>
                @endif
                {{-- @end-chisel-login-verification --}}
            </div>

            <div class="flex items-center gap-4">
                <div class="flex items-center justify-end">
                    <flux:button variant="primary" type="submit" class="w-full" data-test="update-profile-button">
                        {{ __('Save') }}
                    </flux:button>
                </div>

            </div>
        </form>

        {{-- @chisel-login-verification --}}
        @if ($this->showDeleteUser)
        {{-- @end-chisel-login-verification --}}
            <livewire:pages::settings.delete-user-form />
        {{-- @chisel-login-verification --}}
        @endif
        {{-- @end-chisel-login-verification --}}
    </x-pages::settings.layout>
</section>
