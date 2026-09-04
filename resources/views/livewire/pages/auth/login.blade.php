<?php

use App\Livewire\Forms\LoginForm;
use App\Services\Front\StoreSettingsService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.guest')] class extends Component
{
    public LoginForm $form;

    public string $recaptchaToken = '';

    /**
     * Handle an incoming authentication request.
     */
    public function login(): void
    {
        $this->validate();

        $captchaSettings = app(StoreSettingsService::class)->captcha();

        if ($this->recaptchaIsEnabled($captchaSettings)) {
            $this->validate(
                ['recaptchaToken' => ['required', 'string', 'max:4096']],
                ['recaptchaToken.required' => __('ui.auth.captcha_failed')],
                ['recaptchaToken' => __('ui.auth.validation.security_check')]
            );

            try {
                $this->assertRecaptchaIsValid(
                    token: $this->recaptchaToken,
                    secret: (string) $captchaSettings['recaptcha_v3_secret_key'],
                    minScore: (float) ($captchaSettings['recaptcha_v3_min_score'] ?? 0.5),
                    expectedAction: 'login_form',
                    ip: (string) request()->ip()
                );
            } finally {
                $this->recaptchaToken = '';
            }
        }

        $this->form->authenticate();

        Session::regenerate();

        if (app()->isDownForMaintenance()) {
            $user = auth()->user();
            $isPrivileged = $user
                && (
                    $user->isA('superadmin')
                    || $user->isA('super-admin')
                    || $user->isA('admin')
                    || $user->isA('editor')
                    || $user->can('admin.access')
                );

            if ($isPrivileged) {
                $data = app()->maintenanceMode()->data();
                $secret = trim((string) ($data['secret'] ?? ''));

                if ($secret !== '') {
                    $this->redirect('/'.$secret, navigate: true);

                    return;
                }
            }
        }

        $this->redirectIntended(default: route('dashboard', absolute: false), navigate: true);
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function recaptchaIsEnabled(array $settings): bool
    {
        return (bool) ($settings['recaptcha_v3_enabled'] ?? false)
            && trim((string) ($settings['recaptcha_v3_site_key'] ?? '')) !== ''
            && trim((string) ($settings['recaptcha_v3_secret_key'] ?? '')) !== '';
    }

    private function assertRecaptchaIsValid(
        string $token,
        string $secret,
        float $minScore,
        string $expectedAction,
        string $ip
    ): void {
        $minScore = max(0.0, min(1.0, $minScore));

        try {
            $response = Http::asForm()
                ->timeout(8)
                ->post('https://www.google.com/recaptcha/api/siteverify', [
                    'secret' => $secret,
                    'response' => $token,
                    'remoteip' => $ip,
                ]);
        } catch (\Throwable) {
            throw ValidationException::withMessages([
                'recaptchaToken' => __('ui.auth.captcha_failed'),
            ]);
        }

        $json = $response->ok() ? (array) $response->json() : [];
        $action = (string) ($json['action'] ?? '');

        if (
            ! (bool) ($json['success'] ?? false)
            || (float) ($json['score'] ?? 0.0) < $minScore
            || ($action !== '' && $action !== $expectedAction)
        ) {
            throw ValidationException::withMessages([
                'recaptchaToken' => __('ui.auth.captcha_failed'),
            ]);
        }
    }
}; ?>

@php
    $loginCaptchaSettings = app(\App\Services\Front\StoreSettingsService::class)->captcha();
    $loginCaptchaSiteKey = trim((string) ($loginCaptchaSettings['recaptcha_v3_site_key'] ?? ''));
    $loginCaptchaSecretKey = trim((string) ($loginCaptchaSettings['recaptcha_v3_secret_key'] ?? ''));
    $loginCaptchaEnabled = (bool) ($loginCaptchaSettings['recaptcha_v3_enabled'] ?? false)
        && $loginCaptchaSiteKey !== ''
        && $loginCaptchaSecretKey !== '';
@endphp

<section class="auth-layout store-auth-layout store-auth-layout--single">
    <div class="auth-form-card store-auth-card">
        <x-auth-session-status class="store-auth-status" :status="session('status')" />

        <form
            @if ($loginCaptchaEnabled)
                x-data="{
                    captchaSubmitting: false,
                    async submitWithCaptcha() {
                        if (this.captchaSubmitting) {
                            return;
                        }

                        this.captchaSubmitting = true;
                        let token = '';

                        try {
                            if (window.grecaptcha) {
                                await new Promise((resolve) => window.grecaptcha.ready(resolve));
                                token = await window.grecaptcha.execute(@js($loginCaptchaSiteKey), { action: 'login_form' });
                            }
                        } catch (error) {
                            token = '';
                        }

                        try {
                            await this.$wire.set('recaptchaToken', token || '', false);
                            await this.$wire.login();
                        } finally {
                            this.captchaSubmitting = false;
                        }
                    }
                }"
                x-on:submit.prevent="submitWithCaptcha"
                data-recaptcha-form
                data-recaptcha-site-key="{{ $loginCaptchaSiteKey }}"
                data-recaptcha-action="login_form"
            @else
                wire:submit="login"
            @endif
            class="store-auth-form store-auth-form--flush"
            novalidate
        >
            <div class="store-auth-field">
                <label for="email">{{ __('ui.auth.fields.email') }}</label>
                <input
                    wire:model="form.email"
                    id="email"
                    type="email"
                    name="email"
                    autocomplete="username"
                    autofocus
                    required
                    @if ($errors->has('form.email')) aria-invalid="true" aria-describedby="login-email-error" @endif
                >
                <x-input-error id="login-email-error" :messages="$errors->get('form.email')" class="store-auth-error" />
            </div>

            <div class="store-auth-field">
                <label for="password">{{ __('ui.auth.fields.password') }}</label>
                <input
                    wire:model="form.password"
                    id="password"
                    type="password"
                    name="password"
                    autocomplete="current-password"
                    required
                    @if ($errors->has('form.password')) aria-invalid="true" aria-describedby="login-password-error" @endif
                >
                <x-input-error id="login-password-error" :messages="$errors->get('form.password')" class="store-auth-error" />
            </div>

            <div class="store-auth-form-options">
                <label for="remember" class="store-auth-checkbox">
                    <input wire:model="form.remember" id="remember" type="checkbox" name="remember">
                    <span>{{ __('ui.auth.login.remember') }}</span>
                </label>

                @if (Route::has('password.request'))
                    <a href="{{ route('password.request') }}" class="auth-inline-link" wire:navigate>
                        {{ __('ui.auth.login.forgot_password') }}
                    </a>
                @endif
            </div>

            <x-input-error :messages="$errors->get('recaptchaToken')" class="store-auth-error" />

            <button
                type="submit"
                class="commerce-primary-action store-auth-submit"
                wire:loading.attr="disabled"
                @if ($loginCaptchaEnabled) x-bind:disabled="captchaSubmitting" @endif
            >
                <span wire:loading.remove wire:target="login">{{ __('ui.auth.login.submit') }}</span>
                <span wire:loading wire:target="login">{{ __('ui.auth.login.submitting') }}</span>
            </button>
        </form>
    </div>

    @if ($loginCaptchaEnabled)
        @once
            <script src="https://www.google.com/recaptcha/api.js?render={{ $loginCaptchaSiteKey }}"></script>
        @endonce
    @endif
</section>
