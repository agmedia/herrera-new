<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\User\CustomerImpersonationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class CustomerImpersonationController extends Controller
{
    public function start(Request $request, User $user, CustomerImpersonationService $impersonation): RedirectResponse
    {
        $impersonation->start($request, $user);

        return redirect()->route('account.dashboard');
    }

    public function stop(Request $request, CustomerImpersonationService $impersonation): RedirectResponse
    {
        $customerId = (int) $request->session()->get(CustomerImpersonationService::SESSION_KEY.'.customer_id');
        $admin = $impersonation->stop($request);

        if (! $admin) {
            return redirect()->route('login');
        }

        return redirect()->route('admin.users.show', ['user' => $customerId])
            ->with('status', __('Vratili ste se u administratorski račun.'));
    }
}
