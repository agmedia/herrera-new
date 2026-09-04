<?php

namespace App\Http\Controllers\Feed;

use App\Http\Controllers\Controller;
use App\Services\Feeds\NabavaNetFeedService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class NabavaNetFeedController extends Controller
{
    public function __invoke(Request $request, NabavaNetFeedService $feed): StreamedResponse
    {
        $enabled = (bool) config('services.nabava_net.enabled', false);
        $expectedUsername = trim((string) config('services.nabava_net.username', ''));
        $expectedPassword = (string) config('services.nabava_net.password', '');

        abort_unless($enabled && $expectedUsername !== '' && $expectedPassword !== '', 404);

        $providedUsername = $request->query('username');
        $providedPassword = $request->query('password');
        $providedUsername = is_string($providedUsername) ? $providedUsername : '';
        $providedPassword = is_string($providedPassword) ? $providedPassword : '';

        $usernameMatches = hash_equals($expectedUsername, $providedUsername);
        $passwordMatches = hash_equals($expectedPassword, $providedPassword);

        abort_unless($usernameMatches && $passwordMatches, 401);

        $locale = trim((string) config('services.nabava_net.locale', 'hr')) ?: 'hr';

        return response()->stream(
            static fn () => $feed->stream($locale),
            200,
            [
                'Content-Type' => 'text/xml; charset=UTF-8',
                'Content-Disposition' => 'inline; filename="nabava-net.xml"',
                'Cache-Control' => 'private, no-store, max-age=0',
                'X-Content-Type-Options' => 'nosniff',
                'X-Robots-Tag' => 'noindex, nofollow, noarchive',
            ],
        );
    }
}
