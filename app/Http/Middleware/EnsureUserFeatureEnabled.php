<?php

namespace App\Http\Middleware;

use App\Services\Settings\SystemSettingsService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserFeatureEnabled
{
    public function __construct(
        private readonly SystemSettingsService $settings
    ) {
    }

    /**
     * @param  string  $flag
     */
    public function handle(Request $request, Closure $next, string $flag): Response
    {
        $enabled = (bool) $this->settings->get(
            $flag,
            (bool) config('user_features.flags.'.$flag, false)
        );

        if ($enabled) {
            return $next($request);
        }

        return redirect()
            ->route('admin.settings.user.index')
            ->with('notify', [
                'type' => 'warning',
                'message' => 'This user module is disabled in Settings > User.',
            ]);
    }
}
