<?php

namespace App\Services\Content;

use App\Models\Content\ContentBlockSlot;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

class ContentBlockResolver
{
    public function forPlacement(
        string $placement,
        ?string $locale = null,
        ?string $targetType = null,
        ?string $targetRef = null,
        ?string $frontendVariant = null,
        bool $strictVariant = false
    ): Collection {
        if (! Schema::hasTable('content_block_slots') || ! Schema::hasTable('content_blocks')) {
            return collect();
        }

        $locale = $locale ?: app()->getLocale();
        $targetType = $targetType !== '' ? $targetType : null;
        $targetRef = $targetRef !== '' ? $targetRef : null;
        $frontendVariant = in_array($frontendVariant, ['desktop', 'mobile'], true) ? $frontendVariant : null;
        $version = (int) Cache::get($this->versionKey(), 1);

        $cacheKey = sprintf(
            'content_blocks:v%s:%s:%s:%s:%s:%s:%s',
            $version,
            $placement,
            $locale,
            $targetType ?: 'global',
            $targetRef ?: 'global',
            $frontendVariant ?: 'all',
            $strictVariant ? 'strict' : 'fallback'
        );

        return Cache::remember(
            $cacheKey,
            (int) config('content_blocks.cache.ttl_seconds', 3600),
            function () use ($placement, $locale, $targetType, $targetRef, $frontendVariant, $strictVariant): Collection {
                $baseQuery = ContentBlockSlot::query()
                    ->with([
                        'block',
                        'block.items',
                        'block.media',
                        'block.translations' => fn ($q) => $q->whereIn('locale', [$locale, config('app.locale')]),
                    ])
                    ->whereHas('block', fn ($query) => $query->where('is_active', true))
                    ->where('placement', $placement)
                    ->currentlyActive()
                    ->when($targetType !== null, function ($query) use ($targetType, $targetRef): void {
                        $query->where(function ($q) use ($targetType, $targetRef): void {
                            $q->whereNull('target_type')
                                ->orWhere(function ($specific) use ($targetType, $targetRef): void {
                                    $specific->where('target_type', $targetType)
                                        ->where(function ($refQuery) use ($targetRef): void {
                                            $refQuery->whereNull('target_ref');

                                            if ($targetRef !== null && $targetRef !== '') {
                                                $refQuery->orWhere('target_ref', $targetRef);
                                            }
                                        });
                                });
                        });
                    }, function ($query): void {
                        $query->whereNull('target_type');
                    });

                if ($frontendVariant !== null) {
                    $specificSlots = (clone $baseQuery)
                        ->where('frontend_variant', $frontendVariant)
                        ->orderBy('sort_order')
                        ->orderBy('id')
                        ->get();

                    if ($specificSlots->isNotEmpty() || $strictVariant) {
                        $slots = $specificSlots;
                    } else {
                        $slots = (clone $baseQuery)
                            ->where(function ($q): void {
                                $q->whereNull('frontend_variant')
                                    ->orWhere('frontend_variant', 'all');
                            })
                            ->orderBy('sort_order')
                            ->orderBy('id')
                            ->get();
                    }
                } else {
                    $slots = (clone $baseQuery)
                        ->where(function ($q): void {
                            $q->whereNull('frontend_variant')
                                ->orWhere('frontend_variant', 'all');
                        })
                        ->orderBy('sort_order')
                        ->orderBy('id')
                        ->get();
                }

                return $slots->map(function (ContentBlockSlot $slot) use ($locale) {
                    $block = $slot->block;
                    $translation = $block->translations->firstWhere('locale', $locale)
                        ?? $block->translations->firstWhere('locale', config('app.locale'))
                        ?? null;

                    return [
                        'slot' => $slot,
                        'block' => $block,
                        'translation' => $translation,
                    ];
                });
            }
        );
    }

    public static function bumpCacheVersion(): void
    {
        $key = config('content_blocks.cache.version_key', 'content_blocks:version');
        $current = (int) Cache::get($key, 1);
        Cache::forever($key, $current + 1);
    }

    private function versionKey(): string
    {
        return config('content_blocks.cache.version_key', 'content_blocks:version');
    }
}
