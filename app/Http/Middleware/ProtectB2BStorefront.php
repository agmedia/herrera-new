<?php

namespace App\Http\Middleware;

use App\Services\Pricing\B2BAccessService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ProtectB2BStorefront
{
    public function __construct(private readonly B2BAccessService $access) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->access->requiresApprovedAccount()) {
            return $next($request);
        }

        $canViewPrices = $this->access->canViewPrices($request->user());

        if (! $canViewPrices && $request->routeIs(
            'cart.*',
            'shop.promotions',
            'checkout.create',
            'checkout.options',
            'checkout.store',
            'checkout.wspay.start',
            'checkout.corvus.start',
            'checkout.keks.start',
        )) {
            if ($request->expectsJson() || $request->ajax()) {
                $response = response()->json(['message' => __('ui.b2b.pricing.access_required')], 403);
            } elseif ($request->user()) {
                $response = redirect()->route('account.dashboard')->with('warning', __('ui.b2b.pricing.approval_required'));
            } else {
                $response = redirect()->guest(route('front.auth.login'))->with('warning', __('ui.b2b.pricing.login_required'));
            }
        } else {
            if (! $canViewPrices) {
                // Hidden prices must not be discoverable through filtering or sorting.
                foreach (['price_min', 'price_max', 'available', 'available_only', 'promotion', 'promotion_only', 'promo_only'] as $key) {
                    $request->query->remove($key);
                }

                if (in_array($request->query('sort'), ['price_low', 'price_high', 'stock_high'], true)) {
                    $request->query->remove('sort');
                }
            }

            $response = $next($request);
        }

        $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
        $response->setVary('Cookie', false);

        return $response;
    }
}
