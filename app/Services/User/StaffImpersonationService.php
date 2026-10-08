<?php

namespace App\Services\User;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class StaffImpersonationService
{
    public const SESSION_KEY = 'admin.staff_impersonation';

    public function canStart(?User $admin, User $staff): bool
    {
        return $this->canManageStaff($admin)
            && ! session()->has(self::SESSION_KEY)
            && ! session()->has(CustomerImpersonationService::SESSION_KEY)
            && (int) $admin->id !== (int) $staff->id
            && $staff->account_type === 'staff'
            && $staff->admin_login_enabled
            && $staff->isA('order_manager')
            && ! $staff->isA('superadmin', 'super-admin', 'admin', 'editor')
            && ! $staff->can('users.access.manage');
    }

    public function isActive(Request $request): bool
    {
        return is_array($request->session()->get(self::SESSION_KEY));
    }

    public function start(Request $request, User $staff): void
    {
        abort_if($this->isActive($request) || $request->session()->has(CustomerImpersonationService::SESSION_KEY), 409, __('Prvo se vratite u svoj administratorski račun.'));
        $admin = $request->user();
        abort_unless($this->canStart($admin, $staff), 403);

        $context = [
            'admin_id' => (int) $admin->id,
            'staff_id' => (int) $staff->id,
            'admin_front' => $request->session()->get('front', []),
            'started_at' => now()->toIso8601String(),
        ];

        $request->session()->forget(['front', 'auth.password_confirmed_at', 'url.intended', '_old_input', 'errors']);
        $request->session()->put(self::SESSION_KEY, $context);
        Auth::guard('web')->logoutCurrentDevice();
        Auth::guard('web')->login($staff, false);
        $request->session()->regenerate();

        activity('staff-support')
            ->causedBy($admin)
            ->performedOn($staff)
            ->event('staff_impersonation.started')
            ->withProperties(['original_admin_id' => $admin->id, 'started_at' => $context['started_at']])
            ->log('Administrator se prijavio kao Order Manager.');
    }

    public function stop(Request $request): ?User
    {
        $context = $request->session()->get(self::SESSION_KEY);
        abort_unless(is_array($context), 409, __('Nema aktivnog pregleda administratorskog računa.'));
        $staffId = (int) ($context['staff_id'] ?? 0);

        // The server-side login ID remains available if this target was disabled
        // or deleted during the preview and the provider no longer returns it.
        $sessionUserId = (int) $request->session()->get(Auth::guard('web')->getName());
        $currentUserId = (int) $request->user()?->id;
        abort_unless($staffId > 0 && ($currentUserId ?: $sessionUserId) === $staffId, 403);

        $admin = User::query()->find((int) ($context['admin_id'] ?? 0));
        $staff = User::query()->find($staffId);
        $restore = $this->canManageStaff($admin);

        $audit = activity('staff-support');
        $admin ? $audit->causedBy($admin) : $audit->causedByAnonymous();
        if ($staff) {
            $audit->performedOn($staff);
        }
        $audit->event('staff_impersonation.stopped')
            ->withProperties([
                'original_admin_id' => (int) ($context['admin_id'] ?? 0),
                'staff_id' => $staffId,
                'started_at' => $context['started_at'] ?? null,
                'restored' => $restore,
            ])
            ->log('Administrator je završio pregled Order Manager računa.');

        Auth::guard('web')->logoutCurrentDevice();
        $request->session()->forget([self::SESSION_KEY, 'front', 'auth.password_confirmed_at', 'url.intended', '_old_input', 'errors']);

        if (! $restore) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return null;
        }

        $request->session()->put('front', (array) ($context['admin_front'] ?? []));
        Auth::guard('web')->login($admin, false);
        $request->session()->regenerate();

        return $admin;
    }

    private function canManageStaff(?User $admin): bool
    {
        return $admin
            && $admin->account_type === 'staff'
            && $admin->admin_login_enabled
            && ($admin->isA('superadmin') || ($admin->can('admin.access') && $admin->can('users.access.manage')));
    }
}
