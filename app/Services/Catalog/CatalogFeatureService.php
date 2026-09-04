<?php

namespace App\Services\Catalog;

use App\Services\Settings\SystemSettingsService;

class CatalogFeatureService
{
    public function __construct(
        private readonly SystemSettingsService $settings
    ) {
    }

    /**
     * @return array<string, bool>
     */
    public function all(): array
    {
        /** @var array<string, bool> $defaults */
        $defaults = config('catalog_features.flags', []);
        $flags = [];

        try {
            foreach ($defaults as $key => $default) {
                $raw = $this->settings->get($key, $default);
                $flags[$key] = filter_var($raw, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? (bool) $default;
            }
        } catch (\Throwable) {
            // Gracefully fallback when settings storage is unavailable (e.g. isolated test DB).
            foreach ($defaults as $key => $default) {
                $flags[$key] = (bool) $default;
            }
        }

        return $flags;
    }

    public function enabled(string $flag): bool
    {
        $all = $this->all();

        return (bool) ($all[$flag] ?? false);
    }

    public function useAttributes(): bool
    {
        return $this->enabled('catalog_use_attributes');
    }

    public function useBlog(): bool
    {
        return $this->enabled('catalog_use_blog');
    }

    public function useApi(): bool
    {
        return $this->enabled('catalog_use_api');
    }

    public function useOptions(): bool
    {
        return $this->enabled('catalog_use_options');
    }

    public function useManufacturers(): bool
    {
        return $this->enabled('catalog_use_manufacturers');
    }

    public function useActions(): bool
    {
        return $this->enabled('catalog_use_actions');
    }

    public function hideOutOfStockProducts(): bool
    {
        return $this->enabled('catalog_hide_out_of_stock_products');
    }
}
