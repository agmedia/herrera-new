<?php

namespace App\Services\UserTracking;

use App\Models\User\UserTrackingEvent;
use App\Services\Settings\SystemSettingsService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

class UserTrackingService
{
    public function __construct(
        private readonly SystemSettingsService $settings
    ) {
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function track(string $event, ?Model $subject = null, array $payload = [], ?Request $request = null): ?UserTrackingEvent
    {
        $enabled = (bool) $this->settings->get(
            'user_tracking_enabled',
            (bool) config('user_features.flags.user_tracking_enabled', true)
        );

        if (! $enabled) {
            return null;
        }

        $request = $request ?: request();
        $sessionId = ($request && $request->hasSession()) ? $request->session()->getId() : null;

        return UserTrackingEvent::query()->create([
            'user_id' => auth()->id(),
            'session_id' => $sessionId,
            'event' => $event,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'url' => $request?->fullUrl(),
            'referrer' => $request?->headers->get('referer'),
            'ip_address' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
            'payload' => $payload,
            'occurred_at' => now(),
        ]);
    }
}
