<?php

namespace App\Http\Controllers\Feed;

use App\Http\Controllers\Controller;
use App\Services\Feeds\CustomerCatalogFeedAccess;
use App\Services\Feeds\CustomerWholesaleFeedService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CustomerWholesaleFeedController extends Controller
{
    public function __invoke(Request $request, string $token, CustomerCatalogFeedAccess $access, CustomerWholesaleFeedService $export): StreamedResponse
    {
        abort_unless($request->isSecure() || app()->environment(['local', 'testing']), 404);
        $feed = $access->resolveToken($token);
        abort_unless($feed, 404);

        return response()->stream(static fn () => $export->stream($feed), 200, [
            'Content-Type' => 'application/xml; charset=UTF-8',
            'Content-Disposition' => 'inline; filename="customer-catalogue.xml"',
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
            'X-Robots-Tag' => 'noindex, nofollow, noarchive',
            'Referrer-Policy' => 'no-referrer',
        ]);
    }
}
