<?php

namespace App\Livewire\Actions;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;

class Logout
{
    /**
     * Log the current user out of the application.
     */
    public function __invoke(): void
    {
        $request = request();
        if (! $request->hasSession()) {
            $request->setLaravelSession(Session::driver());
        }

        $impersonation = app(\App\Services\User\CustomerImpersonationService::class);
        if ($impersonation->isActive($request)) {
            $impersonation->stop($request);

            return;
        }

        Auth::guard('web')->logout();

        Session::invalidate();
        Session::regenerateToken();
    }
}
