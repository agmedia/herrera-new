<?php

namespace App\Http\Middleware;

use App\Services\Catalog\CatalogFeatureService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureCatalogFeatureEnabled
{
    public function __construct(
        private readonly CatalogFeatureService $catalogFeatures
    ) {
    }

    /**
     * @param  string  $flag
     */
    public function handle(Request $request, Closure $next, string $flag): Response
    {
        if ($this->catalogFeatures->enabled($flag)) {
            return $next($request);
        }

        if ($request->expectsJson() || $request->is('api/*')) {
            return response()->json([
                'message' => 'This API module is disabled in Catalog Features.',
                'flag' => $flag,
            ], 403);
        }

        return redirect()
            ->route('admin.settings.system.catalog-features')
            ->with('notify', [
                'type' => 'warning',
                'message' => 'This module is disabled in Catalog Features.',
            ]);
    }
}
