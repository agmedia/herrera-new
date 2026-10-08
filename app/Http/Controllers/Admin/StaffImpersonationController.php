<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\User\StaffImpersonationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class StaffImpersonationController extends Controller
{
    public function start(Request $request, User $user, StaffImpersonationService $impersonation): RedirectResponse
    {
        $impersonation->start($request, $user);

        return redirect()->route('admin.orders');
    }

    public function stop(Request $request, StaffImpersonationService $impersonation): RedirectResponse
    {
        $admin = $impersonation->stop($request);

        return $admin
            ? redirect()->route('admin.users')->with('status', __('Vratili ste se u svoj administratorski račun.'))
            : redirect()->route('login');
    }
}
