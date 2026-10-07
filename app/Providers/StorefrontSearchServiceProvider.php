<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class StorefrontSearchServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        RateLimiter::for('storefront-search', static function (Request $request): array {
            $ip = hash('sha256', (string) $request->ip());
            $user = $request->user();
            $budgets = $user
                ? ['user:'.$user->getAuthIdentifier() => 'user', 'authenticated-ip:'.$ip => 'authenticated_ip']
                : ['guest-ip:'.$ip => 'guest'];
            $limits = [];

            foreach ($budgets as $key => $budget) {
                $settings = (array) config('storefront-search.limits.'.$budget);
                foreach ([
                    Limit::perSecond(max(1, (int) ($settings['burst'] ?? 45)), max(1, (int) ($settings['burst_seconds'] ?? 10)))->by($key.':burst'),
                    Limit::perMinute(max(1, (int) ($settings['per_minute'] ?? 180)))->by($key.':minute'),
                ] as $limit) {
                    $limits[] = $limit->response(static function (Request $request, array $headers) {
                        $message = __('search.rate_limited');
                        $response = $request->expectsJson()
                            ? response()->json(['message' => $message], 429, $headers)
                            : response($message, 429, $headers)->header('Content-Type', 'text/plain; charset=UTF-8');
                        $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
                        $response->setVary('Cookie', false);

                        return $response;
                    });
                }
            }

            return $limits;
        });
    }
}
