<?php

namespace App\Http\Middleware;

use App\Services\User\CustomerImpersonationService;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ValidateCustomerImpersonation
{
    public function __construct(private readonly CustomerImpersonationService $impersonation) {}

    public function handle(Request $request, Closure $next): Response
    {
        // Existing gateway handlers authenticate callbacks and settle orders already placed.
        if ($request->routeIs(
            'checkout.wspay.return',
            'checkout.wspay.error',
            'checkout.wspay.cancel',
            'checkout.corvus.success',
            'checkout.corvus.cancel',
            'checkout.corvus.success.static',
            'checkout.corvus.cancel.static',
            'checkout.keks.success',
            'checkout.keks.fail',
            'checkout.keks.advice',
        )) {
            return $next($request);
        }

        try {
            $this->impersonation->validatedContext($request);
        } catch (AuthorizationException $exception) {
            if ($request->expectsJson()) {
                throw $exception;
            }

            return response()->view('front.customer-impersonation-expired', [], 403)
                ->header('Cache-Control', 'private, no-store, max-age=0');
        }

        return $next($request);
    }
}
