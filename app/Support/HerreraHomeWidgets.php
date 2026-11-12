<?php

namespace App\Support;

use App\Models\Content\ContentBlock;
use App\Models\Content\ContentBlockTranslation;
use App\Services\Settings\SystemSettingsService;

class HerreraHomeWidgets
{
    public const FIXED_CODES = [
        'herrera-home-hero',
        'herrera-home-category-catalog',
        'herrera-home-featured-products',
        'herrera-home-brands',
        'herrera-home-popular-products',
        'herrera-home-registration',
        'herrera-home-blog',
    ];

    public static function isHerreraStore(): bool
    {
        return str_contains(strtolower((string) app(SystemSettingsService::class)
            ->get('store_brand_name', config('app.name'))), 'herrera');
    }

    public static function usesDatabaseEditor(string $type, string $code, string $placement): bool
    {
        return in_array($code, self::FIXED_CODES, true)
            || str_starts_with($type, 'herrera_')
            || (str_starts_with($placement, 'home.')
                && array_key_exists($type, config('herrera_home_widgets.fields'))
                && self::isHerreraStore());
    }

    public static function payload(?ContentBlock $block, ?ContentBlockTranslation $translation): array
    {
        return array_replace_recursive((array) $block?->payload, (array) $translation?->payload);
    }

    public static function safeUrl(mixed $value, string $fallback = ''): string
    {
        $url = is_string($value) ? trim($value) : '';
        if ($url === '' || preg_match('~[\x00-\x20\x7f\\\\]~', $url)) {
            return $fallback;
        }
        if ((str_starts_with($url, '/') && ! str_starts_with($url, '//')) || str_starts_with($url, '#')) {
            return $url;
        }

        return preg_match('~^https?://~i', $url) && filter_var($url, FILTER_VALIDATE_URL) ? $url : $fallback;
    }
}
