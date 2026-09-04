<?php

namespace App\Http\Controllers\Front;

use App\Http\Controllers\Controller;
use App\Services\Front\StorefrontStylesService;
use Illuminate\Http\Response;

class StorefrontStylesController extends Controller
{
    public function __invoke(
        StorefrontStylesService $styles
    ): Response {
        return response($styles->css(), 200, [
            'Content-Type' => 'text/css; charset=UTF-8',
            'Cache-Control' => 'private, no-cache, must-revalidate',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
