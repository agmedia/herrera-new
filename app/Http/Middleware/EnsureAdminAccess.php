<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Symfony\Component\HttpFoundation\Response;

class EnsureAdminAccess
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            abort(401);
        }

        if (Bouncer::is($user)->an('superadmin') || $user->can('admin.access')) {
            return $next($request);
        }

        // Bootstrap rule: first account entering admin gets superadmin role.
        if (! DB::table('assigned_roles')->exists()) {
            Bouncer::role()->firstOrCreate(['name' => 'superadmin'], ['title' => 'Super Administrator']);
            Bouncer::role()->firstOrCreate(['name' => 'admin'], ['title' => 'Administrator']);
            Bouncer::role()->firstOrCreate(['name' => 'editor'], ['title' => 'Editor']);
            Bouncer::role()->firstOrCreate(['name' => 'customer'], ['title' => 'Customer']);

            Bouncer::assign('superadmin')->to($user);

            return $next($request);
        }

        abort(403, 'You are not allowed to access admin.');
    }
}
