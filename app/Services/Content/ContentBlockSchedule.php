<?php

namespace App\Services\Content;

use App\Models\Content\ContentBlockSlot;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

class ContentBlockSchedule
{
    /** @return array{version: string, next_transition: ?Carbon} */
    public function context(?string $placement = null): array
    {
        if (! Schema::hasTable('content_block_slots') || ! Schema::hasTable('content_blocks')) {
            return ['version' => 'none', 'next_transition' => null];
        }

        $now = now();
        $transitions = ContentBlockSlot::query()
            ->where('is_active', true)
            ->whereHas('block', fn ($query) => $query->where('is_active', true))
            ->when($placement !== null, fn ($query) => $query->where('placement', $placement))
            ->selectRaw('MAX(CASE WHEN starts_at <= ? THEN starts_at END) AS last_start,
                MAX(CASE WHEN ends_at <= ? THEN ends_at END) AS last_end,
                MIN(CASE WHEN starts_at > ? THEN starts_at END) AS next_start,
                MIN(CASE WHEN ends_at > ? THEN ends_at END) AS next_end', [$now, $now, $now, $now])
            ->first();
        $next = array_filter([$transitions?->next_start, $transitions?->next_end]);
        sort($next);

        return [
            'version' => hash('sha256', ($transitions?->last_start ?? '').'|'.($transitions?->last_end ?? '')),
            'next_transition' => $next === [] ? null : Carbon::parse($next[0]),
        ];
    }

    /** Cache entries must expire at a campaign boundary, even without a database write. */
    public function expiresAt(int $ttlSeconds, ?string $placement = null, ?array $context = null): Carbon
    {
        $expiry = now()->addSeconds(max(1, $ttlSeconds));
        $next = ($context ?? $this->context($placement))['next_transition'];

        return $next && $next->lt($expiry) ? $next : $expiry;
    }
}
