<?php

namespace App\Services\Front;

use App\Support\AssetVersion;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Throwable;

/** Internal HTML storage only. HTTP/session cookies and headers are never stored. */
class GuestStorefrontCache
{
    private const PUBLIC_TABLES = [
        'products', 'product_translations', 'category_product', 'categories', 'category_translations',
        'catalog_manufacturers', 'catalog_manufacturer_translations', 'catalog_product_packages',
        'catalog_product_specifications', 'catalog_product_variants', 'catalog_product_variant_option_values',
        'catalog_product_option_values', 'catalog_options', 'catalog_option_translations',
        'catalog_option_values', 'catalog_option_value_translations', 'catalog_option_product',
        'catalog_attributes', 'catalog_attribute_translations', 'catalog_attribute_product',
        'catalog_attribute_groups', 'catalog_attribute_group_translations', 'catalog_actions',
        'catalog_action_translations', 'catalog_action_targets', 'content_blocks', 'content_block_items',
        'content_block_slots', 'content_block_translations', 'content_blog_posts',
        'content_blog_post_translations', 'content_blog_post_category', 'content_info_pages',
        'content_info_page_translations', 'content_info_page_category', 'content_faqs',
        'content_faq_translations', 'content_comments', 'system_settings', 'languages', 'currencies',
        'features', 'media', 'tax_rates', 'product_energy_declarations',
    ];

    public function enabled(): bool
    {
        return (bool) config('storefront_cache.enabled', false)
            && (bool) config('commerce.b2b_only', true)
            && (int) config('storefront_cache.ttl_seconds', 120) > 0;
    }

    public function revision(): string
    {
        return (string) $this->store()->rememberForever($this->revisionKey(), static fn (): string => bin2hex(random_bytes(16)));
    }

    public function invalidate(): void
    {
        try {
            // Random revisions do not lose concurrent invalidations to read/modify/write races.
            $this->store()->forever($this->revisionKey(), bin2hex(random_bytes(16)));
        } catch (Throwable) {
            // A broken cache must not prevent an admin save or a stock import.
        }
    }

    public function onQueryExecuted(QueryExecuted $query): void
    {
        if (! $this->enabled()) {
            return;
        }

        $sql = preg_replace('/^\s*(?:\/\*.*?\*\/\s*)+/s', '', $query->sql) ?? $query->sql;
        if (! preg_match('/^\s*(?:insert(?:\s+or\s+\w+|\s+ignore)?\s+into|replace(?:\s+into)?|update(?:\s+(?:ignore|low_priority))?|delete\s+from|truncate(?:\s+table)?|alter\s+table|create\s+table(?:\s+if\s+not\s+exists)?|drop\s+table(?:\s+if\s+exists)?)\s+(?:[`"]?[a-zA-Z0-9_]+[`"]?\.)?[`"]?([a-zA-Z0-9_]+)/i', $sql, $match)) {
            return;
        }

        $table = strtolower($match[1]);
        $prefix = strtolower($query->connection->getTablePrefix());
        if ($prefix !== '' && str_starts_with($table, $prefix)) {
            $table = substr($table, strlen($prefix));
        }
        if (! in_array($table, self::PUBLIC_TABLES, true)) {
            return;
        }

        try {
            if ($query->connection->transactionLevel() > 0) {
                $query->connection->afterCommit(fn () => $this->invalidate());
            } else {
                $this->invalidate();
            }
        } catch (Throwable) {
            // Preserve the successful database operation if cache callbacks are unavailable.
        }
    }

    /** @param array<string, string> $query */
    public function key(Request $request, array $query, string $gridCookie): string
    {
        ksort($query);
        $context = [
            'schema' => 1,
            'host' => $request->getSchemeAndHttpHost(),
            'app_url' => (string) config('app.url'),
            'route' => $request->route()?->getName(),
            'path' => $request->getPathInfo(),
            'query' => $query,
            'locale' => (string) $request->attributes->get('front_locale', app()->getLocale()),
            'fallback' => (string) config('app.fallback_locale'),
            'variant' => (string) $request->attributes->get('frontend_variant', 'desktop'),
            'mobile' => app(DeviceViewResolver::class)->variant($request->userAgent()) === 'mobile',
            'grid_cookie' => $gridCookie,
            'b2b_only' => true,
            'content_version' => Cache::get(config('content_blocks.cache.version_key', 'content_blocks:version'), 1),
            'asset_version' => app(AssetVersion::class)->current(),
            'revision' => $this->revision(),
        ];

        return $this->namespace().':html:'.hash('sha256', json_encode($context, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    /** @return array{html: string, csrf_marker: string}|null */
    public function get(string $key): ?array
    {
        $entry = $this->store()->get($key);

        return is_array($entry) && is_string($entry['html'] ?? null)
            && is_string($entry['csrf_marker'] ?? null)
            && preg_match('/^__HERRERA_GUEST_CSRF_[a-f0-9]{48}__$/D', $entry['csrf_marker']) === 1
            ? ['html' => $entry['html'], 'csrf_marker' => $entry['csrf_marker']]
            : null;
    }

    public function put(string $key, string $html, string $csrfToken): void
    {
        $marker = '__HERRERA_GUEST_CSRF_'.bin2hex(random_bytes(24)).'__';
        $this->store()->put($key, [
            'html' => str_replace($csrfToken, $marker, $html),
            'csrf_marker' => $marker,
        ], min(3600, max(1, (int) config('storefront_cache.ttl_seconds', 120))));
    }

    private function store(): Repository
    {
        return Cache::store((string) config('storefront_cache.store', 'file'));
    }

    private function namespace(): string
    {
        return 'front:guest:'.hash('sha256', base_path().'|'.config('app.env').'|'.config('app.url'));
    }

    private function revisionKey(): string
    {
        return $this->namespace().':revision';
    }
}
