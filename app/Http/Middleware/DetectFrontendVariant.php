<?php

namespace App\Http\Middleware;

use App\Services\Front\DeviceViewResolver;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

class DetectFrontendVariant
{
    public function __construct(
        private readonly DeviceViewResolver $deviceViewResolver,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $detectedVariant = $this->deviceViewResolver->variant($request->userAgent());
        $isMobileDevice = $detectedVariant === 'mobile';

        $request->attributes->set('frontend_variant', 'desktop');
        View::share('frontendVariant', 'desktop');
        View::share('isMobileDevice', $isMobileDevice);

        $response = $next($request);

        $existing = (string) $response->headers->get('Vary', '');
        $varyParts = collect(explode(',', $existing))
            ->map(static fn ($item) => trim((string) $item))
            ->filter();

        if (! $varyParts->contains(static fn ($item) => strcasecmp((string) $item, 'User-Agent') === 0)) {
            $varyParts->push('User-Agent');
        }

        $response->headers->set('Vary', $varyParts->implode(', '));

        return $response;
    }
}
