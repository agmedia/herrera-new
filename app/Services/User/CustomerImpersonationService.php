<?php

namespace App\Services\User;

use App\Models\User;
use App\Services\Admin\OrderManagerAccess;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CustomerImpersonationService
{
    public const SESSION_KEY = 'admin.customer_impersonation';

    public function canStart(?User $admin, User $customer): bool
    {
        return $admin
            && ($admin->account_type !== 'staff' || $admin->admin_login_enabled)
            && ! session()->has(StaffImpersonationService::SESSION_KEY)
            && $customer->account_type === 'customer'
            && (int) $admin->id !== (int) $customer->id
            && ($admin->isA('superadmin') || (
                $admin->can('admin.access')
                && $admin->can('users.list.view')
                && $admin->can('users.profile.update')
            ))
            && ! $customer->isA('superadmin', 'super-admin', 'admin', 'editor', 'order_manager')
            && ! $customer->can('admin.access')
            && app(OrderManagerAccess::class)->canAccessCustomer($customer, $admin);
    }

    public function isActive(Request $request): bool
    {
        return $request->hasSession() && $request->session()->exists(self::SESSION_KEY);
    }

    /**
     * @return array{actor: User, customer: User, started_at: string}|null
     */
    public function validatedContext(Request $request, ?User $customer = null): ?array
    {
        if (! $this->isActive($request)) {
            return null;
        }

        $context = $request->session()->get(self::SESSION_KEY);
        $viewer = $request->user();
        if (! is_array($context)
            || ! $viewer
            || (int) ($context['customer_id'] ?? 0) !== (int) $viewer->id
            || ($customer && (int) $customer->id !== (int) $viewer->id)
            || ! is_string($context['started_at'] ?? null)
            || trim($context['started_at']) === ''
            || $request->session()->has(StaffImpersonationService::SESSION_KEY)) {
            throw new AuthorizationException;
        }

        // Resolve current records rather than trusting the identities stored at login.
        $actor = User::query()->find((int) ($context['admin_id'] ?? 0));
        $currentCustomer = User::query()->find((int) $viewer->id);
        if (! $currentCustomer || ! $this->canStart($actor, $currentCustomer)) {
            throw new AuthorizationException;
        }

        return [
            'actor' => $actor,
            'customer' => $currentCustomer,
            'started_at' => $context['started_at'],
        ];
    }

    public function validatedActor(Request $request): ?User
    {
        return $this->validatedContext($request)['actor'] ?? null;
    }

    public function start(Request $request, User $customer): void
    {
        abort_if($request->session()->has(StaffImpersonationService::SESSION_KEY), 409, __('Prvo završite pregled računa voditelja narudžbi.'));
        abort_if($this->isActive($request), 409, __('Već ste prijavljeni kao kupac. Prvo se vratite u admin.'));
        $admin = $request->user();
        abort_unless($this->canStart($admin, $customer), 403);

        $context = [
            'admin_id' => (int) $admin->id,
            'customer_id' => (int) $customer->id,
            'admin_front' => $request->session()->get('front', []),
            'started_at' => now()->toIso8601String(),
        ];

        // Checkout authorization and cart state belong to the current identity.
        $request->session()->forget(['front', 'auth.password_confirmed_at', 'url.intended', '_old_input', 'errors']);
        $request->session()->put(self::SESSION_KEY, $context);

        // Do not alter either user's persistent remember token or other devices.
        Auth::guard('web')->logoutCurrentDevice();
        Auth::guard('web')->login($customer, false);
        $request->session()->regenerateToken();

        activity('customer-support')
            ->causedBy($admin)
            ->performedOn($customer)
            ->event('customer_impersonation.started')
            ->log('Administrator se prijavio kao kupac.');
    }

    public function stop(Request $request): ?User
    {
        $context = $request->session()->get(self::SESSION_KEY);
        abort_unless(is_array($context), 409, __('Nema aktivne prijave kao kupac.'));
        $customerId = (int) ($context['customer_id'] ?? 0);
        // The server-side login ID survives deletion of the customer record.
        $sessionUserId = (int) $request->session()->get(Auth::guard('web')->getName());
        $currentUserId = (int) $request->user()?->id;
        abort_unless($customerId > 0 && ($currentUserId ?: $sessionUserId) === $customerId, 403);

        $admin = User::query()->find((int) ($context['admin_id'] ?? 0));
        $customer = User::query()->find($customerId);

        Auth::guard('web')->logoutCurrentDevice();
        $request->session()->forget([self::SESSION_KEY, 'front', 'auth.password_confirmed_at', 'url.intended', '_old_input', 'errors']);

        if (! $admin
            || ($admin->account_type === 'staff' && ! $admin->admin_login_enabled)
            || ! ($admin->isA('superadmin') || $admin->can('admin.access'))) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return null;
        }

        $request->session()->put('front', (array) ($context['admin_front'] ?? []));
        Auth::guard('web')->login($admin, false);
        $request->session()->regenerateToken();

        $audit = activity('customer-support')->causedBy($admin);
        if ($customer) {
            $audit->performedOn($customer);
        }
        $audit->event('customer_impersonation.stopped')
            ->withProperties(['customer_id' => $customerId, 'started_at' => $context['started_at'] ?? null])
            ->log('Administrator je završio prijavu kao kupac.');

        return $admin;
    }
}
