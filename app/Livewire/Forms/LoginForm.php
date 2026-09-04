<?php

namespace App\Livewire\Forms;

use Illuminate\Foundation\Http\MaintenanceModeBypassCookie;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Validate;
use Livewire\Form;
use Throwable;

class LoginForm extends Form
{
    #[Validate('required|string|email')]
    public string $email = '';

    #[Validate('required|string')]
    public string $password = '';

    #[Validate('boolean')]
    public bool $remember = false;

    /**
     * Attempt to authenticate the request's credentials.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function authenticate(): void
    {
        $this->ensureIsNotRateLimited();

        if (! Auth::attempt($this->only(['email', 'password']), $this->remember)) {
            RateLimiter::hit($this->throttleKey());

            throw ValidationException::withMessages([
                'form.email' => trans('auth.failed'),
            ]);
        }

        $this->issueMaintenanceBypassCookieForPrivilegedUser();

        RateLimiter::clear($this->throttleKey());
    }

    /**
     * Ensure the authentication request is not rate limited.
     */
    protected function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), 5)) {
            return;
        }

        event(new Lockout(request()));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'form.email' => trans('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => ceil($seconds / 60),
            ]),
        ]);
    }

    /**
     * Get the authentication rate limiting throttle key.
     */
    protected function throttleKey(): string
    {
        return Str::transliterate(Str::lower($this->email).'|'.request()->ip());
    }

    private function issueMaintenanceBypassCookieForPrivilegedUser(): void
    {
        if (! app()->isDownForMaintenance()) {
            return;
        }

        $user = Auth::user();

        if (! $user) {
            return;
        }

        $isPrivileged = $user->isA('superadmin')
            || $user->isA('super-admin')
            || $user->isA('admin')
            || $user->isA('editor')
            || $user->can('admin.access');

        if (! $isPrivileged) {
            return;
        }

        $secret = '';

        try {
            $data = app()->maintenanceMode()->data();
            $secret = trim((string) ($data['secret'] ?? ''));
        } catch (Throwable) {
            $secret = trim((string) config('app.maintenance_bypass_secret', ''));
        }

        if ($secret !== '') {
            Cookie::queue(MaintenanceModeBypassCookie::create($secret));
        }
    }
}
