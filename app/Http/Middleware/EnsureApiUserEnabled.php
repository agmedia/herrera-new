<?php

namespace App\Http\Middleware;

use App\Services\Pricing\B2BAccessService;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureApiUserEnabled
{
    public function __construct(private readonly B2BAccessService $b2bAccess) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user('sanctum');

        if ($this->b2bAccess->requiresApprovedAccount() && ! $this->b2bAccess->canViewPrices($user)) {
            return $this->privateResponse(response()->json([
                'message' => 'An approved B2B account is required for wholesale API access.',
            ], 403));
        }

        // Retail-mode guests are handled by the subsequent authentication middleware.
        if (! $user) {
            return $next($request);
        }

        if (! (bool) ($user->api_access_enabled ?? false)) {
            return $this->privateResponse(response()->json([
                'message' => 'API access is disabled for this user.',
            ], 403));
        }

        try {
            return $this->privateResponse($next($request));
        } catch (AuthorizationException $exception) {
            return $this->privateResponse(response()->json([
                'message' => $exception->getMessage() ?: 'This action is unauthorized.',
            ], 403));
        }
    }

    private function privateResponse(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
        $response->setVary(['Authorization', 'Cookie'], false);

        return $response;
    }
}
