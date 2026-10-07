<?php

namespace App\Http\Controllers\Front;

use App\Http\Controllers\Controller;
use App\Services\Front\StorefrontStylesService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class StorefrontStylesController extends Controller
{
    public function __invoke(
        Request $request,
        StorefrontStylesService $styles
    ): Response {
        $css = $styles->css();
        $requestedVersion = $request->query('v');
        $hasCurrentVersion = is_string($requestedVersion)
            && hash_equals($styles->version($css), $requestedVersion);

        return response($css, 200, [
            'Content-Type' => 'text/css; charset=UTF-8',
            'Cache-Control' => $hasCurrentVersion
                ? 'private, max-age=31536000, immutable'
                : 'private, no-cache, must-revalidate',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
