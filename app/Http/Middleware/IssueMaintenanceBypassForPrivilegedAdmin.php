<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Foundation\Http\MaintenanceModeBypassCookie;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class IssueMaintenanceBypassForPrivilegedAdmin
{
    /**
     * Ensure privileged admin users always receive a valid maintenance bypass cookie.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! app()->isDownForMaintenance()) {
            return $next($request);
        }

        $user = $request->user();

        if (! $user || ! $this->isPrivilegedAdmin($user)) {
            return $next($request);
        }

        $secret = $this->resolveMaintenanceSecret();

        if ($secret !== '') {
            Cookie::queue(MaintenanceModeBypassCookie::create($secret));
        }

        return $next($request);
    }

    private function isPrivilegedAdmin(object $user): bool
    {
        return $user->isA('superadmin')
            || $user->isA('super-admin')
            || $user->isA('admin')
            || $user->isA('editor')
            || $user->can('admin.access');
    }

    private function resolveMaintenanceSecret(): string
    {
        try {
            $data = app()->maintenanceMode()->data();

            return trim((string) ($data['secret'] ?? ''));
        } catch (Throwable) {
            return trim((string) config('app.maintenance_bypass_secret', ''));
        }
    }
}

