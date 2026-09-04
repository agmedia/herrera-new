<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureApiUserEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! (bool) ($user->api_access_enabled ?? false)) {
            return response()->json([
                'message' => 'API access is disabled for this user.',
            ], 403);
        }

        return $next($request);
    }
}
