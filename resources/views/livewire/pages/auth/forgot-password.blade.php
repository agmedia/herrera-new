<?php

use Illuminate\Support\Facades\Password;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.guest')] class extends Component
{
    public string $email = '';

    /**
     * Send a password reset link to the provided email address.
     */
    public function sendPasswordResetLink(): void
    {
        $this->validate([
            'email' => ['required', 'string', 'email'],
        ]);

        $status = Password::sendResetLink(
            $this->only('email')
        );

        if ($status != Password::RESET_LINK_SENT) {
            $this->addError('email', __($status));

            return;
        }

        $this->reset('email');

        session()->flash('status', __($status));
    }
}; ?>

<section class="auth-layout store-auth-layout">
    <div class="auth-form-card store-auth-card">
        <div class="store-auth-card-heading">
            <p>{{ __('ui.auth.forgot.form_eyebrow') }}</p>
            <h2>{{ __('ui.auth.forgot.form_title') }}</h2>
            <span>{{ __('ui.auth.forgot.intro') }}</span>
        </div>

        <x-auth-session-status class="store-auth-status" :status="session('status')" />

        <form wire:submit="sendPasswordResetLink" class="store-auth-form" novalidate>
            <div class="store-auth-field">
                <label for="email">{{ __('ui.auth.fields.email') }}</label>
                <input
                    wire:model="email"
                    id="email"
                    type="email"
                    name="email"
                    autocomplete="email"
                    autofocus
                    required
                    @if ($errors->has('email')) aria-invalid="true" aria-describedby="forgot-password-email-error" @endif
                >
                <x-input-error id="forgot-password-email-error" :messages="$errors->get('email')" class="store-auth-error" />
            </div>

            <button type="submit" class="commerce-primary-action store-auth-submit" wire:loading.attr="disabled">
                <span wire:loading.remove wire:target="sendPasswordResetLink">{{ __('ui.auth.forgot.submit') }}</span>
                <span wire:loading wire:target="sendPasswordResetLink">{{ __('ui.auth.forgot.submitting') }}</span>
            </button>
        </form>
    </div>

    <aside class="auth-side-card store-auth-card store-auth-side-card">
        <p class="store-auth-side-eyebrow">{{ __('ui.auth.forgot.back_eyebrow') }}</p>
        <h2>{{ __('ui.auth.forgot.back_title') }}</h2>
        <p>{{ __('ui.auth.forgot.back_text') }}</p>

        <a href="{{ route('login') }}" class="commerce-secondary-action store-auth-secondary-action" wire:navigate>
            {{ __('ui.auth.forgot.back_action') }}
        </a>
    </aside>
</section>
